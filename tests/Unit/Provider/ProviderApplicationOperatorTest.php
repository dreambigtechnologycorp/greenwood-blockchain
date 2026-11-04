<?php

declare(strict_types=1);

namespace Greenwood\Tests\Unit\Provider;

use Greenwood\Compute\Containerd\OciImageRuntimeConfig;
use Greenwood\Compute\Containerd\RegistryTransferAuth;
use Greenwood\Compute\Containerd\ResolvedImage;
use Greenwood\Compute\Containerd\SystemServiceDefinition;
use Greenwood\Compute\Containerd\SystemServiceHandle;
use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;
use Greenwood\Provider\ProviderApplicationOperator;
use Greenwood\Provider\ProviderApplicationReconciler;
use Greenwood\Provider\ProviderApplicationRegistry;
use Greenwood\Provider\ProviderApplicationRuntimeInspector;
use PHPUnit\Framework\TestCase;

final class ProviderApplicationOperatorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory =
            sys_get_temp_dir()
            . '/greenwood-app-operator-'
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

    private function image(
        string $hex = 'a'
    ): string {
        return 'registry.local/apps/42/web@'
            . $this->digest($hex);
    }

    private function serviceId(
        string $service = 'Web.API'
    ): string {
        return 'app-42-'
            . substr(
                hash(
                    'sha256',
                    $service
                ),
                0,
                48
            );
    }

    private function registry(): ProviderApplicationRegistry
    {
        return new ProviderApplicationRegistry(
            $this->directory
        );
    }

    private function absentInspector():
        ProviderApplicationRuntimeInspector
    {
        $missing =
            static function (): never {
                throw new ContainerdException(
                    'not found',
                    ContainerdRpcException::RPC_NOT_FOUND
                );
            };

        return new ProviderApplicationRuntimeInspector(
            $missing,
            $missing,
            $missing,
            static fn (): ?array => null
        );
    }

    private function runningInspector(
        string $imageRef,
        string $serviceId
    ): ProviderApplicationRuntimeInspector {
        return new ProviderApplicationRuntimeInspector(
            static fn (): array => [
                'image_ref' =>
                    $imageRef,
                'snapshot_key' =>
                    'gw-system-view-' . $serviceId,
            ],
            static fn (): array => [
                'state' => 'RUNNING',
                'pid' => 4321,
            ],
            fn (): array => [
                'parent_snapshot' =>
                    $this->digest('b'),
            ],
            static fn (): array => [
                'id' =>
                    'gw-system-lease-' . $serviceId,
            ]
        );
    }

    private function resolved(
        string $imageRef
    ): ResolvedImage {
        return new ResolvedImage(
            $imageRef,
            explode('@', $imageRef, 2)[0],
            substr(
                $imageRef,
                strrpos(
                    $imageRef,
                    '@'
                ) + 1
            ),
            'application/vnd.oci.image.manifest.v1+json',
            'linux/arm64'
        );
    }

    private function seedRunning(
        ProviderApplicationRegistry $registry,
        string $imageRef,
        string $service = 'Web.API'
    ): void {
        $serviceId =
            $this->serviceId(
                $service
            );

        $registry->beginStart(
            42,
            $service,
            $serviceId,
            $imageRef,
            $this->digest(
                substr(
                    $imageRef,
                    -1
                )
            )
        );

        $registry->markRunning(
            42,
            $service,
            $serviceId,
            'gw-system-view-' . $serviceId,
            $this->digest('b'),
            'gw-system-lease-' . $serviceId,
            4321
        );
    }

    public function testFirstInspectionProvesAbsenceWithoutCreatingAnIntent(): void
    {
        $registry = $this->registry();
        $inspector = $this->absentInspector();
        $never = static fn () => throw new \LogicException('No runtime mutation permitted');
        $operator = new ProviderApplicationOperator($registry,
            new ProviderApplicationReconciler($registry, $inspector), $never, $never, $never,
            inspectRuntime: $inspector->inspect(...));
        $result = $operator->inspect(42, 'web');
        self::assertNull($result['record']);
        self::assertSame('ABSENT', $result['runtime']['runtime_state']);
        self::assertFalse($result['runtime']['lease_present']);
        self::assertNull($registry->find(42, 'web'));
    }

    public function testMissingRecordWithOrphanedRuntimeDoesNotAuthorizeStart(): void
    {
        $registry = $this->registry();
        $inspector = $this->runningInspector($this->image(), $this->serviceId('web'));
        $never = static fn () => throw new \LogicException('No runtime mutation permitted');
        $operator = new ProviderApplicationOperator($registry,
            new ProviderApplicationReconciler($registry, $inspector), $never, $never, $never,
            inspectRuntime: $inspector->inspect(...));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unowned application resources require reconciliation');
        $operator->inspect(42, 'web');
    }

    public function testStartControlSurfaceHasNoExecutionFields(): void
    {
        $method =
            new \ReflectionMethod(
                ProviderApplicationOperator::class,
                'start'
            );

        $parameters =
            $method->getParameters();

        self::assertCount(
            4,
            $parameters
        );

        self::assertSame(
            [
                'applicationId',
                'service',
                'imageRef',
                'registryAuth',
            ],
            array_map(
                static fn (
                    \ReflectionParameter $parameter
                ): string =>
                    $parameter->getName(),
                $parameters
            )
        );

        self::assertSame(
            [
                'int',
                'string',
                'string',
                RegistryTransferAuth::class,
            ],
            array_map(
                static fn (
                    \ReflectionParameter $parameter
                ): string =>
                    (string) $parameter->getType(),
                $parameters
            )
        );
    }

    public function testFreshStartPersistsIntentAndUsesOciMetadata(): void
    {
        $registry =
            $this->registry();

        $reconciler =
            new ProviderApplicationReconciler(
                $registry,
                $this->absentInspector()
            );

        $imageRef =
            $this->image();

        $token =
            str_repeat('t', 32);

        $events = [];
        $captured = null;

        $operator =
            new ProviderApplicationOperator(
                $registry,
                $reconciler,
                function (
                    string $receivedImage,
                    RegistryTransferAuth $auth
                ) use (
                    $registry,
                    $imageRef,
                    &$events
                ): ResolvedImage {
                    self::assertSame(
                        $imageRef,
                        $receivedImage
                    );

                    self::assertSame(
                        'STARTING',
                        $registry
                            ->get(
                                42,
                                'Web.API'
                            )['state']
                    );

                    $events[] =
                        'prepare';

                    return $this->resolved(
                        $receivedImage
                    );
                },
                function (
                    string $receivedImage
                ) use (
                    $imageRef,
                    &$events
                ): OciImageRuntimeConfig {
                    self::assertSame(
                        $imageRef,
                        $receivedImage
                    );

                    $events[] =
                        'config';

                    return new OciImageRuntimeConfig(
                        command: [
                            'php',
                            'server.php',
                        ],
                        environment: [
                            'APP_MODE' =>
                                'production',
                        ],
                        workingDirectory:
                            '/srv/application',
                        exposedPorts: [
                            '8080/tcp',
                        ]
                    );
                },
                function (
                    SystemServiceDefinition $definition
                ) use (
                    &$events,
                    &$captured,
                    $imageRef
                ): SystemServiceHandle {
                    $events[] =
                        'start';

                    $captured =
                        $definition;

                    self::assertSame(
                        $imageRef,
                        $definition->imageRef
                    );

                    return new SystemServiceHandle(
                        serviceId:
                            $definition->id,
                        imageReference:
                            $definition->imageRef,
                        imageDigest:
                            $this->digest(),
                        containerId:
                            $definition->containerId(),
                        viewKey:
                            $definition->viewKey(),
                        parentSnapshot:
                            $this->digest('b'),
                        leaseId:
                            $definition->leaseId(),
                        pid: 4321
                    );
                }
            );

        $row =
            $operator->start(
                42,
                'Web.API',
                $imageRef,
                new RegistryTransferAuth(
                    $token,
                    'http'
                )
            );

        self::assertSame(
            [
                'prepare',
                'config',
                'start',
            ],
            $events
        );

        self::assertSame(
            'RUNNING',
            $row['state']
        );

        self::assertInstanceOf(
            SystemServiceDefinition::class,
            $captured
        );

        self::assertSame(
            $this->serviceId(),
            $captured->id
        );

        self::assertSame(
            [
                'php',
                'server.php',
            ],
            $captured->command
        );

        self::assertSame(
            [
                'APP_MODE' =>
                    'production',
            ],
            $captured->environment
        );

        self::assertSame(
            '/srv/application',
            $captured->workingDirectory
        );

        self::assertSame(
            [],
            $captured->mounts
        );

        self::assertSame(
            '65534:65534',
            $captured->user
        );

        self::assertFalse(
            $captured->hostNetwork
        );

        self::assertStringNotContainsString(
            $token,
            json_encode(
                $row,
                JSON_THROW_ON_ERROR
            )
        );
    }

    public function testConfirmedRunningServiceIsReconciledThenReturned(): void
    {
        $registry =
            $this->registry();

        $imageRef =
            $this->image();

        $serviceId =
            $this->serviceId();

        $this->seedRunning(
            $registry,
            $imageRef
        );

        $reconciler =
            new ProviderApplicationReconciler(
                $registry,
                $this->runningInspector(
                    $imageRef,
                    $serviceId
                )
            );

        $never =
            static function (): never {
                self::fail(
                    'Confirmed running service must not relaunch'
                );
            };

        $operator =
            new ProviderApplicationOperator(
                $registry,
                $reconciler,
                $never,
                $never,
                $never
            );

        $row =
            $operator->start(
                42,
                'Web.API',
                $imageRef,
                new RegistryTransferAuth(
                    str_repeat('t', 32),
                    'http'
                )
            );

        self::assertSame(
            'RUNNING',
            $row['state']
        );

        self::assertSame(
            4321,
            (int) $row['pid']
        );
    }

    public function testStaleRunningStateIsFailedThenRelaunched(): void
    {
        $registry =
            $this->registry();

        $imageRef =
            $this->image();

        $this->seedRunning(
            $registry,
            $imageRef
        );

        $reconciler =
            new ProviderApplicationReconciler(
                $registry,
                $this->absentInspector()
            );

        $events = [];

        $operator =
            new ProviderApplicationOperator(
                $registry,
                $reconciler,
                function (
                    string $receivedImage,
                    RegistryTransferAuth $auth
                ) use (
                    $registry,
                    &$events
                ): ResolvedImage {
                    self::assertSame(
                        'STARTING',
                        $registry
                            ->get(
                                42,
                                'Web.API'
                            )['state']
                    );

                    $events[] =
                        'prepare';

                    return $this->resolved(
                        $receivedImage
                    );
                },
                function () use (
                    &$events
                ): OciImageRuntimeConfig {
                    $events[] =
                        'config';

                    return new OciImageRuntimeConfig(
                        command: [
                            '/bin/sh',
                            '-c',
                            'true',
                        ],
                        environment: [],
                        workingDirectory: '/',
                        exposedPorts: []
                    );
                },
                function (
                    SystemServiceDefinition $definition
                ) use (
                    &$events
                ): SystemServiceHandle {
                    $events[] =
                        'start';

                    return new SystemServiceHandle(
                        serviceId:
                            $definition->id,
                        imageReference:
                            $definition->imageRef,
                        imageDigest:
                            $this->digest(),
                        containerId:
                            $definition->containerId(),
                        viewKey:
                            $definition->viewKey(),
                        parentSnapshot:
                            $this->digest('b'),
                        leaseId:
                            $definition->leaseId(),
                        pid: 9876
                    );
                }
            );

        $row =
            $operator->start(
                42,
                'Web.API',
                $imageRef,
                new RegistryTransferAuth(
                    str_repeat('t', 32),
                    'http'
                )
            );

        self::assertSame(
            [
                'prepare',
                'config',
                'start',
            ],
            $events
        );

        self::assertSame(
            'RUNNING',
            $row['state']
        );

        self::assertSame(
            9876,
            (int) $row['pid']
        );
    }

    public function testNetworkFailureCannotBeHiddenByRunningContainer(): void
    {
        $registry = $this->registry();
        $this->seedRunning($registry, $this->image(), 'isolated');
        $inspector = $this->runningInspector($this->image(), $this->serviceId('isolated'));
        $network = new \Greenwood\Provider\ProviderApplicationNetwork(
            $this->directory,
            new \Greenwood\Provider\ProviderApplicationBindingRegistry($this->directory, '10.254.16.0/28', 20000, 20003),
            static fn() => ['namespace_identity' => 'isolated'],
            static fn() => false,
            static fn() => false
        );
        $operator = new ProviderApplicationOperator($registry, new ProviderApplicationReconciler($registry, $inspector),
            static fn() => throw new \LogicException('No new image expected'),
            static fn() => new OciImageRuntimeConfig(['/app'], [], '/', ['8080/tcp']),
            static fn() => throw new \LogicException('No new runtime expected'),
            inspectRuntime: fn($r) => $inspector->inspect($r), network: $network);
        try {
            $operator->start(42, 'isolated', $this->image(), new RegistryTransferAuth(str_repeat('t', 32), 'http'));
            self::fail('Unhealthy runtime returned as success');
        } catch (\RuntimeException $e) { self::assertSame('Network health not confirmed', $e->getMessage()); }
        self::assertSame('RUNNING', $registry->get(42, 'isolated')['state']);
        self::assertFalse($operator->inspect(42, 'isolated')['network']['ready']);
        try { $operator->stop(42, 'isolated'); self::fail('Unconfirmed cleanup accepted'); }
        catch (\RuntimeException $e) { self::assertSame('Network cleanup not confirmed', $e->getMessage()); }
        self::assertSame('RUNNING', $registry->get(42, 'isolated')['state']);
        self::assertSame('CLEANUP_REQUIRED', $network->find(42, 'isolated')['state']);
    }

    public function testActiveDifferentImageIsRejectedBeforeMutation(): void
    {
        $registry =
            $this->registry();

        $registry->beginStart(
            42,
            'Web.API',
            $this->serviceId(),
            $this->image('a'),
            $this->digest('a')
        );

        $reconciler =
            new ProviderApplicationReconciler(
                $registry,
                $this->absentInspector()
            );

        $never =
            static function (): never {
                self::fail(
                    'Conflicting identity must not reach runtime'
                );
            };

        $operator =
            new ProviderApplicationOperator(
                $registry,
                $reconciler,
                $never,
                $never,
                $never
            );

        $this->expectException(
            \RuntimeException::class
        );

        $this->expectExceptionMessage(
            'already owns another runtime'
        );

        $operator->start(
            42,
            'Web.API',
            $this->image('b'),
            new RegistryTransferAuth(
                str_repeat('t', 32),
                'http'
            )
        );
    }
}
