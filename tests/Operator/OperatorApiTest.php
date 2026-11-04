<?php
declare(strict_types=1);
namespace Greenwood\Tests\Operator;

use Greenwood\Compute\Protocol\{ComputeAssign, ProviderCapability};
use Greenwood\Consensus\Network\Transport;
use Greenwood\Consensus\Runtime\ConsensusDriver;
use Greenwood\Crypto\{Ed25519KeyPair, ProtocolHash};
use Greenwood\Genesis\Genesis;
use Greenwood\Node\Chain;
use Greenwood\Persistence\{AtomicFile, BlockFilesystem, StateSqlite};
use Greenwood\Provider\{ChainClient, ProviderConfig, ProviderIdentity, ProviderJobRegistry, ProviderOperator};
use Greenwood\Rpc\{HttpApi, OperatorApi, OperatorAuth};
use Greenwood\Tests\Fixtures\ComputeFixture as F;
use Greenwood\Transaction\Envelope;
use PHPUnit\Framework\TestCase;

final class OperatorApiTest extends TestCase
{
    private string $dir;
    private Chain $chain;
    private ConsensusDriver $driver;
    private OperatorApi $api;
    private ProviderOperator $operator;
    private ProviderJobRegistry $registry;
    private string $token = 'test-operator-token-0123456789';
    private array $headers;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gw-op-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $g = new Genesis('rpc-test');
        foreach (['B', 'C', 'D'] as $id) $g->addValidator($id, Ed25519KeyPair::generate(), 1);
        $g->addValidator('A', F::key(), 1);
        $g->addInitialAccount('provider', F::key(), 10000);
        $this->chain = new Chain($g, new BlockFilesystem($this->dir . '/blocks'), new StateSqlite($this->dir . '/state.sqlite'));
        $this->driver = new ConsensusDriver($this->chain, 'E', [], null, 'http://127.0.0.1:0');

        $tokenFile = $this->dir . '/operator.token';
        AtomicFile::write($tokenFile, $this->token);
        chmod($tokenFile, 0600);
        $this->headers = ['authorization' => 'Bearer ' . $this->token];

        $keyFile = $this->dir . '/provider.key';
        AtomicFile::write($keyFile, F::key()->toBytes());
        chmod($keyFile, 0600);
        $identity = ProviderIdentity::fromFile($keyFile);
        $config = new ProviderConfig('rpc-test', 'http://127.0.0.1:1', $keyFile, $this->dir . '/provider');
        mkdir($this->dir . '/provider', 0700, true);
        $this->registry = new ProviderJobRegistry($this->dir . '/provider', 'rpc-test', $identity->providerId);
        $this->operator = new ProviderOperator($config, $identity, $this->registry, $this->chainClientStub());
        $this->api = new OperatorApi(
            OperatorAuth::fromFile($tokenFile),
            providerStatus: fn (): array => $this->operator->status(),
            providerJobs: fn (): array => $this->operator->jobs(),
            providerRuntime: fn (): array => $this->operator->runtime(),
            providerDrain: fn (): array => $this->operator->drain(),
            providerResume: fn (): array => $this->operator->resume(),
            providerCapability: fn (array $in): array => $this->operator->capability($in),
            validatorStatus: fn (): array => $this->driver->operatorStatus(),
            validatorConsensus: fn (): array => $this->driver->operatorConsensus(),
        );
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        rmdir($this->dir);
    }

    /** In-process ChainClient over the real Chain + real admission path (same semantics as HTTP). */
    private function chainClientStub(): ChainClient
    {
        $chain = $this->chain;
        $driver = $this->driver;
        return new class($chain, $driver) implements ChainClient {
            public function __construct(private Chain $chain, private ConsensusDriver $driver) {}
            public function assignments(string $providerId, ?string $after = null): array { throw new \LogicException('unused'); }
            public function request(string $requestId): array { throw new \LogicException('unused'); }
            public function nonce(string $accountId): int
            {
                return $this->chain->state->hasAccount($accountId) ? $this->chain->state->getAccount($accountId)->getNonce() : 0;
            }
            public function capability(string $providerId): ?array
            {
                $cap = $this->chain->state->getComputeState()->capabilities()[$providerId] ?? null;
                if ($cap === null) return null;
                return [
                    'provider_id' => bin2hex($providerId),
                    'status' => match ($cap->status) { 0 => 'ACTIVE', 1 => 'DRAINING', default => 'SUSPENDED' },
                    'architectures' => $cap->architectures,
                    'capability_nonce' => $cap->nonce,
                    'cpu_capacity_millis' => $cap->cpuCapacityMillis,
                    'memory_capacity_bytes' => (string) $cap->memoryCapacityBytes,
                    'pid_capacity' => $cap->pidCapacity,
                    'max_concurrent_jobs' => $cap->maxConcurrentJobs,
                ];
            }
            public function submit(Envelope $transaction): string
            {
                try {
                    return $this->driver->admitTransaction(Transport::envelope('transaction', $transaction->getCanonicalBytes()));
                } catch (\InvalidArgumentException | \JsonException $e) {
                    throw new \RuntimeException('admission rejected', 0, $e);
                }
            }
        };
    }

    private function tx(int $type, string $payload, int $nonce): Envelope
    {
        $key = F::key();
        $tx = new Envelope(1, 'rpc-test', ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $key->getPublicKey()), $nonce, $type, $payload, '', $key->getPublicKey());
        $tx->setSignature($key->sign($tx->getSigningDigest()));
        return $tx;
    }

    private function finalize(array $txs): void
    {
        $validators = $this->chain->genesis->getValidators(); ksort($validators, SORT_STRING);
        $ids = array_keys($validators);
        $height = $this->chain->tip->getHeight() + 1;
        $proposer = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $validators[$ids[$height % 4]]['publicKey']);
        $this->chain->finalize($this->chain->build($txs, 0, $proposer));
    }

    /** Finalize exactly the current mempool contents, then clear the pool. */
    private function finalizePool(): void
    {
        $pool = new \ReflectionProperty(ConsensusDriver::class, 'pool');
        $txs = array_values($pool->getValue($this->driver));
        $pool->setValue($this->driver, []);
        $this->finalize($txs);
    }

    /** Seed one ACTIVE capability at height 1. */
    private function seedCapability(int $cpu = 4000): void
    {
        $cap = new ProviderCapability(F::providerId()->toBytes(), F::key()->getPublicKey(), 0, 1, ['linux/arm64/v8'], $cpu, 1 << 30, 128, 4);
        $this->finalize([$this->tx(10, $cap->toBytes(), 0)]);
    }

    private function post(string $path, string $body = '', ?array $headers = null): array
    {
        $r = $this->api->handle('POST', $path, $body, $headers ?? $this->headers);
        return [$r->status, json_decode($r->body, true, 32, JSON_THROW_ON_ERROR)];
    }

    private function get(string $path, ?array $headers = null): array
    {
        $r = $this->api->handle('GET', $path, '', $headers ?? $this->headers);
        return [$r->status, json_decode($r->body, true, 32, JSON_THROW_ON_ERROR)];
    }


    public function testAuthBoundaries(): void
    {
        // Missing credentials.
        [$status, $err] = $this->get('/v1/operator/provider/status', []);
        self::assertSame(401, $status);
        self::assertSame('UNAUTHENTICATED', $err['error']['code']);
        // Wrong token.
        [$status] = $this->get('/v1/operator/provider/status', ['authorization' => 'Bearer wrong-token-00000000']);
        self::assertSame(401, $status);
        // Malformed scheme.
        [$status] = $this->get('/v1/operator/provider/status', ['authorization' => 'Basic abcdefghijklmnop']);
        self::assertSame(401, $status);
        // Token value never leaks into error responses.
        [$status, $err] = $this->get('/v1/operator/provider/status', ['authorization' => 'Bearer wrong-token-00000000']);
        self::assertStringNotContainsString('wrong-token', json_encode($err));
        // Valid token.
        [$status] = $this->get('/v1/operator/validator/status');
        self::assertSame(200, $status);
    }

    public function testRouteIsolation(): void
    {
        // Public route table never includes operator routes.
        foreach (HttpApi::ROUTES as $route => $class) self::assertStringNotContainsString('/operator/', $route);
        foreach (OperatorApi::ROUTES as $route => $class) self::assertSame('OPERATOR_ONLY', $class);
        // Operator API refuses non-operator paths even with a token.
        [$status] = $this->get('/v1/chain/status');
        self::assertSame(404, $status);
        // Route groups not offered by a process return 404.
        $bare = new OperatorApi(OperatorAuth::fromFile($this->dir . '/operator.token'));
        $r = $bare->handle('GET', '/v1/operator/provider/status', '', $this->headers);
        self::assertSame(404, $r->status);
    }

    public function testValidatorOperatorReads(): void
    {
        $this->seedCapability();
        [$status, $body] = $this->get('/v1/operator/validator/status');
        self::assertSame(200, $status);
        self::assertSame('full_node', $body['role']);
        self::assertSame(1, $body['finalized_height']);
        self::assertSame(0, $body['peer_count']);
        self::assertArrayNotHasKey('signing_key', $body);
        [, $consensus] = $this->get('/v1/operator/validator/consensus');
        self::assertSame(1, $consensus['height']); // driver candidate height, set at construction
        self::assertSame(0, $consensus['mempool_size']);
    }

    public function testDrainResumeAreCanonicalTransactions(): void
    {
        $this->seedCapability();
        [$status, $body] = $this->post('/v1/operator/provider/drain');
        self::assertSame(200, $status);
        self::assertSame('DRAINING', $body['requested_status']);
        self::assertSame(F::PROVIDER_ID, $body['provider_id']);
        self::assertSame(2, $body['capability_nonce']);
        self::assertFalse($body['finalized']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['tx_hash']);
        // Not yet finalized: chain state still ACTIVE.
        $cap = $this->chain->state->getComputeState()->capabilities()[F::providerId()->toBytes()];
        self::assertSame(0, $cap->status);
        // Finalize the drain transaction from the mempool via the canonical block path.
        $this->finalizePool();
        $cap = $this->chain->state->getComputeState()->capabilities()[F::providerId()->toBytes()];
        self::assertSame(1, $cap->status); // DRAINING finalized
        self::assertSame(2, $cap->nonce);

        [$status, $body] = $this->post('/v1/operator/provider/resume');
        self::assertSame(200, $status);
        self::assertSame('ACTIVE', $body['requested_status']);
        self::assertSame(3, $body['capability_nonce']);
        $this->finalizePool();
        self::assertSame(0, $this->chain->state->getComputeState()->capabilities()[F::providerId()->toBytes()]->status);
    }

    public function testCapabilityUpdateAndReservationGuard(): void
    {
        $this->seedCapability();
        // Increase capacity: finalizes and the scheduler-visible record changes.
        [$status, $body] = $this->post('/v1/operator/provider/capability', json_encode(['cpu_capacity_millis' => 8000]));
        self::assertSame(200, $status);
        self::assertSame(2, $body['capability_nonce']);
        $this->finalizePool();
        self::assertSame(8000, $this->chain->state->getComputeState()->capabilities()[F::providerId()->toBytes()]->cpuCapacityMillis);

        // Open (unsettled) assignment reserves 2000 cpu millis against this provider.
        $request = F::request(['requestNonce' => 2, 'deadlineHeight' => 50]);
        $this->finalize([$this->tx(7, $request->toBytes(), 2)]);
        $assign = new ComputeAssign($request->getId(), F::providerId()->toBytes(), F::key()->getPublicKey(), 1, 50);
        $this->finalize([$this->tx(8, $assign->toBytes(), 3)]);
        self::assertSame(2000, $this->chain->state->getComputeState()->reserved(F::providerId()->toBytes())['cpu']);

        // Undercutting the reservation is rejected by canonical state admission, not the HTTP layer.
        [$status, $err] = $this->post('/v1/operator/provider/capability', json_encode(['cpu_capacity_millis' => 1000]));
        self::assertSame(409, $status);
        self::assertSame('PROVIDER_CAPABILITY_REJECTED', $err['error']['code']);
        // Capacity at/above the reservation still works.
        [$status] = $this->post('/v1/operator/provider/capability', json_encode(['cpu_capacity_millis' => 2000]));
        self::assertSame(200, $status);
    }

    public function testCapabilityInputValidation(): void
    {
        $this->seedCapability();
        foreach ([
            ['{"status":"BOGUS"}', 'bad status'],
            ['{"unknown_field":1}', 'unknown field'],
            ['{"cpu_capacity_millis":0}', 'non-positive'],
            ['{"cpu_capacity_millis":"8000"}', 'wrong type'],
            ['{"architectures":[]}', 'empty arch'],
            ['not json', 'malformed JSON'],
        ] as [$body, $case]) {
            [$status, $err] = $this->post('/v1/operator/provider/capability', $body);
            self::assertSame(400, $status, $case);
            self::assertSame('INVALID_REQUEST', $err['error']['code'], $case);
        }
        [$status] = $this->post('/v1/operator/provider/capability', str_repeat('{"a":', 5000));
        self::assertSame(400, $status);
        // Drain/resume take no body.
        [$status] = $this->post('/v1/operator/provider/drain', '{"x":1}');
        self::assertSame(400, $status);
        // Unknown architecture string reaches the frozen ProviderCapability validation (400, not 500).
        [$status] = $this->post('/v1/operator/provider/capability', json_encode(['architectures' => ['linux/sparc']]));
        self::assertSame(400, $status);
    }

    public function testLocalDrainIsDistinctFromNetworkStatus(): void
    {
        $this->seedCapability();
        [$status, $body] = $this->get('/v1/operator/provider/status');
        self::assertSame(200, $status);
        self::assertSame('ACTIVE', $body['network_status']);
        self::assertFalse($body['local_drain']);
        self::assertTrue($body['effective_accepting_work']);
        self::assertSame(F::PROVIDER_ID, $body['provider_id']);

        // Local emergency drain: affects acceptance locally, never touches finalized state.
        $this->registry->setDrain(true);
        [, $body] = $this->get('/v1/operator/provider/status');
        self::assertSame('ACTIVE', $body['network_status']);
        self::assertTrue($body['local_drain']);
        self::assertFalse($body['effective_accepting_work']);
        self::assertSame(0, $this->chain->state->getComputeState()->capabilities()[F::providerId()->toBytes()]->status);
    }

    public function testRuntimeDiagnosticsAcceptStandardSbinMkfsExt4Path(): void
    {
        if (!is_executable('/usr/bin/mkfs.erofs')) {
            self::markTestSkipped('mkfs.erofs is not installed at the standard test host path');
        }

        if (!is_executable('/usr/sbin/mkfs.ext4') && !is_executable('/usr/bin/mkfs.ext4')) {
            self::markTestSkipped('mkfs.ext4 is not installed at a supported path');
        }

        [, $body] = $this->get('/v1/operator/provider/runtime');

        self::assertTrue(
            $body['snapshotter_available'],
            'EROFS tooling should be detected when mkfs.ext4 is installed under /usr/sbin'
        );
    }

    public function testRuntimeDiagnosticsExposeNoSecrets(): void
    {
        [$status, $body] = $this->get('/v1/operator/provider/runtime');
        self::assertSame(200, $status);
        foreach (['containerd_socket_access', 'configured_snapshotter', 'snapshotter_available', 'snapshotter_ready',
                  'worker_identity_ready', 'data_path_writable', 'chain_reachable', 'signing_key_isolated'] as $key) {
            self::assertArrayHasKey($key, $body);
        }
        $json = json_encode($body);
        self::assertStringNotContainsString(F::PROVIDER_PRIVATE_KEY, $json);
        self::assertStringNotContainsString('provider.key', $json);

        [$status, $jobs] = $this->get('/v1/operator/provider/jobs');
        self::assertSame(200, $status);
        self::assertSame(['jobs' => []], $jobs);
    }

    public function testDrainWithoutRegistrationConflicts(): void
    {
        [$status, $err] = $this->post('/v1/operator/provider/drain');
        self::assertSame(409, $status);
        self::assertSame('PROVIDER_CAPABILITY_NOT_FOUND', $err['error']['code']);
    }
}
