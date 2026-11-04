<?php
declare(strict_types=1);
namespace Greenwood\Tests\Rpc;

use Greenwood\Compute\Protocol\{ComputeAssign, ComputeReceiptV2, ComputeStatus, ProviderCapability, ProviderId, RequestState};
use Greenwood\Consensus\Network\Transport;
use Greenwood\Consensus\Runtime\ConsensusDriver;
use Greenwood\Crypto\{Ed25519KeyPair, ProtocolHash};
use Greenwood\Genesis\Genesis;
use Greenwood\Node\Chain;
use Greenwood\Persistence\{BlockFilesystem, StateSqlite};
use Greenwood\Rpc\HttpApi;
use Greenwood\Tests\Fixtures\ComputeFixture as F;
use Greenwood\Transaction\{Envelope, Transfer};
use PHPUnit\Framework\TestCase;

final class HttpApiTest extends TestCase
{
    private Chain $chain;
    private ConsensusDriver $driver;
    private HttpApi $api;
    private string $dir;
    private Ed25519KeyPair $userKey;
    private string $userAddress;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gw-rpc-' . bin2hex(random_bytes(6));
        // Validator A doubles as the fixture provider so TRUSTED/SOVEREIGN
        // eligibility comes from the genesis pools, as in production genesis.
        $g = new Genesis('rpc-test');
        foreach (['B', 'C', 'D'] as $id) $g->addValidator($id, Ed25519KeyPair::generate(), 1);
        $g->addValidator('A', F::key(), 1);
        $this->userKey = Ed25519KeyPair::generate();
        $this->userAddress = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $this->userKey->getPublicKey());
        $g->addInitialAccount('provider', F::key(), 10000);
        $g->addInitialAccount('user', $this->userKey, 5000);
        $this->chain = new Chain($g, new BlockFilesystem($this->dir . '/blocks'), new StateSqlite($this->dir . '/state.sqlite'));
        $this->driver = new ConsensusDriver($this->chain, 'E', [], null, 'http://127.0.0.1:0');
        $ref = new \ReflectionProperty(ConsensusDriver::class, 'api');
        $this->api = $ref->getValue($this->driver);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        rmdir($this->dir);
    }

    private function tx(int $type, string $payload, int $nonce, ?Ed25519KeyPair $key = null): Envelope
    {
        $key ??= F::key();
        $tx = new Envelope(1, 'rpc-test', ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $key->getPublicKey()), $nonce, $type, $payload, '', $key->getPublicKey());
        $tx->setSignature($key->sign($tx->getSigningDigest()));
        return $tx;
    }

    private function finalize(array $txs): \Greenwood\Block\Block
    {
        $validators = $this->chain->genesis->getValidators(); ksort($validators, SORT_STRING);
        $ids = array_keys($validators);
        $height = $this->chain->tip->getHeight() + 1;
        $proposer = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $validators[$ids[$height % 4]]['publicKey']);
        $block = $this->chain->build($txs, 0, $proposer);
        $this->chain->finalize($block);
        return $block;
    }

    private function seedCompute(): array
    {
        $request = F::request(['requestNonce' => 0, 'deadlineHeight' => 10]);
        $id = $request->getId();
        $requestTx = $this->tx(7, $request->toBytes(), 0);
        $this->finalize([$requestTx]);
        $assign = new ComputeAssign($id, F::providerId()->toBytes(), F::key()->getPublicKey(), 1, 10);
        $this->finalize([$this->tx(8, $assign->toBytes(), 1)]);
        $receipt = (new ComputeReceiptV2(F::receipt(['requestId' => $id]), 1))->sign(F::key());
        $this->finalize([$this->tx(9, $receipt->toSignedBytes(), 2)]);
        $capability = new ProviderCapability(F::providerId()->toBytes(), F::key()->getPublicKey(), ProviderCapability::STATUS_ACTIVE, 1, ['linux/arm64/v8'], 4000, 1 << 30, 128, 4);
        $this->finalize([$this->tx(10, $capability->toBytes(), 3)]);
        return [$request, $requestTx, $receipt];
    }

    private function get(string $path): array
    {
        $r = $this->api->handle('GET', $path, '');
        return [$r->status, json_decode($r->body, true, 64, JSON_THROW_ON_ERROR), $r->contentType];
    }

    public function testChainStatusFinalityAndValidators(): void
    {
        $this->seedCompute();
        [$status, $body] = $this->get('/v1/chain/status');
        self::assertSame(200, $status);
        self::assertSame('rpc-test', $body['chain_id']);
        self::assertSame('v1', $body['api_version']);
        self::assertSame('full_node', $body['role']);
        self::assertSame(4, $body['height']);
        self::assertSame(4, $body['finalized_height']);
        self::assertSame(4, $body['validator_count']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['block_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['state_root']);
        self::assertFalse($body['syncing']);
        self::assertArrayNotHasKey('accounts', $body);

        [$status, $body] = $this->get('/v1/chain/finality');
        self::assertSame(200, $status);
        self::assertSame(4, $body['height']);
        self::assertSame(4, $body['validator_count']);
        self::assertArrayHasKey('certificate_present', $body);
        self::assertArrayHasKey('precommit_count', $body);

        [$status, $body] = $this->get('/v1/chain/validators');
        self::assertSame(200, $status);
        self::assertCount(4, $body['validators']);
        $byId = array_column($body['validators'], null, 'validator_id');
        $a = $byId['A'];
        self::assertSame('A', $a['validator_id']);
        self::assertSame(F::PROVIDER_PUBLIC_KEY, $a['public_key']);
        self::assertSame(1, $a['voting_power']);
        self::assertTrue($a['active']);
        // No key paths, journals or local signing state are exposed.
        self::assertSame(['validator_id', 'public_key', 'voting_power', 'active'], array_keys($a));

        [$status, $body] = $this->get('/v1/chain/state-root');
        self::assertSame(200, $status);
        self::assertSame(bin2hex($this->chain->state->getStateRoot()), $body['state_root']);
    }

    public function testBlocksByHeightAndHash(): void
    {
        [, $requestTx] = $this->seedCompute();
        $hash = bin2hex($requestTx->getHash());
        [$status, $body] = $this->get('/v1/blocks/1');
        self::assertSame(200, $status);
        self::assertSame(1, $body['height']);
        self::assertSame([$hash], $body['transactions']);
        [$status, $byHash] = $this->get('/v1/blocks/hash/' . $body['block_hash']);
        self::assertSame(200, $status);
        self::assertSame($body, $byHash);

        [$status, $err] = $this->get('/v1/blocks/99');
        self::assertSame(404, $status);
        self::assertSame('BLOCK_NOT_FOUND', $err['error']['code']);
        [$status] = $this->get('/v1/blocks/hash/' . str_repeat('0', 64));
        self::assertSame(404, $status);
        [$status, $err] = $this->get('/v1/blocks/hash/' . str_repeat('0', 63));
        self::assertSame(400, $status); // malformed hash is INVALID_REQUEST
        self::assertSame('INVALID_REQUEST', $err['error']['code']);
    }

    public function testAccounts(): void
    {
        $this->seedCompute();
        $provider = bin2hex(F::requester());
        [$status, $body] = $this->get('/v1/accounts/' . $provider);
        self::assertSame(200, $status);
        self::assertSame($provider, $body['account_id']);
        self::assertSame(4, $body['nonce']);
        self::assertIsString($body['balance']); // u64 serialized as decimal string

        [, $balance] = $this->get('/v1/accounts/' . $provider . '/balance');
        self::assertSame($body['balance'], $balance['balance']);
        [, $nonce] = $this->get('/v1/accounts/' . $provider . '/nonce');
        self::assertSame(4, $nonce['nonce']);

        [$status, $err] = $this->get('/v1/accounts/' . str_repeat('1', 64));
        self::assertSame(404, $status);
        self::assertSame('ACCOUNT_NOT_FOUND', $err['error']['code']);
        [$status, $err] = $this->get('/v1/accounts/XYZ');
        self::assertSame(400, $status);
        self::assertSame('INVALID_REQUEST', $err['error']['code']);
    }


    public function testTransactionSubmissionLifecycle(): void
    {
        $this->seedCompute();
        $recipient = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', F::key()->getPublicKey());
        $tx = $this->tx(1, (new Transfer($recipient, 100))->serialize(), 0, $this->userKey);
        $body = Transport::envelope('transaction', $tx->getCanonicalBytes());
        $hash = bin2hex($tx->getHash());

        $r = $this->api->handle('POST', '/v1/transactions', $body);
        self::assertSame(200, $r->status);
        $data = json_decode($r->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(['accepted' => true, 'tx_hash' => $hash, 'status' => 'PENDING'], $data);

        [, $status] = $this->get('/v1/transactions/' . $hash . '/status');
        self::assertSame('PENDING', $status['status']);

        // Duplicate submission is idempotent: same admission path, same hash.
        $r = $this->api->handle('POST', '/v1/transactions', $body);
        self::assertSame(200, $r->status);
        self::assertSame($hash, json_decode($r->body, true)['tx_hash']);

        $this->finalize([$tx]);
        [, $status] = $this->get('/v1/transactions/' . $hash . '/status');
        self::assertSame('FINALIZED', $status['status']);
        self::assertSame(5, $status['height']);
        [, $full] = $this->get('/v1/transactions/' . $hash);
        self::assertSame('FINALIZED', $full['status']);
        self::assertSame(1, $full['type']);
        self::assertSame(bin2hex($this->userAddress), $full['sender']);
        self::assertSame(0, $full['nonce']);

        // Rejected by the canonical admission path: bad nonce, 422 semantic failure.
        $bad = $this->tx(1, (new Transfer($recipient, 100))->serialize(), 0, $this->userKey);
        $r = $this->api->handle('POST', '/v1/transactions', Transport::envelope('transaction', $bad->getCanonicalBytes()));
        self::assertSame(422, $r->status);
        self::assertSame('TRANSACTION_REJECTED', json_decode($r->body, true)['error']['code']);

        // Malformed body, unknown hash.
        $r = $this->api->handle('POST', '/v1/transactions', '{"type":"transaction"');
        self::assertSame(422, $r->status);
        [$code, $statusBody] = $this->get('/v1/transactions/' . str_repeat('2', 64) . '/status');
        self::assertSame(200, $code);
        self::assertSame('UNKNOWN', $statusBody['status']);
        [$code] = $this->get('/v1/transactions/' . str_repeat('2', 64));
        self::assertSame(404, $code);
    }

    public function testComputeRequestHistoryReceipt(): void
    {
        [$request, , $receipt] = $this->seedCompute();
        $hex = bin2hex($request->getId());

        [$status, $body] = $this->get('/v1/compute/requests/' . $hex);
        self::assertSame(200, $status);
        self::assertSame($hex, $body['request_id']);
        self::assertSame('TRUSTED', $body['trust_class']);
        self::assertSame(RequestState::TERMINAL, $body['lifecycle']);
        self::assertSame(1, $body['current_generation']);
        self::assertSame(F::PROVIDER_ID, $body['current_provider']);
        self::assertSame(bin2hex($receipt->getId()), $body['terminal_receipt']);
        self::assertSame(10, $body['deadline_height']);
        self::assertIsString($body['memory_limit_bytes']);
        self::assertIsString($body['max_payment']);

        [, $history] = $this->get('/v1/compute/requests/' . $hex . '/history');
        self::assertSame(RequestState::TERMINAL, $history['lifecycle']);
        self::assertSame([['generation' => 1, 'provider_id' => F::PROVIDER_ID, 'height' => 2, 'status' => 'SETTLED']], $history['assignments']);

        [, $dto] = $this->get('/v1/compute/requests/' . $hex . '/receipt');
        self::assertSame(bin2hex($receipt->getId()), $dto['receipt_id']);
        self::assertSame($hex, $dto['request_id']);
        self::assertSame(1, $dto['generation']);
        self::assertSame(F::PROVIDER_ID, $dto['provider_id']);
        self::assertSame('COMPLETED', $dto['status']);
        self::assertSame(0, $dto['exit_code']);
        self::assertSame('812', $dto['cpu_time_ms']);
        self::assertSame('33554432', $dto['memory_peak_bytes']);
        self::assertSame('901', $dto['duration_ms']);
        self::assertSame(bin2hex(F::outputDigest()), $dto['output_digest']);

        [, $byId] = $this->get('/v1/compute/receipts/' . bin2hex($receipt->getId()));
        self::assertSame($dto, $byId);

        [$status, $err] = $this->get('/v1/compute/requests/' . str_repeat('3', 64));
        self::assertSame(404, $status);
        self::assertSame('COMPUTE_REQUEST_NOT_FOUND', $err['error']['code']);
        [$status] = $this->get('/v1/compute/receipts/' . str_repeat('3', 64));
        self::assertSame(404, $status);
    }


    public function testProvidersAndReservations(): void
    {
        $this->seedCompute();
        [$status, $body] = $this->get('/v1/compute/providers');
        self::assertSame(200, $status);
        self::assertCount(1, $body['providers']);
        self::assertNull($body['next_cursor']);
        $p = $body['providers'][0];
        self::assertSame(F::PROVIDER_ID, $p['provider_id']);
        self::assertSame('ACTIVE', $p['status']);
        self::assertSame(['OPEN', 'TRUSTED', 'SOVEREIGN'], $p['trust_classes']);
        self::assertSame(['linux/arm64/v8'], $p['architectures']);
        self::assertSame(4000, $p['cpu_capacity_millis']);
        self::assertSame((string) (1 << 30), $p['memory_capacity_bytes']);
        self::assertSame(128, $p['pid_capacity']);
        self::assertSame(4, $p['max_concurrent_jobs']);
        self::assertSame(['cpu_millis' => 0, 'memory_bytes' => '0', 'pids' => 0, 'jobs' => 0], $p['reserved']);
        self::assertSame(4000, $p['available']['cpu_millis']);
        self::assertSame('0', $p['reserved']['memory_bytes']);

        [, $single] = $this->get('/v1/compute/providers/' . F::PROVIDER_ID);
        self::assertSame($p, $single);

        [, $res] = $this->get('/v1/compute/providers/' . F::PROVIDER_ID . '/reservations');
        self::assertSame(F::PROVIDER_ID, $res['provider_id']);
        self::assertSame(0, $res['reserved']['jobs']);
        self::assertSame(4, $res['finalized_height']);

        [$status, $err] = $this->get('/v1/compute/providers/' . str_repeat('4', 64));
        self::assertSame(404, $status);
        self::assertSame('PROVIDER_NOT_FOUND', $err['error']['code']);

        // Pagination bounds are explicit.
        [$status, $err] = $this->get('/v1/compute/providers?limit=0');
        self::assertSame(400, $status);
        self::assertSame('INVALID_REQUEST', $err['error']['code']);
        [$status] = $this->get('/v1/compute/providers?limit=201');
        self::assertSame(400, $status);
        [$status] = $this->get('/v1/compute/providers?cursor=xyz');
        self::assertSame(400, $status);
        [$status] = $this->get('/v1/compute/providers?bogus=1');
        self::assertSame(400, $status);
        [$status, $ok] = $this->get('/v1/compute/providers?limit=50&cursor=' . F::PROVIDER_ID);
        self::assertSame(200, $status);
        self::assertSame([], $ok['providers']);
    }

    public function testHealthReadinessAndMetrics(): void
    {
        $this->seedCompute();
        [$status, $body] = $this->get('/v1/health');
        self::assertSame(200, $status);
        self::assertSame(['ok' => true], $body);

        [$status, $body] = $this->get('/v1/ready');
        self::assertSame(200, $status);
        self::assertTrue($body['ready']);
        self::assertSame('full_node', $body['role']);
        self::assertSame(['chain_state' => true, 'transaction_index' => true, 'identity' => true], $body['checks']);
        [$status2, $body2] = $this->get('/ready');
        self::assertSame([$status, $body], [$status2, $body2]);

        $r = $this->api->handle('GET', '/metrics', '');
        self::assertSame(200, $r->status);
        self::assertStringStartsWith('text/plain', $r->contentType);
        foreach ([
            'greenwood_finalized_height 4',
            'greenwood_validator_count 4',
            'greenwood_pending_transactions 0',
            'greenwood_compute_requests_total 1',
            'greenwood_compute_assignments_total 1',
            'greenwood_compute_receipts_total 1',
            'greenwood_compute_reassignments_total 0',
            'greenwood_providers_registered 1',
        ] as $line) self::assertStringContainsString($line, $r->body);
        // No unbounded identifiers appear in metric lines.
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $r->body);
    }

    public function testStructuredErrors(): void
    {
        $r = $this->api->handle('GET', '/v1/definitely-not-a-route', '');
        self::assertSame(404, $r->status);
        $err = json_decode($r->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(['code', 'message', 'details'], array_keys($err['error']));
        self::assertSame('NOT_FOUND', $err['error']['code']);
        // No stack traces or exception classes leak.
        self::assertStringNotContainsString('Exception', $r->body);
        self::assertStringNotContainsString('Stack trace', $r->body);

        // Malformed hex identifier on a known route shape.
        $r = $this->api->handle('GET', '/v1/accounts/' . str_repeat('zz', 32), '');
        self::assertSame(400, $r->status); // malformed identifier -> INVALID_REQUEST, not a 500

        $r = $this->api->handle('POST', '/v1/chain/status', '');
        self::assertSame(404, $r->status);
    }

    public function testLegacyRoutesUnchanged(): void
    {
        $this->seedCompute();
        // Existing internal routes still respond through the legacy handler.
        $driver = new \ReflectionMethod(ConsensusDriver::class, 'handle');
        $health = $driver->invoke($this->driver, 'GET', '/health', '');
        self::assertSame(['ok' => true], $health);
        $status = $driver->invoke($this->driver, 'GET', '/status', '');
        self::assertSame('rpc-test', $status['chain_id']);
        self::assertSame(4, $status['finalized_height']);
        // Legacy ProviderQueries view still available at unversioned paths.
        $request = F::request(['requestNonce' => 0, 'deadlineHeight' => 10]);
        $view = \Greenwood\Rpc\ProviderQueries::handle($this->chain, '/compute/requests/' . bin2hex($request->getId()) . '/history');
        self::assertSame([['generation' => 1, 'provider_id' => F::PROVIDER_ID, 'height' => 2]], $view['assignments']);
    }
}

