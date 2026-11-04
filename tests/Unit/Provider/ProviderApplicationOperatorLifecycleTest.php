<?php

declare(strict_types=1);

namespace Greenwood\Tests\Unit\Provider;

use Greenwood\Compute\Containerd\RegistryTransferAuth;
use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;
use Greenwood\Provider\ProviderApplicationOperator;
use Greenwood\Provider\ProviderApplicationReconciler;
use Greenwood\Provider\ProviderApplicationRegistry;
use Greenwood\Provider\ProviderApplicationRuntimeCleaner;
use Greenwood\Provider\ProviderApplicationRuntimeInspector;
use PHPUnit\Framework\TestCase;

final class ProviderApplicationOperatorLifecycleTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory =
            sys_get_temp_dir()
            . '/greenwood-app-operator-lifecycle-'
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
        return 'registry.local/apps/42/web@'
            . $this->digest();
    }

    private function serviceId(): string
    {
        return 'app-42-'
            . substr(
                hash(
                    'sha256',
                    'Web.API'
                ),
                0,
                48
            );
    }

    private function registry():
        ProviderApplicationRegistry
    {
        return new ProviderApplicationRegistry(
            $this->directory
        );
    }

    private function seedRunning(
        ProviderApplicationRegistry $registry
    ): void {
        $serviceId =
            $this->serviceId();

        $registry->beginStart(
            42,
            'Web.API',
            $serviceId,
            $this->image(),
            $this->digest()
        );

        $registry->markRunning(
            42,
            'Web.API',
            $serviceId,
            'gw-system-view-' . $serviceId,
            $this->digest('b'),
            'gw-system-lease-' . $serviceId,
            4321
        );
    }

    private function state(): array
    {
        return [
            'container' => true,
            'task' => true,
            'snapshot' => true,
            'lease' => true,
            'task_state' => 'RUNNING',
            'wrong_image' => false,
        ];
    }

    private function inspector(
        array &$state
    ): ProviderApplicationRuntimeInspector {
        $imageRef =
            $this->image();

        $serviceId =
            $this->serviceId();

        $missing =
            static function (): never {
                throw new ContainerdException(
                    'not found',
                    ContainerdRpcException::RPC_NOT_FOUND
                );
            };

        return new ProviderApplicationRuntimeInspector(
            function () use (
                &$state,
                $imageRef,
                $serviceId,
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
                            : $imageRef,
                    'snapshot_key' =>
                        'gw-system-view-' . $serviceId,
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
                &$state,
                $serviceId
            ): ?array {
                return $state['lease']
                    ? [
                        'id' =>
                            'gw-system-lease-'
                            . $serviceId,
                    ]
                    : null;
            }
        );
    }

    private function cleaner(
        ProviderApplicationRuntimeInspector $inspector,
        array &$state,
        array &$events
    ): ProviderApplicationRuntimeCleaner {
        return new ProviderApplicationRuntimeCleaner(
            $inspector,
            function () use (
                &$state,
                &$events
            ): void {
                $events[] = 'terminate';
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
    }

    private function operator(
        ProviderApplicationRegistry $registry,
        ProviderApplicationReconciler $reconciler,
        ProviderApplicationRuntimeInspector $inspector
    ): ProviderApplicationOperator {
        $never =
            static function (): never {
                self::fail(
                    'Lifecycle operation must not enter start path'
                );
            };

        return new ProviderApplicationOperator(
            $registry,
            $reconciler,
            $never,
            $never,
            $never,
            inspectRuntime:
                static fn (
                    array $record
                ): array =>
                    $inspector->inspect(
                        $record
                    )
        );
    }

    public function testInspectIsReadOnlyAndReportsRuntime(): void
    {
        $registry =
            $this->registry();

        $this->seedRunning(
            $registry
        );

        $state =
            $this->state();

        $inspector =
            $this->inspector(
                $state
            );

        $operator =
            $this->operator(
                $registry,
                new ProviderApplicationReconciler(
                    $registry,
                    $inspector
                ),
                $inspector
            );

        $result =
            $operator->inspect(
                42,
                'Web.API'
            );

        self::assertSame(
            'RUNNING',
            $result['record']['state']
        );

        self::assertSame(
            'RUNNING',
            $result[
                'runtime'
            ]['runtime_state']
        );

        self::assertSame(
            'RUNNING',
            $registry
                ->get(
                    42,
                    'Web.API'
                )['state']
        );
    }

    public function testStopCleansOwnedRuntimeThenPersistsStopped(): void
    {
        $registry =
            $this->registry();

        $this->seedRunning(
            $registry
        );

        $state =
            $this->state();

        $events = [];

        $inspector =
            $this->inspector(
                $state
            );

        $cleaner =
            $this->cleaner(
                $inspector,
                $state,
                $events
            );

        $reconciler =
            new ProviderApplicationReconciler(
                $registry,
                $inspector,
                $cleaner
            );

        $operator =
            $this->operator(
                $registry,
                $reconciler,
                $inspector
            );

        $result =
            $operator->stop(
                42,
                'Web.API'
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
            $result[
                'runtime'
            ]['runtime_state']
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
            'STOPPED',
            $registry
                ->get(
                    42,
                    'Web.API'
                )['state']
        );
    }

    public function testInconsistentRuntimeRefusesStopWithoutStateMutation(): void
    {
        $registry =
            $this->registry();

        $this->seedRunning(
            $registry
        );

        $state =
            $this->state();

        $state['wrong_image'] =
            true;

        $events = [];

        $inspector =
            $this->inspector(
                $state
            );

        $cleaner =
            $this->cleaner(
                $inspector,
                $state,
                $events
            );

        $operator =
            $this->operator(
                $registry,
                new ProviderApplicationReconciler(
                    $registry,
                    $inspector,
                    $cleaner
                ),
                $inspector
            );

        try {
            $operator->stop(
                42,
                'Web.API'
            );

            self::fail(
                'Expected recovery refusal'
            );
        } catch (\RuntimeException $error) {
            self::assertSame(
                'Application stop requires recovery',
                $error->getMessage()
            );
        }

        self::assertSame(
            'RUNNING',
            $registry
                ->get(
                    42,
                    'Web.API'
                )['state']
        );

        self::assertSame(
            [],
            $events
        );
    }

    public function testStopIsIdempotentWhenAlreadyStopped(): void
    {
        $registry =
            $this->registry();

        $this->seedRunning(
            $registry
        );

        $registry->markStopping(
            42,
            'Web.API'
        );

        $registry->markStopped(
            42,
            'Web.API'
        );

        $state = [
            'container' => false,
            'task' => false,
            'snapshot' => false,
            'lease' => false,
            'task_state' => 'STOPPED',
            'wrong_image' => false,
        ];

        $inspector =
            $this->inspector(
                $state
            );

        $operator =
            $this->operator(
                $registry,
                new ProviderApplicationReconciler(
                    $registry,
                    $inspector
                ),
                $inspector
            );

        $result =
            $operator->stop(
                42,
                'Web.API'
            );

        self::assertSame(
            'ALREADY_INACTIVE',
            $result['outcome']
        );

        self::assertSame(
            'STOPPED',
            $result['record']['state']
        );
    }
}
