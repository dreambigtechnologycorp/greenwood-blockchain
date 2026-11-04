<?php
declare(strict_types=1);

namespace Greenwood\Compute\Containerd\Service;

use Greenwood\Compute\Containerd\ExecutionRegistry;
use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;
use Containerd\Services\Tasks\V1\ListTasksRequest;
use Containerd\Services\Containers\V1\ListContainersRequest;
use Greenwood\Compute\Runtime\ExecutionStatus;

/**
 * Execution lifecycle manager with restart reconciliation.
 *
 * Integrates the ExecutionRegistry with containerd state to:
 * - Record state transitions durably before making runtime changes
 * - Recover execution state on provider restart
 * - Detect and handle orphans
 */
class ExecutionRegistryService
{
    private ExecutionRegistry $registry;
    private ContainerdClientSet $clients;
    private string $storagePath;

    public function __construct(
        string $storagePath,
        ContainerdClientSet $clients
    ) {
        $this->storagePath = $storagePath;
        $this->clients = $clients;
        $this->registry = new ExecutionRegistry($storagePath);
    }

    /**
     * Get the registry instance.
     */
    public function getRegistry(): ExecutionRegistry
    {
        return $this->registry;
    }

    /**
     * Record intent to terminate explicitly before initiating termination.
     */
    public function recordExplicitTermination(string $executionId): void
    {
        $this->registry->recordExplicitTermination($executionId);
    }

    /**
     * Record intent to terminate due to timeout before initiating termination.
     */
    public function recordTimeoutTermination(string $executionId): void
    {
        $this->registry->recordTimeoutTermination($executionId);
    }

    /**
     * On startup, reconcile runtime state with registry state.
     *
     * Returns three categories:
     * - active: registry says RUNNING, runtime says RUNNING
     * - stopped: registry says RUNNING, runtime says STOPPED (or not found)
     * - orphan: runtime says RUNNING, registry has no entry
     *
     * @return array{
     *     active: array,
     *     stopped: array,
     *     orphan: array,
     *     stale: array
     * }
     */
    public function reconcile(): array
    {
        $active = [];
        $stopped = [];
        $orphan = [];
        $stale = [];

        // Load registry entries
        $registryEntries = $this->registry->listActiveExecutions();
        $registryByContainer = [];
        foreach ($registryEntries as $entry) {
            $registryByContainer[$entry['container_id']] = $entry;
        }

        // List runtime containers
        $runtimeContainers = $this->listRuntimeContainers();
        $runtimeByContainer = [];
        foreach ($runtimeContainers as $container) {
            $runtimeByContainer[$container['id']] = $container;
        }

        // List runtime tasks
        $runtimeTasks = $this->listRuntimeTasks();
        $runtimeTaskByContainer = [];
        foreach ($runtimeTasks as $task) {
            $runtimeTaskByContainer[$task['id']] = $task;
        }

        // Check each registry entry against runtime
        foreach ($registryEntries as $entry) {
            $containerId = $entry['container_id'];

            if (isset($runtimeTaskByContainer[$containerId])) {
                // Runtime task exists
                $taskState = $runtimeTaskByContainer[$containerId]['state'] ?? 'RUNNING';
                if (in_array($taskState, ['CREATED', 'RUNNING', 'PAUSED', 'PAUSING'], true)) {
                    $active[] = $entry;
                } elseif ($taskState === 'STOPPED') {
                    // Task stopped but registry still active
                    $stopped[] = $entry;
                } else throw new \RuntimeException('Unknown task state during reconciliation');
            } elseif (isset($runtimeByContainer[$containerId])) {
                // Container exists but no task (orphaned)
                $stale[] = $entry;
            } else {
                // Neither container nor task exists
                $stale[] = $entry;
            }
        }

        // Check for orphans: runtime containers without registry entry
        foreach ($runtimeContainers as $container) {
            $cid = $container['id'];
            if (!isset($registryByContainer[$cid]) && str_starts_with($cid, 'gw-')) {
                $orphan[] = $container;
            }
        }

        return [
            'active' => $active,
            'stopped' => $stopped,
            'orphan' => $orphan,
            'stale' => $stale,
        ];
    }

    /**
     * List runtime containers (Greenwood-owned only).
     *
     * @return array
     */
    private function listRuntimeContainers(): array
    {
        $metadata = $this->clients->getConnection()->getMetadata();
        
        try {
            [$resp, $status] = $this->clients->getContainers()
                ->List(new ListContainersRequest(), $metadata, ['timeout' => 30_000_000])
                ->wait();

            if ($status->code !== \Grpc\STATUS_OK) throw new \RuntimeException('Container list unavailable during reconciliation');

            $result = [];
            foreach ($resp->getContainers() as $container) {
                $id = $container->getId();
                if (str_starts_with($id, 'gw-')) {
                    $result[] = [
                        'id' => $id,
                        'image' => $container->getImage(),
                        'labels' => $container->getLabels(),
                    ];
                }
            }
            return $result;
        } catch (\Throwable $e) { throw new \RuntimeException('Container reconciliation failed', 0, $e); }
    }

    /**
     * List runtime tasks (Greenwood-owned only).
     *
     * @return array
     */
    private function listRuntimeTasks(): array
    {
        $metadata = $this->clients->getConnection()->getMetadata();
        
        try {
            [$resp, $status] = $this->clients->getTasks()
                ->List(new ListTasksRequest(), $metadata, ['timeout' => 30_000_000])
                ->wait();

            if ($status->code !== \Grpc\STATUS_OK) throw new \RuntimeException('Task list unavailable during reconciliation');

            $result = [];
            foreach ($resp->getTasks() as $task) {
                $id = $task->getId();
                if (str_starts_with($id, 'gw-')) {
                    $result[] = [
                        'id' => $id,
                        'state' => self::taskState($task->getStatus()),
                        'pid' => $task->getPid(),
                    ];
                }
            }
            return $result;
        } catch (\Throwable $e) { throw new \RuntimeException('Task reconciliation failed', 0, $e); }
    }

    /** Only a confirmed STOPPED task may be classified as stopped. */
    public static function taskState(int $status): string
    {
        return match ($status) {
            \Containerd\V1\Types\Status::CREATED => 'CREATED',
            \Containerd\V1\Types\Status::RUNNING => 'RUNNING',
            \Containerd\V1\Types\Status::STOPPED => 'STOPPED',
            \Containerd\V1\Types\Status::PAUSED => 'PAUSED',
            \Containerd\V1\Types\Status::PAUSING => 'PAUSING',
            default => throw new \RuntimeException('Unknown task state during reconciliation'),
        };
    }

    /**
     * Clean up a stopped execution.
     *
     * @param array $entry Registry entry
     */
    public function cleanupExecution(array $entry): void
    {
        $containerId = $entry['container_id'];
        $snapshotKey = $entry['snapshot_key'];

        // Delete task
        try {
            $this->clients->getTaskService()->delete($containerId, '');
        } catch (\Throwable) {
            // Ignore if already deleted
        }

        // Delete container
        try {
            $this->clients->getContainerService()->delete($containerId);
        } catch (\Throwable) {
            // Ignore if already deleted
        }

        // Remove snapshot
        try {
            $this->clients->getSnapshotService()->remove($snapshotKey);
        } catch (\Throwable) {
            // Ignore if already deleted
        }

        // Remove registry entry
        $this->registry->deleteExecution($entry['execution_id']);
    }
}
