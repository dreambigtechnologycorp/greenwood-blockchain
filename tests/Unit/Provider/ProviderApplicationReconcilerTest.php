<?php

declare(strict_types=1);

namespace Greenwood\Tests\Unit\Provider;

use Greenwood\Provider\ProviderApplicationReconciler;
use Greenwood\Provider\ProviderApplicationRegistry;
use Greenwood\Provider\ProviderApplicationRuntimeInspector;
use PHPUnit\Framework\TestCase;

final class ProviderApplicationReconcilerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory =
            sys_get_temp_dir()
            . '/greenwood-app-reconcile-'
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

    private function registry(
        string $state = 'STARTING'
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

        if ($state !== 'STARTING') {
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

    private function runningInspector():
        ProviderApplicationRuntimeInspector
    {
        return new ProviderApplicationRuntimeInspector(
            fn () => [
                'image_ref' =>
                    $this->image(),
                'snapshot_key' =>
                    'gw-system-view-app-1-web',
            ],
            fn () => [
                'state' => 'RUNNING',
                'pid' => 4321,
            ],
            fn () => [
                'parent_snapshot' =>
                    $this->digest('b'),
            ]
        );
    }

    private function absentInspector():
        ProviderApplicationRuntimeInspector
    {
        $missing =
            static function (): never {
                throw new \Greenwood\Contracts\ContainerdException(
                    'not found',
                    \Greenwood\Contracts\ContainerdRpcException::RPC_NOT_FOUND
                );
            };

        return new ProviderApplicationRuntimeInspector(
            $missing,
            $missing,
            $missing
        );
    }

    public function testStartingRunningBecomesRunning(): void
    {
        $registry =
            $this->registry();

        $result =
            (new ProviderApplicationReconciler(
                $registry,
                $this->runningInspector()
            ))->reconcile(
                1,
                'web'
            );

        self::assertSame(
            'RECOVERED_RUNNING',
            $result['outcome']
        );

        self::assertSame(
            'RUNNING',
            $result['record']['state']
        );

        self::assertSame(
            4321,
            (int) $result[
                'record'
            ]['pid']
        );
    }

    public function testStartingAbsentBecomesFailed(): void
    {
        $registry =
            $this->registry();

        $result =
            (new ProviderApplicationReconciler(
                $registry,
                $this->absentInspector()
            ))->reconcile(
                1,
                'web'
            );

        self::assertSame(
            'CONFIRMED_ABSENT',
            $result['outcome']
        );

        self::assertSame(
            'FAILED',
            $result['record']['state']
        );
    }

    public function testRunningAbsentBecomesFailed(): void
    {
        $registry =
            $this->registry(
                'RUNNING'
            );

        $result =
            (new ProviderApplicationReconciler(
                $registry,
                $this->absentInspector()
            ))->reconcile(
                1,
                'web'
            );

        self::assertSame(
            'FAILED',
            $result['record']['state']
        );
    }

    public function testStoppingAbsentBecomesStopped(): void
    {
        $registry =
            $this->registry(
                'STOPPING'
            );

        $result =
            (new ProviderApplicationReconciler(
                $registry,
                $this->absentInspector()
            ))->reconcile(
                1,
                'web'
            );

        self::assertSame(
            'CONFIRMED_STOPPED',
            $result['outcome']
        );

        self::assertSame(
            'STOPPED',
            $result['record']['state']
        );
    }

    public function testRunningRunningStaysRunning(): void
    {
        $registry =
            $this->registry(
                'RUNNING'
            );

        $result =
            (new ProviderApplicationReconciler(
                $registry,
                $this->runningInspector()
            ))->reconcile(
                1,
                'web'
            );

        self::assertSame(
            'UNCHANGED',
            $result['outcome']
        );

        self::assertSame(
            'RUNNING',
            $result['record']['state']
        );
    }

    public function testResidueRetainsOwnership(): void
    {
        $registry =
            $this->registry(
                'RUNNING'
            );

        $inspector =
            new ProviderApplicationRuntimeInspector(
                fn () => [
                    'image_ref' =>
                        $this->image(),
                    'snapshot_key' =>
                        'gw-system-view-app-1-web',
                ],
                function (): never {
                    throw new \Greenwood\Contracts\ContainerdException(
                        'not found',
                        \Greenwood\Contracts\ContainerdRpcException::RPC_NOT_FOUND
                    );
                },
                fn () => [
                    'parent_snapshot' =>
                        $this->digest('b'),
                ]
            );

        $result =
            (new ProviderApplicationReconciler(
                $registry,
                $inspector
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
            'RESIDUE',
            $result[
                'runtime'
            ]['runtime_state']
        );
    }

    public function testStoppedTaskRetainsOwnershipForCleanup(): void
    {
        $registry =
            $this->registry(
                'STOPPING'
            );

        $inspector =
            new ProviderApplicationRuntimeInspector(
                fn () => [
                    'image_ref' =>
                        $this->image(),
                    'snapshot_key' =>
                        'gw-system-view-app-1-web',
                ],
                fn () => [
                    'state' => 'STOPPED',
                    'pid' => 1234,
                ],
                fn () => [
                    'parent_snapshot' =>
                        $this->digest('b'),
                ]
            );

        $result =
            (new ProviderApplicationReconciler(
                $registry,
                $inspector
            ))->reconcile(
                1,
                'web'
            );

        self::assertSame(
            'RECOVERY_REQUIRED',
            $result['outcome']
        );

        self::assertSame(
            'STOPPING',
            $result['record']['state']
        );
    }
}
