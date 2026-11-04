<?php

declare(strict_types=1);

namespace Greenwood\Tests\Compute\Containerd;

use Greenwood\Compute\Containerd\SystemServiceDefinition;
use PHPUnit\Framework\TestCase;

final class SystemServiceDefinitionOciCommandTest extends TestCase
{
    public function testBareOciExecutableIsAccepted(): void
    {
        $definition =
            new SystemServiceDefinition(
                id: 'oci-bare-command',
                imageRef:
                    'registry.local/apps/1/web@sha256:'
                    . str_repeat('a', 64),
                command: [
                    'nginx',
                    '-g',
                    'daemon off;',
                ],
                environment: [
                    'PATH' =>
                        '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                ],
                mounts: [],
                user: '0:0',
                hostNetwork: false,
                workingDirectory: '/'
            );

        self::assertSame(
            'nginx',
            $definition->command[0]
        );
    }

    public function testAbsoluteOciExecutableRemainsAccepted(): void
    {
        $definition =
            new SystemServiceDefinition(
                id: 'oci-absolute-command',
                imageRef:
                    'registry.local/apps/1/web@sha256:'
                    . str_repeat('a', 64),
                command: [
                    '/bin/sh',
                    '-c',
                    'true',
                ],
                hostNetwork: false
            );

        self::assertSame(
            '/bin/sh',
            $definition->command[0]
        );
    }

    public function testCommandArgumentContainingNulIsRejected(): void
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        new SystemServiceDefinition(
            id: 'oci-invalid-command',
            imageRef:
                'registry.local/apps/1/web@sha256:'
                . str_repeat('a', 64),
            command: [
                "nginx\0evil",
            ],
            hostNetwork: false
        );
    }
}
