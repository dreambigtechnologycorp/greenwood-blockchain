<?php

namespace Greenwood\Tests\Compute\Containerd;

use Containerd\Services\Containers\V1\ListContainersRequest;
use Containerd\Services\Tasks\V1\ListTasksRequest;
use Greenwood\Compute\Containerd\ContainerdConnection;
use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\ContainerdExecutor;
use Greenwood\Compute\Containerd\Service\SnapshotService;
use Greenwood\Compute\Runtime\ExecutionResult;
use Greenwood\Compute\Runtime\ExecutionStatus;
use PHPUnit\Framework\TestCase;

/**
 * 4A.4 — Runtime hardening: repetition, mixed terminal states, concurrency.
 *
 * Zero-residue invariant is asserted after EVERY execution, not just at
 * the end of the run.
 */
class RuntimeHardeningTest extends TestCase
{
    private const IMAGE = 'docker.io/library/alpine:latest';

    private ?ContainerdConnection $connection = null;
    private ?ContainerdClientSet $clients = null;
    private ?ContainerdExecutor $executor = null;
    private ?SnapshotService $snapshotService = null;

    protected function setUp(): void
    {
        $this->connection = new ContainerdConnection();
        $this->clients = new ContainerdClientSet($this->connection);
        $this->executor = new ContainerdExecutor($this->clients);
        $this->snapshotService = new SnapshotService($this->clients);
    }

    public function testFiftySequentialSuccesses(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $result = $this->executor->run(
                self::IMAGE,
                "/bin/ash -c 'printf \"run{$i}\\n\"'"
            );

            $tag = "run {$i}: status={$result->getStatus()} exit={$result->getExitCode()} "
                . "err=" . var_export($result->getErrorMessage(), true)
                . " stderr=" . var_export($result->getStderr(), true);

            if (!$result->getStatus()->isCompleted()) {
                // DIAGNOSTIC capture at failure time: does the prepared
                // snapshot storage still exist? (transient RUNTIME_ERROR
                // seen under repetition: shim mount ENOENT on upperdir)
                $diag = shell_exec('sudo ls /var/lib/containerd/io.containerd.snapshotter.v1.overlayfs/snapshots/ 2>&1');
                $tag .= "\nsnapshot storage ids at failure:\n" . $diag;
            }

            self::assertTrue($result->getStatus()->isCompleted(), $tag);
            self::assertSame(0, $result->getExitCode(), $tag);
            self::assertSame("run{$i}\n", $result->getStdout(), $tag);
            self::assertSame("", $result->getStderr(), $tag);

            $this->assertNoExecutionResidue($tag);
        }
    }

    public function testFiftyMixedSequential(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $kind = $i % 5;
            $tag = "mixed {$i} (kind {$kind})";

            switch ($kind) {
                case 0: // COMPLETED
                    $result = $this->executor->run(self::IMAGE, "/bin/ash -c 'exit 0'");
                    self::assertTrue($result->getStatus()->isCompleted(), $this->resultContext($tag, $result));
                    self::assertSame(0, $result->getExitCode(), $tag);
                    break;

                case 1: // FAILED
                    $result = $this->executor->run(self::IMAGE, "/bin/ash -c 'exit 7'");
                    self::assertTrue($result->getStatus()->isFailed(), $this->resultContext($tag, $result));
                    self::assertSame(7, $result->getExitCode(), $tag);
                    break;

                case 2: // TERMINATED (explicit stop)
                    $result = $this->executor->run(
                        self::IMAGE,
                        "/bin/ash -c 'sleep 300'",
                        terminateAfterMs: 300
                    );
                    self::assertTrue($result->getStatus()->isTerminated(), $this->resultContext($tag, $result));
                    break;

                case 3: // TIMED_OUT (deadline)
                    $result = $this->executor->run(
                        self::IMAGE,
                        "/bin/ash -c 'sleep 300'",
                        timeoutMs: 1000
                    );
                    self::assertTrue($result->getStatus()->isTimedOut(), $this->resultContext($tag, $result));
                    break;

                case 4: // RUNTIME_ERROR (unpullable image)
                    $result = $this->executor->run(
                        'docker.io/library/alpine:gw-definitely-nonexistent-tag',
                        "/bin/ash -c 'exit 0'"
                    );
                    self::assertTrue($result->getStatus()->isError(), $this->resultContext($tag, $result));
                    break;
            }

            $this->assertNoExecutionResidue($tag);
        }
    }

    private function resultContext(string $tag, ExecutionResult $result): string
    {
        return $tag . ': status=' . $result->getStatus()
            . ' exit=' . var_export($result->getExitCode(), true)
            . ' error=' . var_export($result->getErrorMessage(), true)
            . ' stderr=' . var_export($result->getStderr(), true);
    }

    public function testFiveConcurrentWorkloads(): void
    {
        // Workers run as separate PHP processes (grpc channel state is not
        // fork-safe, and separate processes mirror the provider model).
        $n = 5;
        $workerScript = __DIR__ . '/Fixtures/concurrent_worker.php';
        self::assertFileExists($workerScript);

        $processes = [];
        $pipes = [];

        for ($i = 0; $i < $n; $i++) {
            $proc = proc_open(
                [PHP_BINARY, $workerScript, (string) $i],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $procPipes
            );
            self::assertIsResource($proc, "proc_open {$i} failed");
            $processes[$i] = $proc;
            $pipes[$i] = $procPipes;
        }

        // All workers are running concurrently; collect their results.
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderr = stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $exitCode = proc_close($processes[$i]);

            $data = json_decode((string) $stdout, true);
            self::assertIsArray($data, "worker {$i}: no JSON result; stderr={$stderr}");
            self::assertArrayNotHasKey('error', $data, "worker {$i}: " . ($data['error'] ?? ''));
            self::assertSame(0, $exitCode, "worker {$i} process exit");
            self::assertSame(ExecutionStatus::COMPLETED, $data['status'], "worker {$i} status: " . ($data['errorMessage'] ?? ''));
            self::assertSame(0, $data['exitCode'], "worker {$i} exit code");
            self::assertSame("worker{$i}\n", $data['stdout'], "worker {$i}: stdout cross-talk");
            self::assertSame("", $data['stderr'], "worker {$i}: stderr cross-talk");
            $ids[] = $data['executionId'];
        }
        self::assertCount($n, array_unique($ids), 'Execution IDs must be unique');

        $this->assertNoExecutionResidue('concurrency');
    }


    /**
     * Zero-residue invariant: no Greenwood task, container, writable
     * snapshot, or stale FIFO may remain after any execution.
     */
    private function assertNoExecutionResidue(string $context = ''): void
    {
        $metadata = $this->connection->getMetadata();
        $prefix = $context === '' ? '' : "[{$context}] ";

        [$tasksResp, $tasksStatus] = $this->clients->getTasks()
            ->List(new ListTasksRequest(), $metadata, ['timeout' => 30_000_000])
            ->wait();
        self::assertSame(\Grpc\STATUS_OK, $tasksStatus->code, $prefix . 'Tasks.List failed');
        $gwTasks = [];
        foreach ($tasksResp->getTasks() as $task) {
            if (str_starts_with($task->getId(), 'gw-')) {
                $gwTasks[] = $task->getId();
            }
        }
        self::assertSame([], $gwTasks, $prefix . 'Leaked Greenwood tasks');

        [$ctrResp, $ctrStatus] = $this->clients->getContainers()
            ->List(new ListContainersRequest(), $metadata, ['timeout' => 30_000_000])
            ->wait();
        self::assertSame(\Grpc\STATUS_OK, $ctrStatus->code, $prefix . 'Containers.List failed');
        $gwContainers = [];
        foreach ($ctrResp->getContainers() as $container) {
            if (str_starts_with($container->getId(), 'gw-')) {
                $gwContainers[] = $container->getId();
            }
        }
        self::assertSame([], $gwContainers, $prefix . 'Leaked Greenwood containers');

        $gwSnapshots = [];
        foreach ($this->snapshotService->list() as $info) {
            if (str_starts_with($info->getName(), 'gw-snapshot-')) {
                $gwSnapshots[] = $info->getName();
            }
        }
        self::assertSame([], $gwSnapshots, $prefix . 'Leaked Greenwood snapshots');

        $fifos = glob(sys_get_temp_dir() . '/*fifo*_gw_*') ?: [];
        self::assertSame([], $fifos, $prefix . 'Leaked IO FIFOs');
    }
}
