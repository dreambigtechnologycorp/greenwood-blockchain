<?php

namespace Greenwood\Tests\Compute\Containerd;

use Containerd\Services\Containers\V1\ListContainersRequest;
use Containerd\Services\Tasks\V1\ListTasksRequest;
use Greenwood\Compute\Containerd\ContainerdConnection;
use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\ContainerdExecutor;
use Greenwood\Compute\Containerd\Service\SnapshotService;
use PHPUnit\Framework\TestCase;

/**
 * 4A.3B.2 — ExecutionStatus matrix over the proven native lifecycle.
 *
 *   success             -> COMPLETED
 *   exit 7              -> FAILED
 *   explicit stop       -> TERMINATED
 *   deadline expiration -> TIMED_OUT
 *   runtime failure     -> RUNTIME_ERROR
 *
 * TERMINATED vs TIMED_OUT is determined by WHY Greenwood initiated
 * termination, not by which signal (SIGTERM/SIGKILL) reaped the workload.
 *
 * Every terminal state must leave zero Greenwood execution residue.
 */
class ExecutionStatusMatrixTest extends TestCase
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

    public function testSuccessMapsToCompleted(): void
    {
        $result = $this->executor->run(self::IMAGE, "/bin/ash -c 'printf \"greenwood\\n\"'");

        self::assertTrue($result->getStatus()->isCompleted());
        self::assertSame(0, $result->getExitCode());
        self::assertSame("greenwood\n", $result->getStdout());
        self::assertSame("", $result->getStderr());

        $this->assertNoExecutionResidue();
    }

    public function testNonZeroExitMapsToFailed(): void
    {
        $result = $this->executor->run(self::IMAGE, "/bin/ash -c 'exit 7'");

        // The runtime worked; the workload failed. Never RUNTIME_ERROR.
        self::assertTrue($result->getStatus()->isFailed());
        self::assertSame(7, $result->getExitCode());
        self::assertSame("", $result->getStdout());
        self::assertSame("", $result->getStderr());

        $this->assertNoExecutionResidue();
    }

    public function testStderrFailureMapsToFailedWithStreamsSeparated(): void
    {
        $result = $this->executor->run(self::IMAGE, "/bin/ash -c 'printf \"bad\\n\" >&2; exit 7'");

        self::assertTrue($result->getStatus()->isFailed());
        self::assertSame(7, $result->getExitCode());
        self::assertSame("", $result->getStdout());
        self::assertSame("bad\n", $result->getStderr());

        $this->assertNoExecutionResidue();
    }

    public function testExplicitTerminationMapsToTerminated(): void
    {
        $result = $this->executor->run(
            self::IMAGE,
            "/bin/ash -c 'sleep 300'",
            terminateAfterMs: 500
        );

        self::assertTrue($result->getStatus()->isTerminated());
        // SIGTERM (143) or escalated SIGKILL (137): both are TERMINATED
        // because Greenwood initiated the stop explicitly.
        self::assertContains($result->getExitCode(), [143, 137]);

        $this->assertNoExecutionResidue();
    }

    public function testDeadlineExpirationMapsToTimedOut(): void
    {
        $result = $this->executor->run(
            self::IMAGE,
            "/bin/ash -c 'sleep 300'",
            timeoutMs: 1500
        );

        self::assertTrue($result->getStatus()->isTimedOut());
        self::assertContains($result->getExitCode(), [143, 137]);
        self::assertNotNull($result->getErrorMessage());

        $this->assertNoExecutionResidue();
    }

    public function testInfrastructureFailureMapsToRuntimeError(): void
    {
        // Unpullable image: the workload never ran. This is an infrastructure
        // failure and must not be reported as FAILED.
        $result = $this->executor->run(
            'docker.io/library/alpine:gw-definitely-nonexistent-tag',
            "/bin/ash -c 'exit 0'"
        );

        self::assertTrue($result->getStatus()->isError());
        self::assertFalse($result->getStatus()->isFailed());
        self::assertNotSame('', $result->getErrorMessage() ?? '');

        $this->assertNoExecutionResidue();
    }

    /**
     * Cleanup invariant (4A.3B.2-F): after every terminal state there must
     * be no Greenwood task, container, writable snapshot, or stale FIFO.
     */
    private function assertNoExecutionResidue(): void
    {
        $metadata = $this->connection->getMetadata();

        // Tasks
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

        // Containers
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

        // Writable snapshots (any gw-snapshot key must be gone)
        $gwSnapshots = [];
        foreach ($this->snapshotService->list() as $info) {
            if (str_starts_with($info->getName(), 'gw-snapshot-')) {
                $gwSnapshots[] = $info->getName();
            }
        }
        self::assertSame([], $gwSnapshots, 'Leaked Greenwood snapshots');

        // Stale FIFOs in the IO temp dir
        $fifos = glob(sys_get_temp_dir() . '/*fifo*_gw_*') ?: [];
        self::assertSame([], $fifos, 'Leaked IO FIFOs');
    }
}

