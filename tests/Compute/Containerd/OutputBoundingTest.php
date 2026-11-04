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
 * 4A.4.6 — Concurrent output drain + bounding.
 *
 * Frozen output policy:
 *   - Greenwood drains stdout/stderr CONTINUOUSLY while the workload runs;
 *     a workload must never block on a full FIFO (deadlock).
 *   - Retention is bounded per stream (default 1MB); overflow bytes are
 *     counted and DISCARDED, and the stream is marked truncated.
 *   - The workload itself is never killed for output volume (v1 policy).
 */
class OutputBoundingTest extends TestCase
{
    private const IMAGE = 'docker.io/library/alpine:latest';
    private const LIMIT = 1024 * 1024; // default per-stream retention

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

    public function testLargeStdoutDoesNotDeadlock(): void
    {
        // 2MB stdout > 64KB pipe capacity: without concurrent draining the
        // workload would block in write() forever. dd stats go to /dev/null.
        $result = $this->executor->run(
            self::IMAGE,
            "/bin/ash -c 'dd if=/dev/zero bs=1M count=2 2>/dev/null'"
        );

        self::assertTrue($result->getStatus()->isCompleted(), (string) $result->getErrorMessage());
        self::assertSame(0, $result->getExitCode());
        self::assertSame(self::LIMIT, strlen($result->getStdout()), 'retained stdout must be capped');
        self::assertTrue($result->isStdoutTruncated());
        self::assertSame(2 * self::LIMIT, $result->getStdoutBytesObserved());
        self::assertFalse($result->isStderrTruncated());

        $fullHash = hash('sha256', str_repeat("\0", 2 * self::LIMIT), true);
        self::assertSame($fullHash, $result->getFullStdoutHash());
        self::assertNotSame(bin2hex($fullHash), $result->getStdoutHash(), 'Retained and full-stream hashes are distinct');
        self::assertSame(\Greenwood\Compute\Runtime\OutputCommitment::digest(2 * self::LIMIT, $fullHash, 0, hash('sha256', '', true)), $result->getOutputDigest());

        $this->assertNoExecutionResidue();
    }

    public function testLargeStderrDoesNotDeadlock(): void
    {
        // 2MB to stderr: subshell dd writes data to fd1, the outer redirect
        // maps that to stderr; dd's own stats line is discarded.
        $result = $this->executor->run(
            self::IMAGE,
            "/bin/ash -c '(dd if=/dev/zero bs=1M count=2 2>/dev/null) 1>&2'"
        );

        self::assertTrue($result->getStatus()->isCompleted(), (string) $result->getErrorMessage());
        self::assertSame(0, $result->getExitCode());
        self::assertSame("", $result->getStdout());
        self::assertSame(self::LIMIT, strlen($result->getStderr()), 'retained stderr must be capped');
        self::assertTrue($result->isStderrTruncated());
        self::assertGreaterThanOrEqual(2 * self::LIMIT, $result->getStderrBytesObserved());
        $fullHash = hash('sha256', str_repeat("\0", 2 * self::LIMIT), true);
        self::assertSame($fullHash, $result->getFullStderrHash());
        self::assertSame(\Greenwood\Compute\Runtime\OutputCommitment::digest(0, hash('sha256', '', true), 2 * self::LIMIT, $fullHash), $result->getOutputDigest());

        $this->assertNoExecutionResidue();
    }

    public function testInterleavedStreamsNeitherStarves(): void
    {
        // 256KB to EACH stream, interleaved line by line: one busy stream
        // must not starve the other's drain.
        $a = str_repeat('a', 250);
        $b = str_repeat('b', 250);
        $cmd = "/bin/ash -c 'i=0; while [ \$i -lt 1000 ]; do "
            . "printf \"{$a}\\n\"; printf \"{$b}\\n\" >&2; i=\$((i+1)); done'";

        $result = $this->executor->run(self::IMAGE, $cmd);

        self::assertTrue($result->getStatus()->isCompleted(), (string) $result->getErrorMessage());
        self::assertSame(0, $result->getExitCode());
        self::assertSame(1000 * 251, strlen($result->getStdout()));
        self::assertSame(1000 * 251, strlen($result->getStderr()));
        self::assertFalse($result->isStdoutTruncated());
        self::assertFalse($result->isStderrTruncated());
        self::assertSame(1000 * 251, $result->getStdoutBytesObserved());
        self::assertSame(1000 * 251, $result->getStderrBytesObserved());

        $this->assertNoExecutionResidue();
    }

    public function testSmallOutputUnchanged(): void
    {
        $result = $this->executor->run(self::IMAGE, "/bin/ash -c 'printf \"greenwood\\n\"'");

        self::assertTrue($result->getStatus()->isCompleted());
        self::assertSame("greenwood\n", $result->getStdout());
        self::assertSame("", $result->getStderr());
        self::assertFalse($result->isStdoutTruncated());
        self::assertFalse($result->isStderrTruncated());
        self::assertSame(10, $result->getStdoutBytesObserved());
        self::assertSame(0, $result->getStderrBytesObserved());

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
