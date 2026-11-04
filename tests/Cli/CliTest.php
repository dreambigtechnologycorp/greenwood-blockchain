<?php
declare(strict_types=1);
namespace Greenwood\Tests\Cli;

use Greenwood\Cli\GreenwoodCli;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    public array $requests = [];
    public array $queue = [];
    private string $out = '';
    private string $err = '';

    private function runCli(array $argv): int
    {
        $this->out = ''; $this->err = ''; $this->requests = [];
        return GreenwoodCli::run(
            $argv,
            function (string $endpoint) {
                return new class($endpoint, $this) {
                    public function __construct(public string $endpoint, private CliTest $test) {}
                    public function json(string $method, string $path, ?string $body = null, ?string $token = null): array
                    {
                        $this->test->requests[] = ['endpoint' => $this->endpoint, 'method' => $method, 'path' => $path, 'body' => $body, 'token' => $token];
                        return array_shift($this->test->queue) ?? ['status' => 200, 'data' => ['ok' => true]];
                    }
                };
            },
            function (string $s) { $this->out .= $s; },
            function (string $s) { $this->err .= $s; },
        );
    }

    private function tokenFile(): string
    {
        $path = sys_get_temp_dir() . '/gw-cli-token-' . bin2hex(random_bytes(4));
        file_put_contents($path, 'cli-test-token-0123456789');
        chmod($path, 0600);
        return $path;
    }

    public function testChainStatusJsonMode(): void
    {
        $this->queue[] = ['status' => 200, 'data' => ['chain_id' => 'rpc-test', 'height' => 7]];
        $code = $this->runCli(['greenwood', '--json', 'chain', 'status']);
        self::assertSame(0, $code);
        self::assertSame(['GET', '/v1/chain/status'], [$this->requests[0]['method'], $this->requests[0]['path']]);
        self::assertSame(['chain_id' => 'rpc-test', 'height' => 7], json_decode($this->out, true));
        self::assertSame('', $this->err);
    }

    public function testHumanMode(): void
    {
        $this->queue[] = ['status' => 200, 'data' => ['chain_id' => 'rpc-test', 'syncing' => false]];
        $code = $this->runCli(['greenwood', 'chain', 'status']);
        self::assertSame(0, $code);
        self::assertStringContainsString('chain_id: rpc-test', $this->out);
        self::assertStringContainsString('syncing: false', $this->out);
    }

    public function testInvalidLocalInput(): void
    {
        self::assertSame(2, $this->runCli(['greenwood', 'account', 'get', 'not-hex']));
        self::assertSame(2, $this->runCli(['greenwood', 'bogus', 'x']));
        self::assertSame(2, $this->runCli(['greenwood', 'chain', 'bogus']));
        self::assertSame(2, $this->runCli(['greenwood', 'chain']));
        self::assertSame(2, $this->runCli(['greenwood', 'chain', 'status', '--unknown-flag']));
        self::assertStringContainsString('error:', $this->err);
        self::assertSame([], $this->requests); // usage failures never hit the network
    }

    public function testErrorExitCodeMapping(): void
    {
        foreach ([400 => 2, 401 => 3, 403 => 3, 404 => 4, 409 => 5, 422 => 5, 503 => 5, 500 => 5] as $http => $exit) {
            $this->queue[] = ['status' => $http, 'data' => ['error' => ['code' => 'X', 'message' => 'm', 'details' => []]]];
            self::assertSame($exit, $this->runCli(['greenwood', 'chain', 'status']), 'http ' . $http);
        }
        $this->queue[] = ['status' => 404, 'data' => ['error' => ['code' => 'BLOCK_NOT_FOUND', 'message' => 'no', 'details' => ['height' => 9]]]];
        $this->runCli(['greenwood', '--json', 'block', 'get', '9']);
        self::assertSame('BLOCK_NOT_FOUND', json_decode($this->out, true)['error']['code']);
    }


    public function testTxSubmitFromFile(): void
    {
        $file = sys_get_temp_dir() . '/gw-cli-tx-' . bin2hex(random_bytes(4));
        $payload = base64_encode(str_repeat('x', 100));
        file_put_contents($file, json_encode(['type' => 'transaction', 'payload' => $payload]));
        $this->queue[] = ['status' => 200, 'data' => ['accepted' => true, 'tx_hash' => str_repeat('a', 64), 'status' => 'PENDING']];
        $code = $this->runCli(['greenwood', 'tx', 'submit', $file]);
        self::assertSame(0, $code);
        self::assertSame(['POST', '/v1/transactions'], [$this->requests[0]['method'], $this->requests[0]['path']]);
        self::assertSame($payload, json_decode($this->requests[0]['body'], true)['payload']);
        unlink($file);
        $file .= '2';
        file_put_contents($file, '{"type":"other"}');
        self::assertSame(2, $this->runCli(['greenwood', 'tx', 'submit', $file]));
        self::assertCount(0, $this->requests);
        unlink($file);
    }

    public function testProviderCommandsUseOperatorEndpointAndToken(): void
    {
        $tokenFile = $this->tokenFile();
        $this->queue[] = ['status' => 200, 'data' => ['tx_hash' => str_repeat('b', 64), 'requested_status' => 'DRAINING', 'finalized' => false]];
        $code = $this->runCli(['greenwood', '--json', '--operator=unix:///tmp/op.sock', '--token-file=' . $tokenFile, 'provider', 'drain']);
        self::assertSame(0, $code);
        $r = $this->requests[0];
        self::assertSame('unix:///tmp/op.sock', $r['endpoint']);
        self::assertSame(['POST', '/v1/operator/provider/drain'], [$r['method'], $r['path']]);
        self::assertSame('cli-test-token-0123456789', $r['token']);
        self::assertStringNotContainsString('cli-test-token', $this->out . $this->err);
        unlink($tokenFile);
        self::assertSame(2, $this->runCli(['greenwood', 'provider', 'drain']));
    }

    public function testProviderCapabilityBody(): void
    {
        $tokenFile = $this->tokenFile();
        $this->queue[] = ['status' => 200, 'data' => ['ok' => true]];
        $code = $this->runCli(['greenwood', '--token-file=' . $tokenFile, 'provider', 'capability',
            '--status=draining', '--cpu=8000', '--memory=4294967296', '--arch=linux/arm64/v8,linux/amd64']);
        self::assertSame(0, $code);
        $body = json_decode($this->requests[0]['body'], true);
        self::assertSame([
            'status' => 'DRAINING',
            'architectures' => ['linux/arm64/v8', 'linux/amd64'],
            'cpu_capacity_millis' => 8000,
            'memory_capacity_bytes' => 4294967296,
        ], $body);
        unlink($tokenFile);
        self::assertSame(2, $this->runCli(['greenwood', '--token-file=' . $this->tokenFile(), 'provider', 'capability', '--status=BOGUS']));
    }

    public function testConfigFile(): void
    {
        $config = sys_get_temp_dir() . '/gw-cli-config-' . bin2hex(random_bytes(4));
        file_put_contents($config, json_encode(['rpc_endpoint' => 'http://127.0.0.1:9999', 'output' => 'json']));
        $this->queue[] = ['status' => 200, 'data' => ['ok' => true]];
        $code = $this->runCli(['greenwood', '--config=' . $config, 'chain', 'finality']);
        self::assertSame(0, $code);
        self::assertSame('http://127.0.0.1:9999', $this->requests[0]['endpoint']);
        self::assertSame(['ok' => true], json_decode($this->out, true));
        unlink($config);
    }
}
