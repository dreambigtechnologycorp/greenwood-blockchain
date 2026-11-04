<?php

declare(strict_types=1);

namespace Greenwood\Tests\Unit\Provider;

use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;
use Greenwood\Provider\ProviderApplicationReconciler;
use Greenwood\Provider\ProviderApplicationRegistry;
use Greenwood\Provider\ProviderApplicationRuntimeCleaner;
use Greenwood\Provider\ProviderApplicationRuntimeInspector;
use PHPUnit\Framework\TestCase;

final class ProviderApplicationRecoveryIntegrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory =
            sys_get_temp_dir()
            . '/greenwood-app-recovery-'
            . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (
            glob(
                $this->directory . '/*'
            ) ?: []
            as $file
        ) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    private function digest(
        string $hex = 'a'
    ): string {
        return 'sha256:'
            . str_repeat($hex, 64);
    }

    private function image(): string
    {
        return 'registry.local/apps/1/web@'
            . $this->digest();
    }

    private function recordState(
        string $state
    ): ProviderApplicationRegistry {
        $registry =
            new ProviderApplicationRegistry(
                $this->directory
            );

        $registry->beginStart(
            1,
            'web',
            'app-1-web',
            $this->image(),
            $this->digest()
        );

        if (
            in_array(
                $state,
                ['RUNNING', 'STOPPING'],
                true
            )
        ) {
            $registry->markRunning(
                1,
                'web',
                'app-1-web',
                'gw-system-view-app-1-web',
                $this->digest('b'),
                'gw-system-lease-app-1-web',
                1234
            );
        }

        if ($state === 'STOPPING') {
            $registry->markStopping(
                1,
                'web'
            );
        }

        return $registry;
    }

    private function runtime(
        array &$state,
        array &$events,
        bool $terminationFails = false
    ): array {
        $image = $this->image();

        $missing =
            static function (): never {
                throw new ContainerdException(
                    'not found',
                    ContainerdRpcException::RPC_NOT_FOUND
                );
            };

        $inspector =
            new ProviderApplicationRuntimeInspector(
                function () use (
                    &$state,
                    $image,
                    $missing
                ): array {
                    if (!$state['container']) {
                        return $missing();
                    }

                    return [
                        'image_ref' =>
                            $state['wrong_image']
                                ? 'registry.local/wrong@'
                                    . $this->digest('c')
                                : $image,
                        'snapshot_key' =>
                            'gw-system-view-app-1-web',
                    ];
                },
                function () use (
                    &$state,
                    $missing
                ): array {
                    if (!$state['task']) {
                        return $missing();
                    }

                    return [
                        'state' =>
                            $state['task_state'],
                        'pid' => 4321,
                    ];
                },
                function () use (
                    &$state,
                    $missing
                ): array {
                    if (!$state['snapshot']) {
                        return $missing();
                    }

                    return [
                        'parent_snapshot' =>
                            $this->digest('b'),
                    ];
                },
                function () use (
                    &$state
                ): ?array {
                    return $state['lease']
                        ? [
                            'id' =>
                                'gw-system-lease-app-1-web',
                        ]
                        : null;
                }
            );

        $cleaner =
            new ProviderApplicationRuntimeCleaner(
                $inspector,
                function () use (
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
                function () use (
                    &$state,
                    &$events
                ): void {
                    $events[] = 'task';
                    $state['task'] = false;
                },
                function () use (
                    &$state,
                    &$events
                ): void {
                    $events[] = 'container';
                    $state['container'] = false;
                },
                function () use (
                    &$state,
                    &$events
                ): void {
                    $events[] = 'snapshot';
                    $state['snapshot'] = false;
                },
                function () use (
                    &$state,
                    &$events
                ): void {
                    $events[] = 'lease';
                    $state['lease'] = false;
                }
            );

        return [
            $inspector,
            $cleaner,
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

    public function testStoppingRunningCleansThenStops(): void
    {
        $registry =
            $this->recordState(
                'STOPPING'
            );

        $state = $this->state();
        $events = [];

        [
            $inspector,
            $cleaner,
        ] = $this->runtime(
            $state,
            $events
        );

        $result =
            (new ProviderApplicationReconciler(
                $registry,
                $inspector,
                $cleaner
            ))->reconcile(
                1,
                'web'
            );

        self::assertSame(
            'CLEANED_STOPPED',
            $result['outcome']
        );

        self::assertSame(
            'STOPPED',
            $result['record']['state']
        );

        self::assertSame(
            'ABSENT',
            $result['runtime']['runtime_state']
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
    }

    public function testRunningResidueCleansThenFails(): void
    {
        $registry =
            $this->recordState(
                'RUNNING'
            );

        $state =
            $this->state(
                task: false
            );

        $events = [];

        [
            $inspector,
            $cleaner,
        ] = $this->runtime(
            $state,
            $events
        );

        $result =
            (new ProviderApplicationReconciler(
                $registry,
                $inspector,
                $cleaner
            ))->reconcile(
                1,
                'web'
            );

        self::assertSame(
            'CLEANED_FAILED',
            $result['outcome']
        );

        self::assertSame(
            'FAILED',
            $result['record']['state']
        );

        self::assertSame(
            [
                'container',
                'snapshot',
                'lease',
            ],
            $events
        );
    }

    public function testStartingStoppedCleansThenFails(): void
    {
        $registry =
            $this->recordState(
                'STARTING'
            );

        $state =
            $this->state(
                taskState: 'STOPPED'
            );

        $events = [];

        [
            $inspector,
            $cleaner,
        ] = $this->runtime(
            $state,
            $events
        );

        $result =
            (new ProviderApplicationReconciler(
                $registry,
                $inspector,
                $cleaner
            ))->reconcile(
                1,
                'web'
            );

        self::assertSame(
            'CLEANED_FAILED',
            $result['outcome']
        );

        self::assertSame(
            'FAILED',
            $result['record']['state']
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

    public function testCleanupFailurePreservesStoppingState(): void
    {
        $registry =
            $this->recordState(
                'STOPPING'
            );

        $state = $this->state();
        $events = [];

        [
            $inspector,
            $cleaner,
        ] = $this->runtime(
            $state,
            $events,
            terminationFails: true
        );

        try {
            (new ProviderApplicationReconciler(
                $registry,
                $inspector,
                $cleaner
            ))->reconcile(
                1,
                'web'
            );

            self::fail(
                'Expected cleanup failure'
            );
        } catch (ContainerdException $error) {
            self::assertSame(
                'termination failed',
                $error->getMessage()
            );
        }

        self::assertSame(
            'STOPPING',
            $registry
                ->get(
                    1,
                    'web'
                )['state']
        );

        self::assertSame(
            ['terminate'],
            $events
        );
    }

    public function testInconsistentOwnershipNeverMutates(): void
    {
        $registry =
            $this->recordState(
                'RUNNING'
            );

        $state = $this->state();
        $state['wrong_image'] = true;

        $events = [];

        [
            $inspector,
            $cleaner,
        ] = $this->runtime(
            $state,
            $events
        );

        $result =
            (new ProviderApplicationReconciler(
                $registry,
                $inspector,
                $cleaner
            ))->reconcile(
                1,
                'web'
            );

        self::assertSame(
            'RECOVERY_REQUIRED',
            $result['outcome']
        );

        self::assertSame(
            'RUNNING',
            $result['record']['state']
        );

        self::assertSame(
            'INCONSISTENT',
            $result['runtime']['runtime_state']
        );

        self::assertSame(
            [],
            $events
        );
    }
}
