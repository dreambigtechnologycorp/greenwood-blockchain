<?php

declare(strict_types=1);

namespace Greenwood\Tests\Unit\Provider;

use Greenwood\Provider\ProviderApplicationRegistry;
use PHPUnit\Framework\TestCase;

final class ProviderApplicationRegistryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory =
            sys_get_temp_dir()
            . '/greenwood-app-registry-'
            . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

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

    private function image(
        string $hex = 'a'
    ): string {
        $digest = $this->digest($hex);

        return 'registry.local/apps/1/web@'
            . $digest;
    }

    public function testStartIntentIsDurableBeforeRuntimeHandle(): void
    {
        $registry =
            new ProviderApplicationRegistry(
                $this->directory
            );

        $row =
            $registry->beginStart(
                1,
                'web',
                'app-1-web',
                $this->image(),
                $this->digest()
            );

        self::assertSame(
            'STARTING',
            $row['state']
        );

        self::assertSame(
            'app-1-web',
            $row['container_id']
        );

        self::assertSame(
            'gw-system-view-app-1-web',
            $row['view_key']
        );

        self::assertSame(
            'gw-system-lease-app-1-web',
            $row['lease_id']
        );

        self::assertNull(
            $row['parent_snapshot']
        );

        self::assertNull(
            $row['pid']
        );

        unset($registry);

        $reopened =
            new ProviderApplicationRegistry(
                $this->directory
            );

        self::assertSame(
            'STARTING',
            $reopened
                ->get(1, 'web')['state']
        );
    }

    public function testSameStartIsIdempotent(): void
    {
        $registry =
            new ProviderApplicationRegistry(
                $this->directory
            );

        $first =
            $registry->beginStart(
                1,
                'web',
                'app-1-web',
                $this->image(),
                $this->digest()
            );

        $second =
            $registry->beginStart(
                1,
                'web',
                'app-1-web',
                $this->image(),
                $this->digest()
            );

        self::assertSame(
            $first['created_at'],
            $second['created_at']
        );

        self::assertSame(
            'STARTING',
            $second['state']
        );
    }

    public function testDifferentDigestConflictsWhileActive(): void
    {
        $registry =
            new ProviderApplicationRegistry(
                $this->directory
            );

        $registry->beginStart(
            1,
            'web',
            'app-1-web',
            $this->image('a'),
            $this->digest('a')
        );

        $this->expectException(
            \RuntimeException::class
        );

        $registry->beginStart(
            1,
            'web',
            'app-1-web',
            $this->image('b'),
            $this->digest('b')
        );
    }

    public function testRunningHandleIsPersisted(): void
    {
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

        $row =
            $registry->markRunning(
                1,
                'web',
                'app-1-web',
                'gw-system-view-app-1-web',
                $this->digest('c'),
                'gw-system-lease-app-1-web',
                1234
            );

        self::assertSame(
            'RUNNING',
            $row['state']
        );

        self::assertSame(
            'app-1-web',
            $row['container_id']
        );

        self::assertSame(
            'gw-system-lease-app-1-web',
            $row['lease_id']
        );
    }

    public function testStopLifecycleIsDurable(): void
    {
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

        $registry->markRunning(
            1,
            'web',
            'app-1-web',
            'gw-system-view-app-1-web',
            $this->digest('c'),
            'gw-system-lease-app-1-web',
            1234
        );

        self::assertSame(
            'STOPPING',
            $registry
                ->markStopping(
                    1,
                    'web'
                )['state']
        );

        self::assertSame(
            'STOPPED',
            $registry
                ->markStopped(
                    1,
                    'web'
                )['state']
        );
    }

    public function testRunningServiceCanBeMarkedFailedByReconciliation(): void
    {
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

        $registry->markRunning(
            1,
            'web',
            'app-1-web',
            'gw-system-view-app-1-web',
            $this->digest('c'),
            'gw-system-lease-app-1-web',
            1234
        );

        $row =
            $registry->markFailed(
                1,
                'web'
            );

        self::assertSame(
            'FAILED',
            $row['state']
        );
    }

    public function testStoppedServiceCanStartNewDigest(): void
    {
        $registry =
            new ProviderApplicationRegistry(
                $this->directory
            );

        $registry->beginStart(
            1,
            'web',
            'app-1-web',
            $this->image('a'),
            $this->digest('a')
        );

        $registry->markRunning(
            1,
            'web',
            'app-1-web',
            'gw-system-view-app-1-web',
            $this->digest('c'),
            'gw-system-lease-app-1-web',
            1234
        );

        $registry->markStopping(
            1,
            'web'
        );

        $registry->markStopped(
            1,
            'web'
        );

        $row =
            $registry->beginStart(
                1,
                'web',
                'app-1-web',
                $this->image('b'),
                $this->digest('b')
            );

        self::assertSame(
            'STARTING',
            $row['state']
        );

        self::assertSame(
            $this->digest('b'),
            $row['image_digest']
        );

        self::assertSame(
            'app-1-web',
            $row['container_id']
        );

        self::assertSame(
            'gw-system-view-app-1-web',
            $row['view_key']
        );

        self::assertSame(
            'gw-system-lease-app-1-web',
            $row['lease_id']
        );

        self::assertNull(
            $row['parent_snapshot']
        );

        self::assertNull(
            $row['pid']
        );
    }
}
