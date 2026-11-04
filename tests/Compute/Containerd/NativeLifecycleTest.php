<?php

namespace Greenwood\Tests\Compute\Containerd;

use Greenwood\Compute\Containerd\ContainerdConnection;
use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\Service\VersionService;
use Greenwood\Compute\Containerd\Service\NamespaceService;
use Greenwood\Compute\Containerd\Service\ImageService;
use Greenwood\Compute\Containerd\Service\SnapshotService;
use Greenwood\Compute\Containerd\Service\ContainerService;
use Greenwood\Compute\Containerd\Service\TaskService;
use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdUnavailableException;
use Greenwood\Contracts\ContainerdRpcException;
use PHPUnit\Framework\TestCase;

/**
 * Native containerd lifecycle integration tests
 */
class NativeLifecycleTest extends TestCase
{
    private ?ContainerdConnection $connection = null;
    private ?ContainerdClientSet $clients = null;
    private ?VersionService $versionService = null;
    private ?NamespaceService $namespaceService = null;
    private ?ImageService $imageService = null;
    private ?SnapshotService $snapshotService = null;
    private ?ContainerService $containerService = null;
    private ?TaskService $taskService = null;

    protected function setUp(): void
    {
        $this->connection = new ContainerdConnection();
        $this->clients = new ContainerdClientSet($this->connection);
        $this->versionService = new VersionService($this->clients);
        $this->namespaceService = new NamespaceService($this->clients);
        $this->imageService = new ImageService($this->clients);
        $this->snapshotService = new SnapshotService($this->clients);
        $this->containerService = new ContainerService($this->clients);
        $this->taskService = new TaskService($this->clients);
    }

    protected function tearDown(): void
    {
        $this->connection = null;
        $this->clients = null;
    }

    public function testVersionRpcReturnsContainerdVersion(): void
    {
        $version = $this->versionService->getVersion();

        self::assertArrayHasKey("version", $version);
        self::assertArrayHasKey("revision", $version);
        self::assertIsString($version["version"]);
        self::assertNotEmpty($version["version"]);
        self::assertIsString($version["revision"]);
    }

    public function testNamespaceServiceReturnsGreenwood(): void
    {
        $namespace = $this->namespaceService->getNamespace();
        self::assertEquals("greenwood", $namespace);
    }

    public function testSnapshotServiceGeneratesIds(): void
    {
        $id1 = $this->snapshotService->generateSnapshotId("exec-1");
        $id2 = $this->snapshotService->generateSnapshotId("exec-2");
        self::assertEquals("gw-snapshot-exec-1", $id1);
        self::assertNotEquals($id1, $id2);
    }

    public function testContainerServiceGeneratesIds(): void
    {
        $id1 = $this->containerService->generateContainerId();
        $id2 = $this->containerService->generateContainerId();
        self::assertStringStartsWith("gw-exec-", $id1);
        self::assertNotEquals($id1, $id2);
    }
}
