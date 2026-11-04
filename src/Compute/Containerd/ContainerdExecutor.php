<?php
declare(strict_types=1);

namespace Greenwood\Compute\Containerd;

use Greenwood\Compute\Containerd\Model\OciPlatform;
use Greenwood\Compute\Containerd\Service\ContainerService;
use Greenwood\Compute\Containerd\Service\ImageRootfsResolver;
use Greenwood\Compute\Containerd\Service\ImagePreparationService;
use Greenwood\Compute\Containerd\Service\ImageService;
use Greenwood\Compute\Containerd\Service\SnapshotService;
use Greenwood\Compute\Containerd\Service\TaskService;
use Greenwood\Compute\Runtime\ExecutionResult;
use Greenwood\Compute\Runtime\ExecutionStatus;
use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;

/**
 * Semantic executor over the proven native containerd lifecycle.
 *
 * Maps low-level task mechanics to Greenwood terminal statuses:
 *
 *   exit 0 (natural exit)            -> COMPLETED
 *   exit != 0 (natural exit)         -> FAILED
 *   deadline initiated termination   -> TIMED_OUT
 *   explicit stop initiated          -> TERMINATED
 *   containerd/runtime failure       -> RUNTIME_ERROR
 *
 * TIMED_OUT vs TERMINATED is determined by WHY Greenwood initiated
 * termination, never by which signal (SIGTERM/SIGKILL) finally reaped
 * the workload.
 *
 * Termination state machine (frozen):
 *   SIGTERM(all) -> bounded grace wait
 *     -> exit observed: reaped
 *     -> Deadline Exceeded: escalate (a wait timeout is NOT a terminal state)
 *   SIGKILL(all) -> bounded wait -> reaped
 *   then, in order: task delete -> container delete -> snapshot remove
 *   -> IO cleanup. Container/snapshot are never deleted beneath a live task.
 */
final class ContainerdExecutor
{
    private const GRACE_WAIT_MICROS = 2_000_000;    // 2s after SIGTERM
    private const KILL_WAIT_MICROS = 5_000_000;     // 5s after SIGKILL
    private const NATURAL_WAIT_MICROS = 60_000_000; // authoritative reap bound
    private const POLL_INTERVAL_MICROS = 5_000;     // 5ms monitor tick

    private ImageService $imageService;
    private SnapshotService $snapshotService;
    private ContainerService $containerService;
    private TaskService $taskService;
    private ImageRootfsResolver $rootfsResolver;
    private ImagePreparationService $preparationService;
    private Service\LeaseService $leaseService;

    public function __construct(private readonly ContainerdClientSet $clients)
    {
        $this->imageService = new ImageService($clients);
        $this->snapshotService = new SnapshotService($clients);
        $this->containerService = new ContainerService($clients);
        $this->taskService = new TaskService($clients);
        $this->rootfsResolver = new ImageRootfsResolver($clients);
        $this->preparationService = new ImagePreparationService($clients);
        $this->leaseService = new Service\LeaseService($clients);
    }

    /**
     * Execute a shell-command workload to a terminal state.
     *
     * @param string $imageRef OCI image reference
     * @param string $command ash command line executed in the workload
     * @param int|null $timeoutMs Deadline. If the workload is still running
     *                            when it expires, Greenwood terminates it and
     *                            the result is TIMED_OUT.
     * @param int|null $terminateAfterMs Simulated explicit stop request: if
     *                            set, Greenwood initiates termination this
     *                            many ms after start and the result is
     *                            TERMINATED. (Real providers will trigger the
     *                            same state machine from an external cancel.)
     */
    public function run(
        string $imageRef,
        string $command,
        ?int $timeoutMs = null,
        ?int $terminateAfterMs = null,
        ?ResourceLimits $resources = null,
        ?ExecutionLaunch $launch = null
    ): ExecutionResult {
        $executionId = $launch !== null ? $launch->runtimeId : 'native-' . bin2hex(random_bytes(8));
        $snapshotId = $this->snapshotService->generateSnapshotId($executionId);
        $containerId = $this->containerService->generateContainerId($executionId);
        $leaseId = 'gw-lease-' . $executionId;

        $startedAt = new \DateTimeImmutable();
        $startedMono = microtime(true);
        $reaped = false;
        $taskStarted = false;
        $digest = $imageRef;
        $registry = null;
        $accounting = null;

        // Provider linkage: bounded retention and durable execution intent in
        // the job's run directory. The intent record must exist BEFORE the
        // workload starts so a supervisor crash never double-executes.
        $io = new ExecutionIo(sys_get_temp_dir(), $launch?->retentionBytes);

        try {
            $resolvedImage = $this->preparationService->ensureReady($imageRef);
            $image = $this->imageService->get($imageRef);
            $digest = $image->getTarget()->getDigest();

            // Lease-protect the ACTIVE execution snapshot for the whole
            // execution. Between Snapshot.Prepare and Container.Create the
            // snapshot is otherwise unreferenced, and a GC pass triggered
            // by a concurrent Transfer completing elsewhere in the namespace
            // can collect it (reproduced rootfs/overlay mount race).
            $this->leaseService->create($leaseId);

            $imageRootfsParent = $this->rootfsResolver->resolve($image);
            $prepared = $this->snapshotService->prepare($snapshotId, $imageRootfsParent, $leaseId);

            $specBuilder = OciSpecBuilder::forShellCommand('rootfs', $command);
            if ($resources !== null) {
                $specBuilder
                    ->setMemoryLimit($resources->memoryBytes)
                    ->setPidsLimit($resources->pidsLimit);
                if ($resources->cpuQuotaMicros !== null && $resources->cpuPeriodMicros !== null) {
                    $specBuilder->setCpuQuota($resources->cpuQuotaMicros, $resources->cpuPeriodMicros);
                }
            }
            $spec = $specBuilder->build();
            $container = $this->containerService->buildContainer($containerId, $resolvedImage, $snapshotId, $spec);
            $this->containerService->create($container);

            if ($launch !== null) {
                $registry = new ExecutionRegistry($launch->directory);
                $registry->beginExecution($executionId, $containerId, $snapshotId, $imageRef, $command,
                    $launch->requestId, null, $timeoutMs !== null ? time() + intdiv($timeoutMs, 1000) + 1 : null);
                // Durable runtime intent exists; a worker crash from here on
                // must reconcile instead of re-executing.
                ($launch->checkpoint)?->__invoke('runtime_intent');
            }

            $io->create();
            // FIFO readers MUST exist before Tasks.Create, otherwise runc
            // blocks opening the stdio FIFOs and Create deadlocks.
            $io->openReaders();

            $createResponse = $this->taskService->create(
                $containerId,
                '', // init task: empty exec_id
                $prepared,
                '',
                $io->getStdoutPath(),
                $io->getStderrPath()
            );
            if ($launch !== null) {
                // Capture the task cgroup path while the task is in CREATED
                // state: the PID provably exists and cannot have exited yet
                // (Start has not been issued). Counters are read later from
                // the persisted cgroup, before task deletion.
                $accounting = CgroupAccounting::forPid((int)$createResponse->getPid());
            }
            $this->taskService->start($containerId, '');
            $taskStarted = true;

            $deadlineMono = $timeoutMs !== null ? microtime(true) + $timeoutMs / 1000 : null;
            $terminateMono = $terminateAfterMs !== null ? microtime(true) + $terminateAfterMs / 1000 : null;

            // Monitor/drain loop: never block in Wait while the workload may
            // still be writing — a full FIFO would deadlock the workload.
            $outcome = $this->monitor($io, $containerId, $deadlineMono, $terminateMono);
            $reaped = true;

            $exitCode = $outcome['exitCode'];
            $status = match ($outcome['kind']) {
                'natural' => $exitCode === 0
                    ? ExecutionStatus::completed()
                    : ExecutionStatus::failed(),
                'timeout' => ExecutionStatus::timedOut(),
                'terminated' => ExecutionStatus::terminated(),
            };

            $stdout = $io->readStdout();
            $stderr = $io->readStderr();

            $usage = null;
            $durationMs = null;
            if ($launch !== null) {
                // Read counters while the cgroup still exists (before task
                // deletion in the finally block), then mark terminal state.
                $usage = $accounting->usage();
                $durationMs = (int)round((microtime(true) - $startedMono) * 1000);
                $registry->updateRuntimeState($executionId, 'STOPPED');
            }

            return new ExecutionResult(
                executionId: $executionId,
                resolvedImageDigest: $digest,
                status: $status,
                exitCode: $exitCode,
                stdout: $stdout,
                stderr: $stderr,
                startedAt: $startedAt,
                finishedAt: new \DateTimeImmutable(),
                duration: microtime(true) - $startedMono,
                resourceUsage: $usage,
                errorMessage: $status->isTimedOut()
                    ? "Execution deadline expired after {$timeoutMs}ms"
                    : ($status->isTerminated() ? 'Execution terminated by request' : null),
                stdoutTruncated: $io->isStdoutTruncated(),
                stderrTruncated: $io->isStderrTruncated(),
                stdoutBytesObserved: $io->getStdoutBytes(),
                stderrBytesObserved: $io->getStderrBytes(),
                fullStdoutHash: $io->getFullStdoutHash(),
                fullStderrHash: $io->getFullStderrHash(),
                durationMs: $durationMs
            );
        } catch (ContainerdRpcException|ContainerdException $e) {
            // Infrastructure failure — never confused with workload failure.
            // Pre-task failures carry provably-zero accounting (nothing ran);
            // post-start infrastructure failures leave accounting absent so no
            // receipt can be fabricated from incomplete execution evidence.
            return ExecutionResult::runtimeError(
                executionId: $executionId,
                resolvedImageDigest: $digest,
                errorMessage: $e->getMessage(),
                startedAt: $startedAt,
                finishedAt: new \DateTimeImmutable(),
                duration: microtime(true) - $startedMono,
                fullStdoutHash: $taskStarted ? null : hash('sha256', '', true),
                fullStderrHash: $taskStarted ? null : hash('sha256', '', true),
                stdoutBytesObserved: $taskStarted ? null : 0,
                stderrBytesObserved: $taskStarted ? null : 0,
                resourceUsage: $taskStarted ? null : new \Greenwood\Compute\Runtime\ResourceUsage(memoryPeakBytes: 0, cpuTimeMs: 0),
                durationMs: $taskStarted ? null : 0
            );
        } finally {
            // Dependency-ordered cleanup (frozen ordering): the task must be
            // dead and reaped BEFORE container/snapshot deletion.
            if (!$reaped) {
                try { $this->taskService->kill($containerId, 9, '', true); } catch (\Throwable) {}
                try { $this->taskService->wait($containerId, '', self::KILL_WAIT_MICROS); } catch (\Throwable) {}
            }
            try { $this->taskService->delete($containerId, ''); } catch (\Throwable) {}
            try { $this->containerService->delete($containerId); } catch (\Throwable) {}
            try { $this->snapshotService->remove($snapshotId); } catch (\Throwable) {}
            // Delete the lease LAST: while it exists it keeps protecting the
            // execution's own resources; after our explicit removals it has
            // nothing left to hold.
            try { $this->leaseService->delete($leaseId); } catch (\Throwable) {}
            $io->cleanup();
        }
    }

    /**
     * Monitor/drain loop. Continuously drains stdout/stderr (so the workload
     * can never block on a full FIFO) while polling task state. The blocking
     * Wait RPC is used ONLY as the final authoritative reap once the task is
     * observed STOPPED.
     *
     * @return array{kind: 'natural'|'timeout'|'terminated', exitCode: int}
     */
    private function monitor(
        ExecutionIo $io,
        string $containerId,
        ?float $deadlineMono,
        ?float $terminateMono
    ): array {
        while (true) {
            $io->drainAvailable();

            if ($this->isStopped($containerId)) {
                $exitCode = $this->authoritativeReap($io, $containerId);
                return ['kind' => 'natural', 'exitCode' => $exitCode];
            }

            $now = microtime(true);

            if ($terminateMono !== null && $now >= $terminateMono) {
                // Explicit stop initiated by the caller.
                $this->terminateDraining($io, $containerId);
                $exitCode = $this->authoritativeReap($io, $containerId);
                return ['kind' => 'terminated', 'exitCode' => $exitCode];
            }

            if ($deadlineMono !== null && $now >= $deadlineMono) {
                // Deadline initiated termination.
                $this->terminateDraining($io, $containerId);
                $exitCode = $this->authoritativeReap($io, $containerId);
                return ['kind' => 'timeout', 'exitCode' => $exitCode];
            }

            usleep(self::POLL_INTERVAL_MICROS);
        }
    }

    /**
     * Authoritative reap: blocking Wait after the task is already STOPPED,
     * followed by a final output drain.
     */
    private function authoritativeReap(ExecutionIo $io, string $containerId): int
    {
        $exitCode = $this->taskService
            ->wait($containerId, '', self::NATURAL_WAIT_MICROS)
            ->getExitStatus();
        $io->drainAvailable();
        return $exitCode;
    }

    private function isStopped(string $containerId): bool
    {
        $process = $this->taskService->get($containerId, '')->getProcess();
        return $process !== null
            && $process->getStatus() === \Containerd\V1\Types\Status::STOPPED;
    }

    /**
     * SIGTERM(all) -> bounded grace (draining) -> SIGKILL(all) -> bounded
     * wait (draining). Throws if the task still will not stop.
     */
    private function terminateDraining(ExecutionIo $io, string $containerId): void
    {
        $this->taskService->kill($containerId, 15, '', true); // SIGTERM, whole tree

        if ($this->waitUntilStopped($io, $containerId, self::GRACE_WAIT_MICROS)) {
            return;
        }

        $this->taskService->kill($containerId, 9, '', true); // SIGKILL, whole tree

        if (!$this->waitUntilStopped($io, $containerId, self::KILL_WAIT_MICROS)) {
            throw new ContainerdException(
                "Task did not stop after SIGKILL: {$containerId}"
            );
        }
    }

    private function waitUntilStopped(ExecutionIo $io, string $containerId, int $budgetMicros): bool
    {
        $until = microtime(true) + $budgetMicros / 1_000_000;

        while (microtime(true) < $until) {
            $io->drainAvailable();
            if ($this->isStopped($containerId)) {
                return true;
            }
            usleep(self::POLL_INTERVAL_MICROS);
        }

        return false;
    }
}
