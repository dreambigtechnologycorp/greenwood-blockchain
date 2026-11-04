<?php

declare(strict_types=1);

namespace Greenwood\Provider;

use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\Service\ContainerService;
use Greenwood\Compute\Containerd\Service\LeaseService;
use Greenwood\Compute\Containerd\Service\SnapshotService;
use Greenwood\Compute\Containerd\Service\TaskService;
use Greenwood\Contracts\ContainerdException;

final class ProviderApplicationRuntimeCleaner
{
    private const SIGTERM = 15;
    private const SIGKILL = 9;

    private const TERM_WAIT_MICROS = 2_000_000;
    private const KILL_WAIT_MICROS = 5_000_000;

    public function __construct(
        private readonly ProviderApplicationRuntimeInspector $inspector,
        private readonly \Closure $terminateTask,
        private readonly \Closure $deleteTask,
        private readonly \Closure $deleteContainer,
        private readonly \Closure $deleteSnapshot,
        private readonly \Closure $deleteLease
    ) {
    }

    public static function fromClients(
        ContainerdClientSet $clients
    ): self {
        $tasks =
            new TaskService($clients);

        $containers =
            new ContainerService($clients);

        $snapshots =
            new SnapshotService($clients);

        $leases =
            new LeaseService($clients);

        return new self(
            ProviderApplicationRuntimeInspector::fromClients(
                $clients
            ),
            terminateTask:
                static function (
                    string $containerId
                ) use ($tasks): void {
                    $reaped = false;

                    try {
                        $tasks->kill(
                            $containerId,
                            self::SIGTERM,
                            '',
                            true
                        );

                        try {
                            $tasks->wait(
                                $containerId,
                                '',
                                self::TERM_WAIT_MICROS
                            );

                            $reaped = true;
                        } catch (\Throwable) {
                        }
                    } catch (\Throwable) {
                    }

                    if (!$reaped) {
                        try {
                            $tasks->kill(
                                $containerId,
                                self::SIGKILL,
                                '',
                                true
                            );
                        } catch (\Throwable) {
                        }

                        try {
                            $tasks->wait(
                                $containerId,
                                '',
                                self::KILL_WAIT_MICROS
                            );

                            $reaped = true;
                        } catch (\Throwable) {
                        }
                    }

                    if (!$reaped) {
                        throw new ContainerdException(
                            'Application task exit is unconfirmed; preserving backing resources'
                        );
                    }
                },
            deleteTask:
                static fn (
                    string $containerId
                ) =>
                    $tasks->delete(
                        $containerId,
                        ''
                    ),
            deleteContainer:
                static fn (
                    string $containerId
                ) =>
                    $containers->delete(
                        $containerId
                    ),
            deleteSnapshot:
                static fn (
                    string $viewKey
                ) =>
                    $snapshots->remove(
                        $viewKey
                    ),
            deleteLease:
                static fn (
                    string $leaseId
                ) =>
                    $leases->delete(
                        $leaseId
                    )
        );
    }

    public function cleanup(
        array $record
    ): array {
        $before =
            $this->inspector->inspect(
                $record
            );

        if (
            $before['runtime_state']
            === 'INCONSISTENT'
        ) {
            throw new \RuntimeException(
                'Application runtime ownership is inconsistent; refusing cleanup'
            );
        }

        if (
            $before['lease_present']
            === null
        ) {
            throw new \RuntimeException(
                'Application lease ownership was not observed; refusing cleanup'
            );
        }

        if (
            $before['runtime_state']
            === 'ABSENT'
        ) {
            return [
                'outcome' =>
                    'ALREADY_ABSENT',
                'before' => $before,
                'after' => $before,
            ];
        }

        if (
            $before['task_present']
        ) {
            if (
                $before['task_state']
                === 'RUNNING'
            ) {
                ($this->terminateTask)(
                    $record['container_id']
                );
            } elseif (
                $before['task_state']
                !== 'STOPPED'
            ) {
                throw new \RuntimeException(
                    'Application task state is not safely cleanable'
                );
            }

            ($this->deleteTask)(
                $record['container_id']
            );
        }

        if (
            $before['container_present']
        ) {
            ($this->deleteContainer)(
                $record['container_id']
            );
        }

        if (
            $before['snapshot_present']
        ) {
            ($this->deleteSnapshot)(
                $record['view_key']
            );
        }

        if (
            $before['lease_present']
        ) {
            ($this->deleteLease)(
                $record['lease_id']
            );
        }

        $after =
            $this->inspector->inspect(
                $record
            );

        if (
            $after['runtime_state']
            !== 'ABSENT'
        ) {
            throw new ContainerdException(
                'Owned application runtime residue remains after cleanup'
            );
        }

        return [
            'outcome' => 'CLEANED',
            'before' => $before,
            'after' => $after,
        ];
    }
}
