<?php

declare(strict_types=1);

namespace Greenwood\Tests\Compute\Containerd;

use Greenwood\Compute\Containerd\OciSpecBuilder;
use PHPUnit\Framework\TestCase;

final class OciSpecBuilderWorkingDirectoryTest extends TestCase
{
    public function testDefaultWorkingDirectoryIsRoot(): void
    {
        $spec = json_decode(
            (new OciSpecBuilder('rootfs'))
                ->buildJson(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(
            '/',
            $spec['process']['cwd']
        );
    }

    public function testWorkingDirectoryCanBeSetFromOciMetadata(): void
    {
        $spec = json_decode(
            (new OciSpecBuilder('rootfs'))
                ->setWorkingDirectory('/app')
                ->buildJson(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(
            '/app',
            $spec['process']['cwd']
        );
    }

    public function testRelativeWorkingDirectoryIsRejected(): void
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        (new OciSpecBuilder('rootfs'))
            ->setWorkingDirectory('app');
    }

    public function testEmptyWorkingDirectoryIsRejected(): void
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        (new OciSpecBuilder('rootfs'))
            ->setWorkingDirectory('');
    }
}
