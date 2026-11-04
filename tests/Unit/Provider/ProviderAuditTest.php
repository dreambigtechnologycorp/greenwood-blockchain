<?php
declare(strict_types=1);
namespace Greenwood\Tests\Unit\Provider;

use Greenwood\Provider\{AssignmentView, ProviderIdentity, ProviderJobRegistry, ReceiptBuilder};
use Greenwood\Compute\Protocol\ComputeAssign;
use Greenwood\Compute\Containerd\Service\ExecutionRegistryService;
use Greenwood\Tests\Fixtures\ComputeFixture as F;
use PHPUnit\Framework\TestCase;

final class ProviderAuditTest extends TestCase
{
    public function testExactAccountingAndMonotonicRangeChecks(): void
    {
        foreach (['0' => 0, '999' => 0, '1000' => 1, (string)PHP_INT_MAX => intdiv(PHP_INT_MAX, 1000)] as $raw => $expected) self::assertSame($expected, \Greenwood\Compute\Containerd\CgroupAccounting::cpuMillis((string)$raw));
        $this->rejects(fn () => \Greenwood\Compute\Containerd\CgroupAccounting::cpuMillis('9223372036854775808'));
        $this->rejects(fn () => \Greenwood\Compute\Containerd\CgroupAccounting::cpuMillis('-1'));
        self::assertSame(1, \Greenwood\Compute\Runtime\Monotonic::elapsedMs(1000, 1001000));
        self::assertSame(intdiv(PHP_INT_MAX, 1000000), \Greenwood\Compute\Runtime\Monotonic::elapsedMs(0, PHP_INT_MAX));
        $this->rejects(fn () => \Greenwood\Compute\Runtime\Monotonic::elapsedMs(2, 1));
    }
    public function testTwoIndependentClaimersExcludeSecondWorker(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0) { self::assertTrue(true, 'Native race runs in Linux root suite'); return; }
        $dir = $this->directory();
        $registry = new ProviderJobRegistry($dir, 'race', str_repeat('p', 32));
        $registry->discover(str_repeat('r', 32), 1); $registry->transition(str_repeat('r', 32), 1, 'DISCOVERED', 'QUEUED'); unset($registry);
        $children = [];
        foreach (['A', 'B'] as $label) $children[] = proc_open([PHP_BINARY, __DIR__ . '/../../Fixtures/provider_claim_racer.php', $dir, $label],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
        $deadline = microtime(true) + 5;
        while ((!is_file($dir . '/A.ready') || !is_file($dir . '/B.ready')) && microtime(true) < $deadline) usleep(1000);
        file_put_contents($dir . '/go', '1');
        foreach ($children as $child) self::assertSame(0, proc_close($child));
        $results = [json_decode(file_get_contents($dir . '/A.result'), true), json_decode(file_get_contents($dir . '/B.result'), true)];
        self::assertSame(1, count(array_filter($results, fn ($r) => $r['claimed'])));
        self::assertSame(1, count(array_filter($results, fn ($r) => isset($r['pid']))));
        self::assertCount(1, glob($dir . '/workers/*/run/facts.json'));
    }
    private function directory(): string
    {
        $dir = sys_get_temp_dir() . '/greenwood-audit-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700); return $dir;
    }
    private function rejects(callable $call): void
    {
        try { $call(); self::fail('Expected rejection'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { self::assertNotSame('', $e->getMessage()); }
    }
    public function testGenerationZeroAndDuplicateClaimCannotAdvance(): void
    {
        $registry = new ProviderJobRegistry($this->directory(), 'audit', F::providerId()->toBytes());
        $id = F::request()->getId();
        $this->rejects(fn () => $registry->discover($id, 0));
        $registry->discover($id, 1);
        $registry->transition($id, 1, 'DISCOVERED', 'QUEUED');
        $registry->transition($id, 1, 'QUEUED', 'STARTING', executionId: 'native-0000000000000001');
        $this->rejects(fn () => $registry->transition($id, 1, 'QUEUED', 'STARTING', executionId: 'native-0000000000000002'));
        self::assertSame('native-0000000000000001', $registry->get($id, 1)['execution_id']);
        self::assertSame(1, ComputeAssign::nextGeneration(0));
        $this->rejects(fn () => ComputeAssign::nextGeneration(0xffffffff));
    }
    public function testReceiptBoundaryRejectsStaleMismatchAndMalformedFacts(): void
    {
        $dir = $this->directory(); file_put_contents($dir . '/key', F::key()->toBytes()); chmod($dir . '/key', 0600);
        $identity = ProviderIdentity::fromFile($dir . '/key'); $request = F::request();
        $assignment = new ComputeAssign($request->getId(), $identity->providerId, $identity->publicKey, 1, 100);
        $view = new AssignmentView(5, $request, $assignment, null);
        $empty = hash('sha256', '', true); $runtime = 'native-0000000000000001';
        $facts = ['version' => 1, 'runtime_id' => $runtime, 'status' => 'COMPLETED', 'exit_code' => 0,
            'image_digest' => 'sha256:' . bin2hex($request->getWorkloadDigest()),
            'stdout' => '', 'stderr' => '', 'stdout_length' => 0, 'stderr_length' => 0,
            'stdout_hash' => bin2hex($empty), 'stderr_hash' => bin2hex($empty),
            'stdout_truncated' => false, 'stderr_truncated' => false,
            'output_digest' => bin2hex(\Greenwood\Compute\Runtime\OutputCommitment::digest(0, $empty, 0, $empty)),
            'cpu_time_ms' => 1, 'memory_peak_bytes' => 4096, 'duration_ms' => 10,
            // These attacker-chosen protocol fields must never override the assignment.
            'request_id' => str_repeat('ff', 32), 'generation' => 99, 'provider_id' => str_repeat('ee', 32)];
        $job = ['request_id' => $request->getId(), 'generation' => 1, 'provider_id' => $identity->providerId,
            'state' => 'RUNNING', 'execution_id' => $runtime, 'protocol_execution_id' => \Greenwood\Provider\ProviderContent::executionId($runtime)];
        $build = fn ($v, $f) => ReceiptBuilder::build($v, $identity, $runtime, $f, $dir, $job);
        $unsigned = $build($view, $facts);
        self::assertSame('', $unsigned->getSignature());
        $receipt = $identity->signReceipt($unsigned);
        self::assertTrue($receipt->verifySignature($identity->publicKey));
        self::assertSame($request->getId(), $receipt->body()->getRequestId());
        self::assertSame(1, $receipt->generation);
        self::assertSame($identity->providerId, $receipt->body()->getProviderId());
        foreach (['exit_code' => 2, 'runtime_id' => 'wrong', 'image_digest' => 'wrong', 'cpu_time_ms' => -1, 'output_digest' => str_repeat('0', 64)] as $name => $value) {
            $bad = $facts; $bad[$name] = $value;
            $this->rejects(fn () => $build($view, $bad));
        }
        $this->rejects(fn () => $build(new AssignmentView(101, $request, $assignment, null), $facts));
        $this->rejects(fn () => $build(new AssignmentView(5, $request, $assignment, $receipt), $facts));
        $other = new ComputeAssign(str_repeat('x', 32), $identity->providerId, $identity->publicKey, 1, 100);
        $this->rejects(fn () => $build(new AssignmentView(5, $request, $other, null), $facts));
        $badJob = $job; $badJob['generation'] = 2;
        $this->rejects(fn () => ReceiptBuilder::build($view, $identity, $runtime, $facts, $dir, $badJob));
    }
    public function testRuntimeStateMustBeKnownAndOnlyStoppedIsStopped(): void
    {
        foreach ([1 => 'CREATED', 2 => 'RUNNING', 3 => 'STOPPED', 4 => 'PAUSED', 5 => 'PAUSING'] as $code => $name) self::assertSame($name, ExecutionRegistryService::taskState($code));
        $this->rejects(fn () => ExecutionRegistryService::taskState(0));
        $this->rejects(fn () => ExecutionRegistryService::taskState(99));
    }
    public function testActualWorkerRequiresClaimAndIsolation(): void
    {
        $dir = $this->directory();
        file_put_contents($dir . '/key', F::key()->toBytes()); chmod($dir . '/key', 0600);
        $config = new \Greenwood\Provider\ProviderConfig('audit', 'http://127.0.0.1:1', $dir . '/key', $dir,
            workerEntrypoint: realpath(__DIR__ . '/../../Fixtures/provider_process_probe.php'));
        $registry = new ProviderJobRegistry($dir, 'audit', F::providerId()->toBytes());
        $manager = new \Greenwood\Provider\ProcessManager($config, F::providerId()->toBytes(), $registry);
        $previous = getenv('GREENWOOD_AUDIT_SECRET'); putenv('GREENWOOD_AUDIT_SECRET=audit-sentinel');
        $keyFd = fopen($dir . '/key', 'rb');
        $signer = ProviderIdentity::fromFile($dir . '/key');
        $runtime = 'native-0000000000000003';
        try {
            $this->rejects(fn () => $manager->launch($runtime, F::request()->getId(), 1));
            self::assertDirectoryDoesNotExist($dir . '/workers');
            $registry->discover(F::request()->getId(), 1);
            $registry->transition(F::request()->getId(), 1, 'DISCOVERED', 'QUEUED');
            $registry->transition(F::request()->getId(), 1, 'QUEUED', 'STARTING', executionId: $runtime);
            if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0) {
                $this->rejects(fn () => $manager->launch($runtime, F::request()->getId(), 1)); return;
            }
            $pid = $manager->launch($runtime, F::request()->getId(), 1);
            $deadline = microtime(true) + 5;
            while ($manager->busy($runtime) && microtime(true) < $deadline) usleep(10000);
            self::assertFalse($manager->busy($runtime), 'Probe process failed to exit');
            $manager->reap(); $facts = $manager->facts($runtime);
            self::assertNotSame(getmypid(), $pid);
            self::assertSame($pid, $facts['pid']);
            self::assertFalse($facts['inherited_secret']);
            self::assertSame($config->workerUid, $facts['uid']);
            self::assertNotSame(posix_geteuid(), $facts['uid']);
            self::assertFalse($facts['key_readable']);
            self::assertFalse($facts['inherited_key_fd']);
            self::assertSame(['version', 'mode', 'runtime_id', 'request_id', 'generation', 'provider_id', 'chain_id', 'rpc_endpoint', 'containerd_socket', 'snapshotter'], $facts['job_fields']);
            $jobPath = $config->dataPath . '/workers/' . $runtime . '/job.json';
            $job = json_decode(file_get_contents($jobPath), true, 16, JSON_THROW_ON_ERROR);
            foreach (['other', 7, null] as $invalid) {
                $job['snapshotter'] = $invalid;
                file_put_contents($jobPath, json_encode($job, JSON_THROW_ON_ERROR));
                $this->rejects(fn () => \Greenwood\Provider\WorkerJob::read($jobPath));
            }
        } finally { fclose($keyFd); unset($signer); putenv($previous === false ? 'GREENWOOD_AUDIT_SECRET' : 'GREENWOOD_AUDIT_SECRET=' . $previous); }
    }
}
