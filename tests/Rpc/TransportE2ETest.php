<?php
declare(strict_types=1);
namespace Greenwood\Tests\Rpc;

use Greenwood\Persistence\AtomicFile;
use Greenwood\Rpc\RpcClient;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end over real sockets: a forked greenwoodd-equivalent node process,
 * real RpcClient, and the real bin/greenwood binary. No handler shortcuts.
 */
final class TransportE2ETest extends TestCase
{
    private string $dir;
    private int $pid = 0;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) self::markTestSkipped('pcntl required');
        $this->dir = sys_get_temp_dir() . '/gw-e2e-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->pid > 0) { posix_kill($this->pid, SIGKILL); pcntl_waitpid($this->pid, $s); }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        rmdir($this->dir);
    }

    private function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($s, false);
        fclose($s);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function bootNode(): array
    {
        $port = $this->freePort();
        $socket = $this->dir . '/operator.sock';
        $tokenFile = $this->dir . '/operator.token';
        AtomicFile::write($tokenFile, 'e2e-operator-token-0123456789');
        chmod($tokenFile, 0600);
        $readyFile = $this->dir . '/ready.flag';
        $pid = pcntl_fork();
        if ($pid === 0) {
            // Child: full node with public + operator listeners.
            try {
                $g = new \Greenwood\Genesis\Genesis('rpc-test');
                foreach (['B', 'C', 'D'] as $id) $g->addValidator($id, \Greenwood\Crypto\Ed25519KeyPair::generate(), 1);
                $g->addValidator('A', \Greenwood\Tests\Fixtures\ComputeFixture::key(), 1);
                $g->addInitialAccount('provider', \Greenwood\Tests\Fixtures\ComputeFixture::key(), 10000);
                $chain = new \Greenwood\Node\Chain($g, new \Greenwood\Persistence\BlockFilesystem($this->dir . '/blocks'), new \Greenwood\Persistence\StateSqlite($this->dir . '/state.sqlite'));
                $driver = new \Greenwood\Consensus\Runtime\ConsensusDriver($chain, 'E', [], null, 'http://127.0.0.1:' . $port);
                $operator = new \Greenwood\Rpc\OperatorApi(
                    \Greenwood\Rpc\OperatorAuth::fromFile($tokenFile),
                    validatorStatus: fn (): array => $driver->operatorStatus(),
                    validatorConsensus: fn (): array => $driver->operatorConsensus(),
                );
                $driver->operatorTransport = new \Greenwood\Consensus\Network\Transport('unix://' . $socket,
                    fn ($m, $p, $b, $h = []) => $operator->handle($m, $p, $b, $h));
                touch($readyFile);
                while (true) { $driver->tick(); usleep(1000); }
            } catch (\Throwable) {
                exit(1);
            }
        }
        $this->pid = $pid;
        for ($i = 0; $i < 500 && !file_exists($readyFile); $i++) usleep(10000);
        if (!file_exists($readyFile)) throw new \RuntimeException('node did not start');
        usleep(50000);
        return ['http://127.0.0.1:' . $port, 'unix://' . $socket, $tokenFile];
    }

    public function testPublicApiOverRealTransport(): void
    {
        [$endpoint, $socket, $tokenFile] = $this->bootNode();
        $client = new RpcClient($endpoint);

        $r = $client->json('GET', '/v1/chain/status');
        self::assertSame(200, $r['status']);
        self::assertSame('rpc-test', $r['data']['chain_id']);
        self::assertSame('v1', $r['data']['api_version']);

        // Absence must survive the real HTTP transport as a typed response.
        $r = $client->json('GET', '/v1/transactions/' . str_repeat('0', 64));
        self::assertSame(404, $r['status']);
        self::assertSame('TRANSACTION_NOT_FOUND', $r['data']['error']['code']);
        $r = $client->json('GET', '/v1/transactions/' . str_repeat('0', 64) . '/status');
        self::assertSame(200, $r['status']);
        self::assertSame('UNKNOWN', $r['data']['status']);

        $r = $client->json('GET', '/ready');
        self::assertSame(200, $r['status']);
        self::assertTrue($r['data']['ready']);

        $metrics = $client->request('GET', '/metrics');
        self::assertStringStartsWith('text/plain; version=0.0.4', $metrics['content_type']);
        self::assertStringContainsString('greenwood_finalized_height 0', $metrics['body']);

        // Legacy routes still work on the same listener.
        $r = $client->json('GET', '/health');
        self::assertSame(['ok' => true], $r['data']);
        $r = $client->json('GET', '/status');
        self::assertSame('rpc-test', $r['data']['chain_id']);

        // Public listener never reaches operator routes.
        $r = $client->json('GET', '/v1/operator/validator/status');
        self::assertSame(404, $r['status']);

        // Operator socket: auth boundary over UDS.
        $op = new RpcClient($socket);
        $r = $op->json('GET', '/v1/operator/validator/status');
        self::assertSame(401, $r['status']);
        self::assertSame('UNAUTHENTICATED', $r['data']['error']['code']);
        $token = rtrim((string) file_get_contents($tokenFile), "\r\n");
        $r = $op->json('GET', '/v1/operator/validator/status', null, $token);
        self::assertSame(200, $r['status']);
        self::assertSame('full_node', $r['data']['role']);
        $r = $op->json('GET', '/v1/operator/validator/status', null, 'wrong-token-000000000');
        self::assertSame(401, $r['status']);
    }

    public function testBodylessGetAndStrictFraming(): void
    {
        [$endpoint] = $this->bootNode();
        $address = str_replace('http://', 'tcp://', $endpoint);
        foreach (['', "Content-Length: 0\r\n"] as $framing) {
            $socket = stream_socket_client($address);
            stream_set_timeout($socket, 3);
            fwrite($socket, "GET /v1/chain/status HTTP/1.1\r\nHost: localhost\r\n".$framing."Connection: close\r\n\r\n");
            self::assertStringStartsWith('HTTP/1.1 200 ', stream_get_contents($socket));
            fclose($socket);
            $socket = stream_socket_client($address);
            stream_set_timeout($socket, 3);
            fwrite($socket, "GET /v1/chain/status HTTP/1.1\r\nHost: localhost\r\n".$framing."Connection: close\r\n\r\nunframed");
            self::assertSame('', stream_get_contents($socket));
            fclose($socket);
        }
    }

    public function testTransactionAndCliOverRealTransport(): void
    {
        [$endpoint] = $this->bootNode();
        // Submit a real signed transfer through the CLI against the public RPC.
        $key = \Greenwood\Tests\Fixtures\ComputeFixture::key();
        $recipient = \Greenwood\Crypto\ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', \Greenwood\Crypto\Ed25519KeyPair::generate()->getPublicKey());
        $tx = new \Greenwood\Transaction\Envelope(1, 'rpc-test',
            \Greenwood\Tests\Fixtures\ComputeFixture::requester(), 0, 1,
            (new \Greenwood\Transaction\Transfer($recipient, 25))->serialize(), '', $key->getPublicKey());
        $tx->setSignature($key->sign($tx->getSigningDigest()));
        $file = $this->dir . '/tx.json';
        file_put_contents($file, json_encode(['type' => 'transaction', 'payload' => base64_encode($tx->getCanonicalBytes())]));

        $bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/greenwood');
        $hash = bin2hex($tx->getHash());
        $out = [];
        exec($bin . ' --json --rpc=' . escapeshellarg($endpoint) . ' tx submit ' . escapeshellarg($file), $out, $code);
        self::assertSame(0, $code);
        self::assertSame($hash, json_decode($out[0], true)['tx_hash']);

        $out = [];
        exec($bin . ' --json --rpc=' . escapeshellarg($endpoint) . ' tx status ' . $hash, $out, $code);
        self::assertSame(0, $code);
        self::assertSame('PENDING', json_decode($out[0], true)['status']);

        $out = [];
        exec($bin . ' --json --rpc=' . escapeshellarg($endpoint) . ' account balance ' . bin2hex($recipient), $out, $code);
        self::assertSame(4, $code); // account does not exist yet

        $out = [];
        exec($bin . ' --json --rpc=' . escapeshellarg($endpoint) . ' chain status', $out, $code);
        self::assertSame(0, $code);
        self::assertSame('rpc-test', json_decode($out[0], true)['chain_id']);

        // CLI never touches the database: there is no sqlite read path in the client.
        $out = [];
        exec($bin . ' tx get ' . $hash, $out, $code);
        self::assertSame(1, $code); // no endpoint configured anywhere -> connection failure, exit 1
    }
}

