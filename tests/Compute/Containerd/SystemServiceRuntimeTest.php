<?php

declare(strict_types=1);

namespace Greenwood\Tests\Compute\Containerd;

use Containerd\Services\Containers\V1\Container;
use Containerd\Services\Images\V1\Image;
use Containerd\Services\Tasks\V1\CreateTaskResponse;
use Containerd\Services\Tasks\V1\StartResponse;
use Containerd\Services\Tasks\V1\WaitResponse;
use Containerd\Types\Mount;
use Google\Protobuf\Any;
use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\ResolvedImage;
use Greenwood\Compute\Containerd\SystemServiceDefinition;
use Greenwood\Compute\Containerd\SystemServiceHandle;
use Greenwood\Compute\Containerd\SystemServiceRuntime;
use Greenwood\Compute\Containerd\Service\ContainerService;
use Greenwood\Compute\Containerd\Service\ImagePreparationService;
use Greenwood\Compute\Containerd\Service\ImageRootfsResolver;
use Greenwood\Compute\Containerd\Service\ImageService;
use Greenwood\Compute\Containerd\Service\LeaseService;
use Greenwood\Compute\Containerd\Service\SnapshotService;
use Greenwood\Compute\Containerd\Service\TaskService;
use Greenwood\Compute\Containerd\ValueObject\PreparedSnapshot;
use PHPUnit\Framework\TestCase;

final class SystemServiceRuntimeTest extends TestCase
{
    public function testDefinitionUsesDeterministicRuntimeIdentity(): void
    {
        $definition = new SystemServiceDefinition(
            id: 'greenwood-registry-auth',
            imageRef:
                'docker.io/greenwood/system-registry-auth:b13c5',
            command: [
                '/usr/local/bin/php',
                '-S',
                '0.0.0.0:8080',
                '-t',
                '/srv/greenwood-registry-auth/public',
                '/srv/greenwood-registry-auth/public/index.php',
            ],
            environment: [
                'GREENWOOD_CHAIN_ID' =>
                    'greenwood-physical-v1',
            ]
        );

        self::assertSame(
            'greenwood-registry-auth',
            $definition->containerId()
        );

        self::assertSame(
            'gw-system-view-greenwood-registry-auth',
            $definition->viewKey()
        );

        self::assertSame(
            'gw-system-lease-greenwood-registry-auth',
            $definition->leaseId()
        );

        self::assertContains(
            'GREENWOOD_CHAIN_ID=greenwood-physical-v1',
            $definition->environmentList()
        );

        self::assertStringStartsWith(
            'PATH=',
            $definition->environmentList()[0]
        );
    }

    public function testDefinitionAcceptsBareOciExecutable(): void
    {
        $definition =
            new SystemServiceDefinition(
                id: 'oci-path-service',
                imageRef: 'example.invalid/image:test',
                command: [
                    'php',
                    '-v',
                ]
            );

        self::assertSame(
            [
                'php',
                '-v',
            ],
            $definition->command
        );
    }

    public function testStartBuildsLongRunningHostNetworkViewRuntime(): void
    {
        $clients = $this->clientSet();

        $resolved = $this->resolvedImage();

        $image = new Image();

        $mount = new Mount();
        $mount->setType('bind');
        $mount->setSource('/readonly-rootfs');
        $mount->setOptions(['ro']);

        $prepared = new PreparedSnapshot(
            'gw-system-view-test-service',
            'erofs',
            [$mount]
        );

        $preparation =
            $this->createMock(
                ImagePreparationService::class
            );

        $images =
            $this->createMock(
                ImageService::class
            );

        $rootfs =
            $this->createMock(
                ImageRootfsResolver::class
            );

        $snapshots =
            $this->createMock(
                SnapshotService::class
            );

        $leases =
            $this->createMock(
                LeaseService::class
            );

        $containers =
            $this->createMock(
                ContainerService::class
            );

        $tasks =
            $this->createMock(
                TaskService::class
            );

        $preparation
            ->expects(self::once())
            ->method('ensureReady')
            ->with(
                'docker.io/greenwood/test-service:v1'
            )
            ->willReturn($resolved);

        $images
            ->expects(self::once())
            ->method('get')
            ->with(
                'docker.io/greenwood/test-service:v1'
            )
            ->willReturn($image);

        $leases
            ->expects(self::once())
            ->method('create')
            ->with(
                'gw-system-lease-test-service'
            );

        $rootfs
            ->expects(self::once())
            ->method('resolve')
            ->with($image)
            ->willReturn(
                'sha256:'
                . str_repeat('b', 64)
            );

        $snapshots
            ->expects(self::once())
            ->method('view')
            ->with(
                'gw-system-view-test-service',
                'sha256:'
                . str_repeat('b', 64),
                'gw-system-lease-test-service'
            )
            ->willReturn($prepared);

        $capturedSpec = null;

        $containers
            ->expects(self::once())
            ->method('buildContainer')
            ->willReturnCallback(
                function (
                    string $id,
                    ResolvedImage $image,
                    string $snapshotKey,
                    Any $spec
                ) use (&$capturedSpec): Container {
                    self::assertSame(
                        'test-service',
                        $id
                    );

                    self::assertSame(
                        'gw-system-view-test-service',
                        $snapshotKey
                    );

                    self::assertSame(
                        'sha256:'
                        . str_repeat('a', 64),
                        $image->getDigest()
                    );

                    $capturedSpec =
                        json_decode(
                            $spec->getValue(),
                            true,
                            512,
                            JSON_THROW_ON_ERROR
                        );

                    $container = new Container();
                    $container->setId($id);

                    return $container;
                }
            );

        $containers
            ->expects(self::once())
            ->method('create')
            ->with(
                self::callback(
                    static fn (Container $container): bool =>
                        $container->getId()
                        === 'test-service'
                )
            );

        $createResponse =
            new CreateTaskResponse();

        $createResponse->setPid(4242);

        $tasks
            ->expects(self::once())
            ->method('create')
            ->with(
                'test-service',
                '',
                $prepared
            )
            ->willReturn(
                $createResponse
            );

        $tasks
            ->expects(self::once())
            ->method('start')
            ->with(
                'test-service',
                ''
            )
            ->willReturn(
                new StartResponse()
            );

        $runtime = new SystemServiceRuntime(
            clients: $clients,
            preparationService: $preparation,
            imageService: $images,
            rootfsResolver: $rootfs,
            snapshotService: $snapshots,
            leaseService: $leases,
            containerService: $containers,
            taskService: $tasks
        );

        $definition =
            new SystemServiceDefinition(
                id: 'test-service',
                imageRef:
                    'docker.io/greenwood/test-service:v1',
                command: [
                    '/usr/bin/example-daemon',
                    '--listen',
                    '0.0.0.0:9999',
                ],
                environment: [
                    'GREENWOOD_TEST' => '1',
                ],
                mounts: [
                    [
                        'destination' =>
                            '/var/lib/example',
                        'type' => 'bind',
                        'source' =>
                            '/var/lib/greenwood-system/example',
                        'options' => [
                            'rbind',
                            'rw',
                        ],
                    ],
                ],
                user: '0:0',
                hostNetwork: true
            );

        $handle =
            $runtime->start(
                $definition
            );

        self::assertInstanceOf(
            SystemServiceHandle::class,
            $handle
        );

        self::assertSame(
            4242,
            $handle->pid
        );

        self::assertSame(
            'sha256:'
            . str_repeat('a', 64),
            $handle->imageDigest
        );

        self::assertIsArray(
            $capturedSpec
        );

        self::assertNotContains(
            'network',
            array_column(
                $capturedSpec['linux']['namespaces'],
                'type'
            )
        );

        self::assertSame(
            [
                '/usr/bin/example-daemon',
                '--listen',
                '0.0.0.0:9999',
            ],
            $capturedSpec['process']['args']
        );

        self::assertSame(
            0,
            $capturedSpec['process']['user']['uid']
        );

        self::assertSame(
            0,
            $capturedSpec['process']['user']['gid']
        );

        self::assertContains(
            'GREENWOOD_TEST=1',
            $capturedSpec['process']['env']
        );

        self::assertSame(
            '/',
            $capturedSpec['process']['cwd']
        );

        $mounts = array_values(
            array_filter(
                $capturedSpec['mounts'],
                static fn (array $entry): bool =>
                    ($entry['destination'] ?? null)
                    === '/var/lib/example'
            )
        );

        self::assertCount(
            1,
            $mounts
        );

        self::assertSame(
            '/var/lib/greenwood-system/example',
            $mounts[0]['source']
        );
    }

    public function testFailedStartRemovesPartialRuntimeInReverseOrder(): void
    {
        $events = [];

        $clients = $this->clientSet();

        $resolved = $this->resolvedImage();
        $image = new Image();

        $prepared =
            new PreparedSnapshot(
                'gw-system-view-failure-test',
                'erofs',
                []
            );

        $preparation =
            $this->createMock(
                ImagePreparationService::class
            );

        $images =
            $this->createMock(
                ImageService::class
            );

        $rootfs =
            $this->createMock(
                ImageRootfsResolver::class
            );

        $snapshots =
            $this->createMock(
                SnapshotService::class
            );

        $leases =
            $this->createMock(
                LeaseService::class
            );

        $containers =
            $this->createMock(
                ContainerService::class
            );

        $tasks =
            $this->createMock(
                TaskService::class
            );

        $preparation
            ->method('ensureReady')
            ->willReturn($resolved);

        $images
            ->method('get')
            ->willReturn($image);

        $leases
            ->method('create')
            ->willReturnCallback(
                function () use (&$events): void {
                    $events[] = 'lease-create';
                }
            );

        $rootfs
            ->method('resolve')
            ->willReturn(
                'sha256:'
                . str_repeat('b', 64)
            );

        $snapshots
            ->method('view')
            ->willReturnCallback(
                function () use (
                    &$events,
                    $prepared
                ): PreparedSnapshot {
                    $events[] = 'view-create';

                    return $prepared;
                }
            );

        $container = new Container();
        $container->setId(
            'failure-test'
        );

        $containers
            ->method('buildContainer')
            ->willReturn($container);

        $containers
            ->method('create')
            ->willReturnCallback(
                function () use (&$events): void {
                    $events[] = 'container-create';
                }
            );

        $createResponse =
            new CreateTaskResponse();

        $tasks
            ->method('create')
            ->willReturnCallback(
                function () use (
                    &$events,
                    $createResponse
                ): CreateTaskResponse {
                    $events[] = 'task-create';

                    return $createResponse;
                }
            );

        $tasks
            ->method('start')
            ->willReturnCallback(
                function () use (&$events): never {
                    $events[] = 'task-start';

                    throw new \RuntimeException(
                        'synthetic start failure'
                    );
                }
            );

        $tasks
            ->method('delete')
            ->willReturnCallback(
                function () use (&$events) {
                    $events[] = 'task-delete';

                    return new
                        \Containerd\Services\Tasks\V1\DeleteResponse();
                }
            );

        $containers
            ->method('delete')
            ->willReturnCallback(
                function () use (&$events): void {
                    $events[] = 'container-delete';
                }
            );

        $snapshots
            ->method('remove')
            ->willReturnCallback(
                function () use (&$events): void {
                    $events[] = 'view-delete';
                }
            );

        $leases
            ->method('delete')
            ->willReturnCallback(
                function () use (&$events): void {
                    $events[] = 'lease-delete';
                }
            );

        $runtime = new SystemServiceRuntime(
            clients: $clients,
            preparationService: $preparation,
            imageService: $images,
            rootfsResolver: $rootfs,
            snapshotService: $snapshots,
            leaseService: $leases,
            containerService: $containers,
            taskService: $tasks
        );

        try {
            $runtime->start(
                new SystemServiceDefinition(
                    id: 'failure-test',
                    imageRef:
                        'docker.io/greenwood/test-service:v1',
                    command: [
                        '/usr/bin/example-daemon',
                    ]
                )
            );

            self::fail(
                'Expected synthetic start failure'
            );
        } catch (\RuntimeException $error) {
            self::assertSame(
                'synthetic start failure',
                $error->getMessage()
            );
        }

        self::assertSame(
            [
                'lease-create',
                'view-create',
                'container-create',
                'task-create',
                'task-start',
                'task-delete',
                'container-delete',
                'view-delete',
                'lease-delete',
            ],
            $events
        );
    }

    public function testStopEscalatesAndCleansInDependencyOrder(): void
    {
        $signals = [];
        $waits = 0;
        $cleanup = [];

        $clients = $this->clientSet();

        $snapshots =
            $this->createMock(
                SnapshotService::class
            );

        $leases =
            $this->createMock(
                LeaseService::class
            );

        $containers =
            $this->createMock(
                ContainerService::class
            );

        $tasks =
            $this->createMock(
                TaskService::class
            );

        $tasks
            ->method('kill')
            ->willReturnCallback(
                function (
                    string $containerId,
                    int $signal,
                    string $taskId,
                    bool $all
                ) use (&$signals): void {
                    self::assertSame(
                        'test-service',
                        $containerId
                    );

                    self::assertSame(
                        '',
                        $taskId
                    );

                    self::assertTrue($all);

                    $signals[] = $signal;
                }
            );

        $tasks
            ->method('wait')
            ->willReturnCallback(
                function () use (&$waits): WaitResponse {
                    $waits++;

                    if ($waits === 1) {
                        throw new \RuntimeException(
                            'synthetic TERM timeout'
                        );
                    }

                    return new WaitResponse();
                }
            );

        $tasks
            ->method('delete')
            ->willReturnCallback(
                function () use (&$cleanup) {
                    $cleanup[] = 'task';

                    return new
                        \Containerd\Services\Tasks\V1\DeleteResponse();
                }
            );

        $containers
            ->method('delete')
            ->willReturnCallback(
                function () use (&$cleanup): void {
                    $cleanup[] = 'container';
                }
            );

        $snapshots
            ->method('remove')
            ->willReturnCallback(
                function () use (&$cleanup): void {
                    $cleanup[] = 'view';
                }
            );

        $leases
            ->method('delete')
            ->willReturnCallback(
                function () use (&$cleanup): void {
                    $cleanup[] = 'lease';
                }
            );

        $runtime = new SystemServiceRuntime(
            clients: $clients,
            snapshotService: $snapshots,
            leaseService: $leases,
            containerService: $containers,
            taskService: $tasks
        );

        $runtime->stop(
            new SystemServiceHandle(
                serviceId: 'test-service',
                imageReference:
                    'docker.io/greenwood/test-service'
                    . '@sha256:'
                    . str_repeat('a', 64),
                imageDigest:
                    'sha256:'
                    . str_repeat('a', 64),
                containerId: 'test-service',
                viewKey:
                    'gw-system-view-test-service',
                parentSnapshot:
                    'sha256:'
                    . str_repeat('b', 64),
                leaseId:
                    'gw-system-lease-test-service',
                pid: 4242
            ),
            1000,
            1000
        );

        self::assertSame(
            [15, 9],
            $signals
        );

        self::assertSame(
            2,
            $waits
        );

        self::assertSame(
            [
                'task',
                'container',
                'view',
                'lease',
            ],
            $cleanup
        );
    }

    public function testStopPreservesAllResourcesWhenExitCannotBeConfirmed(): void
    {
        $tasks = $this->createMock(TaskService::class);
        $tasks->expects(self::exactly(2))->method('wait')
            ->willThrowException(new \RuntimeException('wait unavailable'));
        $tasks->expects(self::never())->method('delete');
        $containers = $this->createMock(ContainerService::class);
        $containers->expects(self::never())->method('delete');
        $snapshots = $this->createMock(SnapshotService::class);
        $snapshots->expects(self::never())->method('remove');
        $leases = $this->createMock(LeaseService::class);
        $leases->expects(self::never())->method('delete');
        $runtime = new SystemServiceRuntime($this->clientSet(),
            snapshotService: $snapshots, leaseService: $leases,
            containerService: $containers, taskService: $tasks);
        $this->expectException(\Greenwood\Contracts\ContainerdException::class);
        $this->expectExceptionMessage('task exit is unconfirmed');
        $runtime->stop($this->testHandle(), 1000, 1000);
    }

    public function testStopAndFailedStartPreserveDependenciesAtEveryCleanupFailure(): void
    {
        foreach (['stop', 'start'] as $operation) {
            foreach (['task', 'container', 'view', 'lease'] as $failure) {
                $events = [];
                $tasks = $this->createMock(TaskService::class);
                $containers = $this->createMock(ContainerService::class);
                $snapshots = $this->createMock(SnapshotService::class);
                $leases = $this->createMock(LeaseService::class);
                $cleanupError = new \RuntimeException('failed ' . $failure);
                $steps = [
                    ['task', $tasks, 'delete'],
                    ['container', $containers, 'delete'],
                    ['view', $snapshots, 'remove'],
                    ['lease', $leases, 'delete'],
                ];
                $pastFailure = false;
                foreach ($steps as [$name, $service, $method]) {
                    $service->expects($pastFailure ? self::never() : self::once())
                        ->method($method)->willReturnCallback(
                            function () use ($name, $failure, $cleanupError, &$events) {
                                $events[] = $name;
                                if ($name === $failure) {
                                    throw $cleanupError;
                                }
                                return $name === 'task'
                                    ? new \Containerd\Services\Tasks\V1\DeleteResponse()
                                    : null;
                            }
                        );
                    $pastFailure = $pastFailure || $name === $failure;
                }
                $tasks->method('wait')->willReturn(new WaitResponse());
                $tasks->method('create')->willReturn(new CreateTaskResponse());
                $startError = new \RuntimeException('ambiguous start failure');
                $tasks->method('start')->willThrowException($startError);
                $preparation = $this->createMock(ImagePreparationService::class);
                $preparation->method('ensureReady')->willReturn($this->resolvedImage());
                $images = $this->createMock(ImageService::class);
                $images->method('get')->willReturn(new Image());
                $rootfs = $this->createMock(ImageRootfsResolver::class);
                $rootfs->method('resolve')->willReturn('sha256:' . str_repeat('b', 64));
                $snapshots->method('view')->willReturn(new PreparedSnapshot('view', 'erofs', []));
                $containers->method('buildContainer')->willReturn(new Container());
                $runtime = new SystemServiceRuntime($this->clientSet(),
                    preparationService: $preparation, imageService: $images,
                    rootfsResolver: $rootfs, snapshotService: $snapshots,
                    leaseService: $leases, containerService: $containers, taskService: $tasks);
                try {
                    if ($operation === 'stop') {
                        $runtime->stop($this->testHandle(), 1000, 1000);
                    } else {
                        $runtime->start(new SystemServiceDefinition(
                            'test-service', 'example.invalid/image:v1', ['/bin/service']));
                    }
                    self::fail('Expected cleanup to fail closed');
                } catch (\Greenwood\Contracts\ContainerdException $error) {
                    self::assertStringContainsString('failed ' . $failure, $error->getMessage());
                    self::assertSame($operation === 'stop' ? $cleanupError : $startError,
                        $error->getPrevious());
                }
                $order = ['task', 'container', 'view', 'lease'];
                self::assertSame(array_slice($order, 0, array_search($failure, $order, true) + 1), $events);
            }
        }
    }

    private function testHandle(): SystemServiceHandle
    {
        return new SystemServiceHandle('test-service',
            'example.invalid/image@sha256:' . str_repeat('a', 64),
            'sha256:' . str_repeat('a', 64), 'test-service', 'view', 'parent', 'lease', 42);
    }

    public function testDigestQualifiedReferenceRemainsCanonical(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);
        foreach (['example.invalid/service:v1', 'example.invalid/service@' . $digest] as $name) {
            $image = new ResolvedImage($name, $name, $digest, 'application/vnd.oci.image.manifest.v1+json', 'linux/arm64');
            self::assertSame(explode('@', $name, 2)[0] . '@' . $digest, $image->getFullReference());
            self::assertSame(1, substr_count($image->getFullReference(), '@'));
        }
    }

    private function clientSet(): ContainerdClientSet
    {
        return $this->getMockBuilder(
            ContainerdClientSet::class
        )
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function resolvedImage(): ResolvedImage
    {
        return new ResolvedImage(
            requestedReference:
                'docker.io/greenwood/test-service:v1',
            resolvedName:
                'docker.io/greenwood/test-service',
            digest:
                'sha256:'
                . str_repeat('a', 64),
            mediaType:
                'application/vnd.oci.image.manifest.v1+json',
            platform:
                'linux/arm64'
        );
    }
}
