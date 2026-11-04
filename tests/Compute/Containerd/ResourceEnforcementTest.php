<?php

namespace Greenwood\Tests\Compute\Containerd;

use Containerd\Services\Containers\V1\ListContainersRequest;
use Containerd\Services\Tasks\V1\ListTasksRequest;
use Greenwood\Compute\Containerd\ContainerdConnection;
use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\Service\SnapshotService;
use PHPUnit\Framework\TestCase;

/**
 * 4A.4.5 — Resource enforcement at the cgroup level.
 *
 * OCI spec fields are NOT accepted as proof. Each test inspects the live
 * cgroup of the running workload and/or kernel-level enforcement evidence.
 *
 * Resource enforcement is a workload-level terminal outcome (FAILED /
 * TIMED_OUT / TERMINATED), never RUNTIME_ERROR.
 */
class ResourceEnforcementTest extends TestCase
{
    private const WORKER = __DIR__ . '/Fixtures/resource_worker.php';

    private ?ContainerdConnection $connection = null;
    private ?ContainerdClientSet $clients = null;
    private ?SnapshotService $snapshotService = null;

    protected function setUp(): void
    {
        $this->connection = new ContainerdConnection();
        $this->clients = new ContainerdClientSet($this->connection);
        $this->snapshotService = new SnapshotService($this->clients);
    }

    /** @return array{0: resource, 1: array} [process, pipes] */
    private function startWorker(string $mode): array
    {
        $proc = proc_open(
            [PHP_BINARY, self::WORKER, $mode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc, "proc_open failed for mode {$mode}");
        return [$proc, $pipes];
    }

    /** @return array{0: array, 1: int} [decoded JSON result, process exit code] */
    private function collectWorker($proc, array $pipes): array
    {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $data = json_decode((string) $stdout, true);
        self::assertIsArray($data, "worker produced no JSON; stderr={$stderr}");
        self::assertArrayNotHasKey('error', $data, 'worker error: ' . ($data['error'] ?? ''));
        return [$data, $exit];
    }

    /** Locate the single live Greenwood task cgroup (tests run sequentially). */
    private function findCgroup(int $timeoutSeconds = 15): string
    {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            $out = trim((string) shell_exec(
                'sudo find /sys/fs/cgroup -maxdepth 6 -type d -name "gw-exec-*" 2>/dev/null | head -1'
            ));
            if ($out !== '') {
                return $out;
            }
            usleep(200_000);
        }
        self::fail('No Greenwood cgroup appeared within ' . $timeoutSeconds . 's');
    }

    private function cgRead(string $cgroup, string $file): string
    {
        return trim((string) shell_exec("sudo cat {$cgroup}/{$file} 2>/dev/null"));
    }

    public function testCgroupLimitsAreApplied(): void
    {
        [$proc, $pipes] = $this->startWorker('sleep');
        $cg = $this->findCgroup();

        // Configured limits must be REAL cgroup limits, not just OCI fields.
        self::assertSame((string) (256 * 1024 * 1024), $this->cgRead($cg, 'memory.max'), 'memory.max');
        self::assertSame('100', $this->cgRead($cg, 'pids.max'), 'pids.max');
        self::assertSame('50000 100000', $this->cgRead($cg, 'cpu.max'), 'cpu.max');

        $current = (int) $this->cgRead($cg, 'memory.current');
        self::assertGreaterThan(0, $current, 'memory.current should be nonzero for a live workload');

        [$data] = $this->collectWorker($proc, $pipes);
        self::assertSame('TERMINATED', $data['status']);

        $this->assertNoExecutionResidue();
    }

    public function testMemoryCeilingCannotBeExceeded(): void
    {
        // (a) ~200MiB allocation UNDER the ceiling: high usage visible in the
        //     cgroup, host stays healthy, workload survives until terminated.
        [$proc, $pipes] = $this->startWorker('memtouch');
        $cg = $this->findCgroup();

        $peak = 0;
        $deadline = time() + 8;
        while (time() < $deadline) {
            $peak = max($peak, (int) $this->cgRead($cg, 'memory.current'));
            if ($peak > 150 * 1024 * 1024) {
                break;
            }
            usleep(200_000);
        }
        self::assertGreaterThan(80 * 1024 * 1024, $peak, 'workload should actually allocate ~100MiB');
        self::assertLessThanOrEqual(256 * 1024 * 1024, $peak, 'usage must stay under the ceiling');
        self::assertSame((string) (256 * 1024 * 1024), $this->cgRead($cg, 'memory.max'));

        [$data] = $this->collectWorker($proc, $pipes);
        self::assertSame('TERMINATED', $data['status'], 'under-ceiling workload must survive until terminated');

        // (b) Unbounded allocator: the cgroup OOM killer must stop it.
        [$proc2, $pipes2] = $this->startWorker('memhog');
        [$data2] = $this->collectWorker($proc2, $pipes2);

        self::assertNotSame('COMPLETED', $data2['status'], 'memhog must not complete');
        self::assertNotSame('RUNTIME_ERROR', $data2['status'], 'OOM kill is workload-level, not infrastructure failure');
        self::assertSame(137, $data2['exitCode'], 'pinned after observation: 128+SIGKILL (OOM)');

        $this->assertNoExecutionResidue();
    }

    public function testPidCeilingIsEnforced(): void
    {
        [$proc, $pipes] = $this->startWorker('forkloop');
        $cg = $this->findCgroup();

        self::assertSame('100', $this->cgRead($cg, 'pids.max'), 'pids.max');

        // Kernel proof: pids.events "max" increments when the limit is hit.
        $events = '';
        $deadline = time() + 10;
        while (time() < $deadline) {
            $events = $this->cgRead($cg, 'pids.events');
            if (preg_match('/^max\s+(\d+)$/m', $events, $m) && (int) $m[1] > 0) {
                break;
            }
            usleep(200_000);
        }
        self::assertMatchesRegularExpression('/^max\s+[1-9]\d*$/m', $events, 'pids.events max counter must increment');

        $current = (int) $this->cgRead($cg, 'pids.current');
        self::assertLessThanOrEqual(100, $current, 'pids.current must never exceed the ceiling');

        [$data] = $this->collectWorker($proc, $pipes);
        self::assertSame('TERMINATED', $data['status']);
        // Fork-failure evidence in the workload's own stderr (loose match:
        // busybox wording may vary).
        self::assertMatchesRegularExpression(
            '/fork|temporarily unavailable/i',
            $data['stderrHead'],
            'expected fork-failure evidence in workload stderr'
        );

        $this->assertNoExecutionResidue();
    }

    public function testCpuQuotaThrottlesWorkload(): void
    {
        [$proc, $pipes] = $this->startWorker('cpuburn');
        $cg = $this->findCgroup();

        self::assertSame('50000 100000', $this->cgRead($cg, 'cpu.max'), 'cpu.max must reflect the quota');

        // Kernel proof of throttling: nr_throttled / throttled_usec increase.
        $statBefore = $this->cgRead($cg, 'cpu.stat');
        $throttled = false;
        $statAfter = $statBefore;
        $deadline = time() + 8;
        while (time() < $deadline) {
            $statAfter = $this->cgRead($cg, 'cpu.stat');
            if (preg_match('/^nr_throttled\s+(\d+)$/m', $statAfter, $m) && (int) $m[1] > 0) {
                $throttled = true;
                break;
            }
            usleep(200_000);
        }
        self::assertTrue($throttled, "cpu.stat must show throttling.\nbefore:\n{$statBefore}\nafter:\n{$statAfter}");
        self::assertMatchesRegularExpression('/^throttled_usec\s+[1-9]\d*$/m', $statAfter);

        [$data] = $this->collectWorker($proc, $pipes);
        self::assertSame('TIMED_OUT', $data['status'], 'CPU spin must be stopped by the deadline');

        $this->assertNoExecutionResidue();
    }

    private function assertNoExecutionResidue(): void
    {
        $metadata = $this->connection->getMetadata();

        [$tasksResp, $tasksStatus] = $this->clients->getTasks()
            ->List(new ListTasksRequest(), $metadata, ['timeout' => 30_000_000])
            ->wait();
        self::assertSame(\Grpc\STATUS_OK, $tasksStatus->code, 'Tasks.List failed');
        $gwTasks = [];
        foreach ($tasksResp->getTasks() as $task) {
            if (str_starts_with($task->getId(), 'gw-')) {
                $gwTasks[] = $task->getId();
            }
        }
        self::assertSame([], $gwTasks, 'Leaked Greenwood tasks');

        [$ctrResp, $ctrStatus] = $this->clients->getContainers()
            ->List(new ListContainersRequest(), $metadata, ['timeout' => 30_000_000])
            ->wait();
        self::assertSame(\Grpc\STATUS_OK, $ctrStatus->code, 'Containers.List failed');
        $gwContainers = [];
        foreach ($ctrResp->getContainers() as $container) {
            if (str_starts_with($container->getId(), 'gw-')) {
                $gwContainers[] = $container->getId();
            }
        }
        self::assertSame([], $gwContainers, 'Leaked Greenwood containers');

        $gwSnapshots = [];
        foreach ($this->snapshotService->list() as $info) {
            if (str_starts_with($info->getName(), 'gw-snapshot-')) {
                $gwSnapshots[] = $info->getName();
            }
        }
        self::assertSame([], $gwSnapshots, 'Leaked Greenwood snapshots');

        $fifos = glob(sys_get_temp_dir() . '/*fifo*_gw_*') ?: [];
        self::assertSame([], $fifos, 'Leaked IO FIFOs');
    }
}

