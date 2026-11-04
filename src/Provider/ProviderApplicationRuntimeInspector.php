<?php

declare(strict_types=1);

namespace Greenwood\Provider;

use Containerd\Services\Leases\V1\ListRequest;
use Containerd\V1\Types\Status;
use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\Service\ContainerService;
use Greenwood\Compute\Containerd\Service\SnapshotService;
use Greenwood\Compute\Containerd\Service\TaskService;
use Greenwood\Contracts\ContainerdRpcException;

final class ProviderApplicationRuntimeInspector
{
    public function __construct(
        private readonly \Closure $containerLookup,
        private readonly \Closure $taskLookup,
        private readonly \Closure $snapshotLookup,
        private readonly ?\Closure $leaseLookup = null
    ) {
    }

    public static function fromClients(
        ContainerdClientSet $clients
    ): self {
        $containers =
            new ContainerService($clients);

        $tasks =
            new TaskService($clients);

        $snapshots =
            new SnapshotService($clients);

        return new self(
            containerLookup:
                static function (
                    string $containerId
                ) use ($containers): array {
                    $container =
                        $containers->get(
                            $containerId
                        );

                    return [
                        'image_ref' =>
                            $container->getImage(),
                        'snapshot_key' =>
                            $container->getSnapshotKey(),
                    ];
                },
            taskLookup:
                static function (
                    string $containerId
                ) use ($tasks): array {
                    $process =
                        $tasks
                            ->get(
                                $containerId
                            )
                            ->getProcess();

                    $state = match (
                        $process->getStatus()
                    ) {
                        Status::RUNNING =>
                            'RUNNING',
                        Status::STOPPED =>
                            'STOPPED',
                        Status::CREATED =>
                            'CREATED',
                        default =>
                            'OTHER',
                    };

                    return [
                        'state' => $state,
                        'pid' =>
                            (int) $process->getPid(),
                    ];
                },
            snapshotLookup:
                static function (
                    string $viewKey
                ) use ($snapshots): array {
                    $snapshot =
                        $snapshots->stat(
                            $viewKey
                        );

                    return [
                        'parent_snapshot' =>
                            $snapshot->getParent(),
                    ];
                },
            leaseLookup:
                static function (
                    string $leaseId
                ) use ($clients): ?array {
                    [$reply, $status] =
                        $clients
                            ->getLeases()
                            ->List(
                                new ListRequest(),
                                $clients
                                    ->getConnection()
                                    ->getMetadata(),
                                ['timeout' => 30_000_000]
                            )
                            ->wait();

                    if (
                        $status->code
                        !== \Grpc\STATUS_OK
                    ) {
                        throw new ContainerdRpcException(
                            'Lease list unavailable during application reconciliation',
                            $status->code
                        );
                    }

                    foreach (
                        $reply->getLeases()
                        as $lease
                    ) {
                        if (
                            $lease->getId()
                            === $leaseId
                        ) {
                            return [
                                'id' => $leaseId,
                            ];
                        }
                    }

                    return null;
                }
        );
    }

    public function inspect(
        array $record
    ): array {
        foreach (
            [
                'container_id',
                'view_key',
                'lease_id',
                'image_ref',
            ]
            as $field
        ) {
            if (
                !isset($record[$field])
                || !is_string(
                    $record[$field]
                )
                || $record[$field] === ''
            ) {
                throw new \InvalidArgumentException(
                    'Application runtime record lacks '
                    . $field
                );
            }
        }

        $container =
            $this->optional(
                fn (): array =>
                    ($this->containerLookup)(
                        $record['container_id']
                    )
            );

        $task =
            $this->optional(
                fn (): array =>
                    ($this->taskLookup)(
                        $record['container_id']
                    )
            );

        $snapshot =
            $this->optional(
                fn (): array =>
                    ($this->snapshotLookup)(
                        $record['view_key']
                    )
            );

        $leaseObserved =
            $this->leaseLookup !== null;

        $lease =
            $leaseObserved
                ? ($this->leaseLookup)(
                    $record['lease_id']
                )
                : null;

        if (
            $container === null
            && $task === null
            && $snapshot === null
            && (
                !$leaseObserved
                || $lease === null
            )
        ) {
            return $this->result(
                'ABSENT',
                null,
                null,
                null,
                false,
                false,
                false,
                $leaseObserved
                    ? false
                    : null
            );
        }

        if (
            $container !== null
            && (
                ($container['image_ref'] ?? null)
                    !== $record['image_ref']
                || ($container[
                    'snapshot_key'
                ] ?? null)
                    !== $record['view_key']
            )
        ) {
            return $this->result(
                'INCONSISTENT',
                $task['pid'] ?? null,
                $task['state'] ?? null,
                $snapshot[
                    'parent_snapshot'
                ] ?? null,
                true,
                $task !== null,
                $snapshot !== null,
                $leaseObserved
                    ? $lease !== null
                    : null
            );
        }

        if (
            $container === null
            || $task === null
            || $snapshot === null
            || (
                $leaseObserved
                && $lease === null
            )
        ) {
            return $this->result(
                'RESIDUE',
                $task['pid'] ?? null,
                $task['state'] ?? null,
                $snapshot[
                    'parent_snapshot'
                ] ?? null,
                $container !== null,
                $task !== null,
                $snapshot !== null,
                $leaseObserved
                    ? $lease !== null
                    : null
            );
        }

        $runtimeState =
            match (
                $task['state'] ?? null
            ) {
                'RUNNING' => 'RUNNING',
                'STOPPED' => 'STOPPED',
                default => 'RESIDUE',
            };

        return $this->result(
            $runtimeState,
            $task['pid'] ?? null,
            $task['state'] ?? null,
            $snapshot[
                'parent_snapshot'
            ] ?? null,
            true,
            true,
            true,
            $leaseObserved
                ? true
                : null
        );
    }

    private function optional(
        \Closure $lookup
    ): ?array {
        try {
            return $lookup();
        } catch (\Throwable $error) {
            if (
                (int) $error->getCode()
                === ContainerdRpcException::RPC_NOT_FOUND
            ) {
                return null;
            }

            throw $error;
        }
    }

    private function result(
        string $runtimeState,
        ?int $pid,
        ?string $taskState,
        ?string $parentSnapshot,
        bool $containerPresent,
        bool $taskPresent,
        bool $snapshotPresent,
        ?bool $leasePresent
    ): array {
        return [
            'runtime_state' =>
                $runtimeState,
            'pid' => $pid,
            'task_state' => $taskState,
            'parent_snapshot' =>
                $parentSnapshot,
            'container_present' =>
                $containerPresent,
            'task_present' =>
                $taskPresent,
            'snapshot_present' =>
                $snapshotPresent,
            'lease_present' =>
                $leasePresent,
        ];
    }
}
