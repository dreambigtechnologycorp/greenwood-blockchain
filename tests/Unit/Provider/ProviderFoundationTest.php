<?php
declare(strict_types=1);
namespace Greenwood\Tests\Unit\Provider;

use Greenwood\Provider\{ProviderIdentity, ProviderConfig, ProviderJobRegistry};
use Greenwood\Compute\Protocol\ComputeReceiptV2;
use Greenwood\Tests\Fixtures\ComputeFixture as F;
use PHPUnit\Framework\TestCase;

final class ProviderFoundationTest extends TestCase
{
    private function directory(): string
    {
        $dir = sys_get_temp_dir() . '/greenwood-provider-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        return $dir;
    }
    private function identity(string $dir): ProviderIdentity
    {
        file_put_contents($dir . '/key', F::key()->toBytes()); chmod($dir . '/key', 0600);
        return ProviderIdentity::fromFile($dir . '/key');
    }
    private function reject(callable $operation): void
    {
        try { $operation(); self::fail('Expected rejection'); }
        catch (\InvalidArgumentException|\RuntimeException|\LogicException $error) { self::assertNotSame('', $error->getMessage()); }
    }

    public function testIdentityBindingAndBothSignatures(): void
    {
        $dir = $this->directory(); $identity = $this->identity($dir);
        self::assertSame(F::providerId()->toBytes(), $identity->providerId);
        self::assertSame(F::requester(), $identity->accountId);
        self::assertNotSame($identity->accountId, $identity->providerId);
        $unsigned = new ComputeReceiptV2(F::receipt(), 1);
        $receipt = $identity->signReceipt($unsigned);
        self::assertSame('', $unsigned->getSignature());
        self::assertTrue($receipt->verifySignature($identity->publicKey));
        $tx = $identity->receiptTransaction('test', 0, $receipt);
        $tx->verify('test');
        self::assertSame($receipt->toSignedBytes(), $tx->getPayload());
        $this->reject(fn () => serialize($identity));
        $this->reject(fn () => ProviderIdentity::fromFile($dir . '/key', str_repeat('x', 32)));
        $raw = F::key()->toBytes(); $raw[32] = chr(ord($raw[32]) ^ 1);
        file_put_contents($dir . '/key', $raw);
        $this->reject(fn () => ProviderIdentity::fromFile($dir . '/key'));
        chmod($dir . '/key', 0644);
        $this->reject(fn () => ProviderIdentity::fromFile($dir . '/key'));
    }

    public function testConfigurationAndCapacityMapping(): void
    {
        $dir = $this->directory();
        $config = new ProviderConfig('test', 'http://127.0.0.1:8080', $dir . '/key', $dir, trustClasses: [1]);
        self::assertTrue($config->admits(F::request()));
        $resources = $config->resourceLimits(F::request());
        self::assertSame(200000, $resources->cpuQuotaMicros);
        self::assertSame(100000, $resources->cpuPeriodMicros);
        self::assertSame(268435456, $resources->memoryBytes);
        self::assertSame(64, $resources->pidsLimit);
        foreach (['cpuLimitMillis' => 5000, 'memoryLimitBytes' => 8589934592, 'pidLimit' => 513, 'executionTimeoutMs' => 3600001, 'outputRetentionBytes' => 1048577, 'trustClass' => 2] as $field => $value) {
            self::assertFalse($config->admits(F::request([$field => $value])));
        }
        $path = $dir . '/config.json';
        file_put_contents($path, json_encode(['chainId' => 'test', 'rpcEndpoint' => 'http://localhost:8000', 'keyFile' => $dir . '/key', 'dataPath' => $dir, 'expectedProviderId' => F::PROVIDER_ID], JSON_THROW_ON_ERROR));
        self::assertSame(F::providerId()->toBytes(), ProviderConfig::fromFile($path)->expectedProviderId);
        $this->reject(fn () => new ProviderConfig('test', 'http://user:secret@localhost', $dir . '/key', $dir));
        $this->reject(fn () => new ProviderConfig('test', 'http://localhost', $dir . '/key', $dir, maxConcurrent: 0));
        $this->reject(fn () => new ProviderConfig('test', 'http://localhost', $dir . '/key', $dir, snapshotter: 'other'));
    }

    public function testDurableTupleIdentityDrainAndExclusiveSupervisor(): void
    {
        $dir = $this->directory(); $id = F::request()->getId(); $provider = F::providerId()->toBytes();
        $registry = new ProviderJobRegistry($dir, 'test', $provider);
        $registry->discover($id, 1); $registry->discover($id, 1); $registry->discover($id, 2);
        self::assertCount(2, $registry->jobs());
        $this->reject(fn () => new ProviderJobRegistry($dir, 'test', $provider));
        if (PHP_OS_FAMILY === 'Linux' && posix_geteuid() === 0) $this->reject(fn () => new ProviderJobRegistry($this->directory(), 'test', $provider));
        $registry->transition($id, 1, 'DISCOVERED', 'QUEUED');
        $registry->observeHeight(20); $registry->setDrain(true);
        unset($registry);
        $registry = new ProviderJobRegistry($dir, 'test', $provider);
        self::assertSame('QUEUED', $registry->get($id, 1)['state']);
        self::assertSame(20, $registry->observedHeight()); self::assertTrue($registry->isDraining());
        $this->reject(fn () => $registry->observeHeight(19));
        $this->reject(fn () => $registry->transition($id, 1, 'QUEUED', 'STARTING'));
        self::assertSame('QUEUED', $registry->get($id, 1)['state']);
        unset($registry);
        $this->reject(fn () => new ProviderJobRegistry($dir, 'other-chain', $provider));
    }

    public function testDurableOutboxSerializesFiveProviderNonces(): void
    {
        $dir = $this->directory(); $identity = $this->identity($dir);
        $registry = new ProviderJobRegistry($dir, 'test', $identity->providerId);
        $receipts = [];
        for ($i = 0; $i < 5; $i++) {
            $id = hash('sha256', 'local-test-' . $i, true);
            $registry->discover($id, 1);
            $registry->transition($id, 1, 'DISCOVERED', 'QUEUED');
            $registry->transition($id, 1, 'QUEUED', 'STARTING', executionId: 'local-' . $i);
            $registry->transition($id, 1, 'STARTING', 'RUNNING');
            $receipt = $identity->signReceipt(new ComputeReceiptV2(F::receipt(['requestId' => $id]), 1));
            $registry->transition($id, 1, 'RUNNING', 'RECEIPT_READY', receipt: $receipt->toSignedBytes());
            $receipts[] = [$id, $receipt];
        }
        $nonces = [];
        foreach ($receipts as $i => [$id, $receipt]) {
            $tx = $identity->receiptTransaction('test', 10 + $i, $receipt);
            $registry->transition($id, 1, 'RECEIPT_READY', 'SUBMITTING', transaction: $tx->getCanonicalBytes());
            if ($i < 4) {
                [$otherId, $otherReceipt] = $receipts[$i + 1];
                $otherTx = $identity->receiptTransaction('test', 10 + $i, $otherReceipt);
                $this->reject(fn () => $registry->transition($otherId, 1, 'RECEIPT_READY', 'SUBMITTING', transaction: $otherTx->getCanonicalBytes()));
                self::assertSame('RECEIPT_READY', $registry->get($otherId, 1)['state']);
            }
            unset($registry);
            $registry = new ProviderJobRegistry($dir, 'test', $identity->providerId);
            self::assertSame($tx->getCanonicalBytes(), $registry->get($id, 1)['transaction_bytes']);
            self::assertSame($receipt->toSignedBytes(), $registry->get($id, 1)['receipt']);
            $nonces[] = $tx->getNonce();
            $registry->transition($id, 1, 'SUBMITTING', 'SUBMITTED');
            $registry->transition($id, 1, 'SUBMITTED', 'SETTLED');
        }
        self::assertSame([10, 11, 12, 13, 14], $nonces);
    }

    public function testDatabaseFailureCannotAdvanceLaunchIntent(): void
    {
        $dir = $this->directory(); $id = F::request()->getId();
        $registry = new ProviderJobRegistry($dir, 'test', F::providerId()->toBytes());
        $registry->discover($id, 1); $registry->transition($id, 1, 'DISCOVERED', 'QUEUED');
        $db = new \PDO('sqlite:' . $dir . '/provider.sqlite');
        $db->exec("CREATE TRIGGER fail_intent BEFORE UPDATE ON provider_jobs BEGIN SELECT RAISE(ABORT, 'injected persistence failure'); END");
        $this->reject(fn () => $registry->transition($id, 1, 'QUEUED', 'STARTING', executionId: 'local-intent'));
        self::assertSame('QUEUED', $registry->get($id, 1)['state']);
        self::assertNull($registry->get($id, 1)['execution_id']);
    }
}
