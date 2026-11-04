<?php

declare(strict_types=1);

namespace Greenwood\Tests\Compute\Containerd;

use Greenwood\Compute\Containerd\OciImageRuntimeConfig;
use PHPUnit\Framework\TestCase;

final class OciImageRuntimeConfigTest extends TestCase
{
    public function testDerivesRuntimeFromImageMetadata(): void
    {
        $runtime =
            OciImageRuntimeConfig::fromDocument([
                'config' => [
                    'Entrypoint' => ['/usr/bin/example'],
                    'Cmd' => ['serve', '--port', '8080'],
                    'Env' => [
                        'PATH=/usr/bin:/bin',
                        'MODE=production',
                    ],
                    'WorkingDir' => '/app',
                    'ExposedPorts' => [
                        '8080/tcp' => new \stdClass(),
                    ],
                ],
            ]);

        self::assertSame(
            [
                '/usr/bin/example',
                'serve',
                '--port',
                '8080',
            ],
            $runtime->command
        );

        self::assertSame(
            [
                'PATH' => '/usr/bin:/bin',
                'MODE' => 'production',
            ],
            $runtime->environment
        );

        self::assertSame(
            '/app',
            $runtime->workingDirectory
        );

        self::assertSame(
            ['8080/tcp'],
            $runtime->exposedPorts
        );
    }

    public function testB13ShapeUsesCmdWithoutEntrypoint(): void
    {
        $runtime =
            OciImageRuntimeConfig::fromDocument([
                'config' => [
                    'Cmd' => [
                        '/bin/sh',
                        '-c',
                        'example',
                    ],
                    'Env' => [
                        'PATH=/usr/local/bin:/usr/bin:/bin',
                    ],
                    'WorkingDir' => '/',
                    'ExposedPorts' => [
                        '18088/tcp' => new \stdClass(),
                    ],
                ],
            ]);

        self::assertSame(
            ['/bin/sh', '-c', 'example'],
            $runtime->command
        );

        self::assertSame(
            ['18088/tcp'],
            $runtime->exposedPorts
        );
    }

    public function testImageWithoutProcessDefinitionIsRejected(): void
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        OciImageRuntimeConfig::fromDocument([
            'config' => [],
        ]);
    }

    public function testRelativeWorkingDirectoryIsRejected(): void
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        OciImageRuntimeConfig::fromDocument([
            'config' => [
                'Cmd' => ['/bin/true'],
                'WorkingDir' => 'app',
            ],
        ]);
    }

    public function testInvalidPortIsRejected(): void
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        OciImageRuntimeConfig::fromDocument([
            'config' => [
                'Cmd' => ['/bin/true'],
                'ExposedPorts' => [
                    '70000/tcp' => new \stdClass(),
                ],
            ],
        ]);
    }
}
