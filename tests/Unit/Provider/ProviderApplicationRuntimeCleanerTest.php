<?php

declare(strict_types=1);

namespace Greenwood\Tests\Unit\Provider;

use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;
use Greenwood\Provider\ProviderApplicationRuntimeCleaner;
use Greenwood\Provider\ProviderApplicationRuntimeInspector;
use PHPUnit\Framework\TestCase;

final class ProviderApplicationRuntimeCleanerTest extends TestCase
{
    private function digest(
        string $hex = 'a'
    ): string {
        return 'sha256:'
            . str_repeat($hex, 64);
    }

    private function record(): array
    {
        return [
            'container_id' =>
                'app-1-web',
            'view_key' =>
                'gw-system-view-app-1-web',
            'lease_id' =>
                'gw-system-lease-app-1-web',
            'image_ref' =>
                'registry.local/apps/1/web@'
                . $this->digest(),
        ];
    }

    private function state(
        bool $container = true,
        bool $task = true,
        bool $snapshot = true,
        bool $lease = true,
        string $taskState = 'RUNNING'
    ): array {
        return [
            'container' => $container,
            'task' => $task,
            'snapshot' => $snapshot,
            'lease' => $lease,
            'task_state' => $taskState,
            'wrong_image' => false,
        ];
    }

    private function inspector(
        array &$state
    ): ProviderApplicationRuntimeInspector {
        $record = $this->record();

        return new ProviderApplicationRuntimeInspector(
            function () use (
                &$state,
                $record
            ): array {
                if (!$state['container']) {
                    throw new ContainerdException(
                        'not found',
                        ContainerdRpcException::RPC_NOT_FOUND
                    );
                }

                return [
                    'image_ref' =>
                        $state['wrong_image']
                            ? 'registry.local/wrong@'
                                . $this->digest('c')
                            : $record['image_ref'],
                    'snapshot_key' =>
                        $record['view_key'],
                ];
            },
            function () use (
                &$state
            ): array {
                if (!$state['task']) {
                    throw new ContainerdException(
                        'not found',
                        ContainerdRpcException::RPC_NOT_FOUND
                    );
                }

                return [
                    'state' =>
                        $state['task_state'],
                    'pid' => 1234,
                ];
            },
            function () use (
                &$state
            ): array {
                if (!$state['snapshot']) {
                    throw new ContainerdException(
                        'not found',
                        ContainerdRpcException::RPC_NOT_FOUND
                    );
                }

                return [
                    'parent_snapshot' =>
                        $this->digest('b'),
                ];
            },
            function () use (
                &$state,
                $record
            ): ?array {
                return $state['lease']
                    ? [
                        'id' =>
                            $record['lease_id'],
                    ]
                    : null;
            }
        );
    }

    private function cleaner(
        array &$state,
        array &$events,
        bool $terminationFails = false,
        bool $leaseDeleteLeavesResidue = false
    ): ProviderApplicationRuntimeCleaner {
        return new ProviderApplicationRuntimeCleaner(
            $this->inspector(
                $state
            ),
            function (
                string $containerId
            ) use (
                &$state,
                &$events,
                $terminationFails
            ): void {
                $events[] = 'terminate';

                if ($terminationFails) {
                    throw new ContainerdException(
                        'termination failed'
                    );
                }

                $state['task_state'] =
                    'STOPPED';
            },
            function (
                string $containerId
            ) use (
                &$state,
                &$events
            ): void {
                $events[] = 'task';
                $state['task'] = false;
            },
            function (
                string $containerId
            ) use (
                &$state,
                &$events
            ): void {
                $events[] = 'container';
                $state['container'] = false;
            },
            function (
                string $viewKey
            ) use (
                &$state,
                &$events
            ): void {
                $events[] = 'snapshot';
                $state['snapshot'] = false;
            },
            function (
                string $leaseId
            ) use (
                &$state,
                &$events,
                $leaseDeleteLeavesResidue
            ): void {
                $events[] = 'lease';

                if (
                    !$leaseDeleteLeavesResidue
                ) {
                    $state['lease'] =
                        false;
                }
            }
        );
    }

    public function testAlreadyAbsentIsNoOp(): void
    {
        $state =
            $this->state(
                false,
                false,
                false,
                false
            );

        $events = [];

        $result =
            $this
                ->cleaner(
                    $state,
                    $events
                )
                ->cleanup(
                    $this->record()
                );

        self::assertSame(
            'ALREADY_ABSENT',
            $result['outcome']
        );

        self::assertSame(
            [],
            $events
        );
    }

    public function testRunningTaskCleanupUsesDependencyOrder(): void
    {
        $state =
            $this->state();

        $events = [];

        $result =
            $this
                ->cleaner(
                    $state,
                    $events
                )
                ->cleanup(
                    $this->record()
                );

        self::assertSame(
            'CLEANED',
            $result['outcome']
        );

        self::assertSame(
            [
                'terminate',
                'task',
                'container',
                'snapshot',
                'lease',
            ],
            $events
        );

        self::assertSame(
            'ABSENT',
            $result[
                'after'
            ]['runtime_state']
        );
    }

    public function testStoppedTaskSkipsTermination(): void
    {
        $state =
            $this->state(
                taskState: 'STOPPED'
            );

        $events = [];

        $this
            ->cleaner(
                $state,
                $events
            )
            ->cleanup(
                $this->record()
            );

        self::assertSame(
            [
                'task',
                'container',
                'snapshot',
                'lease',
            ],
            $events
        );
    }

    public function testPartialResidueRemovesOnlyPresentResources(): void
    {
        $state =
            $this->state(
                task: false
            );

        $events = [];

        $result =
            $this
                ->cleaner(
                    $state,
                    $events
                )
                ->cleanup(
                    $this->record()
                );

        self::assertSame(
            'RESIDUE',
            $result[
                'before'
            ]['runtime_state']
        );

        self::assertSame(
            [
                'container',
                'snapshot',
                'lease',
            ],
            $events
        );

        self::assertSame(
            'ABSENT',
            $result[
                'after'
            ]['runtime_state']
        );
    }

    public function testInconsistentOwnershipRefusesAllMutation(): void
    {
        $state =
            $this->state();

        $state['wrong_image'] = true;

        $events = [];

        try {
            $this
                ->cleaner(
                    $state,
                    $events
                )
                ->cleanup(
                    $this->record()
                );

            self::fail(
                'Expected inconsistent ownership refusal'
            );
        } catch (\RuntimeException $error) {
            self::assertStringContainsString(
                'ownership is inconsistent',
                $error->getMessage()
            );
        }

        self::assertSame(
            [],
            $events
        );

        self::assertTrue(
            $state['task']
        );

        self::assertTrue(
            $state['container']
        );

        self::assertTrue(
            $state['snapshot']
        );

        self::assertTrue(
            $state['lease']
        );
    }

    public function testTerminationFailurePreservesDependencies(): void
    {
        $state =
            $this->state();

        $events = [];

        try {
            $this
                ->cleaner(
                    $state,
                    $events,
                    terminationFails: true
                )
                ->cleanup(
                    $this->record()
                );

            self::fail(
                'Expected termination failure'
            );
        } catch (ContainerdException $error) {
            self::assertSame(
                'termination failed',
                $error->getMessage()
            );
        }

        self::assertSame(
            ['terminate'],
            $events
        );

        self::assertTrue(
            $state['task']
        );

        self::assertTrue(
            $state['container']
        );

        self::assertTrue(
            $state['snapshot']
        );

        self::assertTrue(
            $state['lease']
        );
    }

    public function testPostCleanupResidueFailsClosed(): void
    {
        $state =
            $this->state(
                taskState: 'STOPPED'
            );

        $events = [];

        try {
            $this
                ->cleaner(
                    $state,
                    $events,
                    leaseDeleteLeavesResidue:
                        true
                )
                ->cleanup(
                    $this->record()
                );

            self::fail(
                'Expected residue failure'
            );
        } catch (ContainerdException $error) {
            self::assertStringContainsString(
                'residue remains',
                $error->getMessage()
            );
        }

        self::assertSame(
            [
                'task',
                'container',
                'snapshot',
                'lease',
            ],
            $events
        );

        self::assertTrue(
            $state['lease']
        );
    }

    public function testUnobservedLeaseRefusesCleanup(): void
    {
        $record =
            $this->record();

        $missing =
            static function (): never {
                throw new ContainerdException(
                    'not found',
                    ContainerdRpcException::RPC_NOT_FOUND
                );
            };

        $inspector =
            new ProviderApplicationRuntimeInspector(
                $missing,
                $missing,
                $missing
            );

        $events = [];

        $cleaner =
            new ProviderApplicationRuntimeCleaner(
                $inspector,
                function () use (
                    &$events
                ): void {
                    $events[] = 'terminate';
                },
                function () use (
                    &$events
                ): void {
                    $events[] = 'task';
                },
                function () use (
                    &$events
                ): void {
                    $events[] = 'container';
                },
                function () use (
                    &$events
                ): void {
                    $events[] = 'snapshot';
                },
                function () use (
                    &$events
                ): void {
                    $events[] = 'lease';
                }
            );

        try {
            $cleaner->cleanup(
                $record
            );

            self::fail(
                'Expected lease observation refusal'
            );
        } catch (\RuntimeException $error) {
            self::assertStringContainsString(
                'lease ownership was not observed',
                $error->getMessage()
            );
        }

        self::assertSame(
            [],
            $events
        );
    }
}
