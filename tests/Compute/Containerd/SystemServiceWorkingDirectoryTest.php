<?php

declare(strict_types=1);

namespace Greenwood\Tests\Compute\Containerd;

use Greenwood\Compute\Containerd\SystemServiceDefinition;
use PHPUnit\Framework\TestCase;

final class SystemServiceWorkingDirectoryTest extends TestCase
{
    public function testRootIsDefault(): void
    {
        $definition =
            new SystemServiceDefinition(
                id: 'test-service',
                imageRef:
                    'example.invalid/app@sha256:'
                    . str_repeat('a', 64),
                command: ['/bin/true']
            );

        self::assertSame(
            '/',
            $definition->workingDirectory
        );
    }

    public function testAbsoluteWorkingDirectoryIsAccepted(): void
    {
        $definition =
            new SystemServiceDefinition(
                id: 'test-service',
                imageRef:
                    'example.invalid/app@sha256:'
                    . str_repeat('a', 64),
                command: ['/bin/true'],
                workingDirectory: '/app'
            );

        self::assertSame(
            '/app',
            $definition->workingDirectory
        );
    }

    public function testRelativeWorkingDirectoryIsRejected(): void
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        new SystemServiceDefinition(
            id: 'test-service',
            imageRef:
                'example.invalid/app@sha256:'
                . str_repeat('a', 64),
            command: ['/bin/true'],
            workingDirectory: 'app'
        );
    }
}
