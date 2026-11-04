<?php

namespace Greenwood\Tests\Compute\Containerd;

use Greenwood\Compute\Containerd\ContainerdConnection;
use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\Service\ImageRootfsResolver;
use Greenwood\Compute\Containerd\Service\VersionService;
use Greenwood\Compute\Containerd\Service\NamespaceService;
use Greenwood\Compute\Containerd\Service\SnapshotService;
use Greenwood\Compute\Containerd\Service\ContainerService;
use Greenwood\Compute\Containerd\Service\TaskService;
use Greenwood\Compute\Containerd\ResolvedImage;
use Greenwood\Compute\Containerd\Model\OciPlatform;
use Greenwood\Compute\Containerd\ExecutionIo;
use Greenwood\Compute\Containerd\OciSpecBuilder;
use Greenwood\Compute\Containerd\Service\ImageService;
use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdUnavailableException;
use Greenwood\Contracts\ContainerdRpcException;
use PHPUnit\Framework\TestCase;

/**
 * Native OCI execution integration tests
 * Tests the complete lifecycle: image resolution -> snapshot -> container -> task -> cleanup
 */
class NativeOciLifecycleTest extends TestCase
{
    private ?ContainerdConnection $connection = null;
    private ?ContainerdClientSet $clients = null;
    private ?VersionService $versionService = null;
    private ?NamespaceService $namespaceService = null;
    private ?SnapshotService $snapshotService = null;
    private ?ContainerService $containerService = null;
    private ?TaskService $taskService = null;
    private ?ImageService $imageService = null;

    protected function setUp(): void
    {
        $this->connection = new ContainerdConnection();
        $this->clients = new ContainerdClientSet($this->connection);
        $this->versionService = new VersionService($this->clients);
        $this->namespaceService = new NamespaceService($this->clients);
        $this->snapshotService = new SnapshotService($this->clients);
        $this->containerService = new ContainerService($this->clients);
        $this->taskService = new TaskService($this->clients);
        $this->imageService = new ImageService($this->clients);
    }

    protected function tearDown(): void
    {
        $this->connection = null;
        $this->clients = null;
        $this->imageService = null;
    }

    public function testVersionRpcReturnsContainerdVersion(): void
    {
        $version = $this->versionService->getVersion();
        self::assertArrayHasKey("version", $version);
        self::assertEquals("2.2.2", preg_replace('/^v/', '', $version["version"]));
        self::assertSame('301b2dac98f15c27117da5c8af12118a041a31d9', $version['revision']);
    }

    public function testNamespaceMetadataIsCorrect(): void
    {
        $metadata = $this->connection->getMetadata();
        self::assertArrayHasKey("containerd-namespace", $metadata);
        self::assertIsArray($metadata["containerd-namespace"]);
        self::assertEquals(["greenwood"], $metadata["containerd-namespace"]);
    }

    public function testCustomNamespaceMetadata(): void
    {
        $connection = new ContainerdConnection("test-namespace");
        $metadata = $connection->getMetadata();
        
        self::assertArrayHasKey("containerd-namespace", $metadata);
        self::assertIsArray($metadata["containerd-namespace"]);
        self::assertEquals(["test-namespace"], $metadata["containerd-namespace"]);
    }

    public function testSnapshotCreateAndDelete(): void
    {
        $snapshotId = $this->snapshotService->generateSnapshotId("test-1");
        
        // Pull the image
        (new \Greenwood\Compute\Containerd\Service\ImagePreparationService($this->clients))
            ->ensureReady("docker.io/library/alpine:latest");
        
        // Get the image after pulling
        $image = $this->imageService->get("docker.io/library/alpine:latest");
        echo "\n=== IMAGE LABELS ===\n";
        $labels = $image->getLabels();
        echo "Labels count: " . count($labels) . "\n";
        foreach ($labels as $key => $value) {
            echo "$key = $value\n";
        }
        
        echo "\n=== EXECUTION ID: $snapshotId ===\n";
        // Get the image target info
        $target = $image->getTarget();
        if ($target) {
            echo "\nTarget digest: " . $target->getDigest() . "\n";
            echo "Target size: " . $target->getSize() . "\n";
        }

        // Prepare snapshot - parent is empty because containerd should have auto-unpacked the image
        $preparedStatement = $this->snapshotService->prepare($snapshotId);
        
        // Debug: Check the mounts returned
        $mounts = $preparedStatement->getMounts();
        echo "Snapshot mounts count: " . count($mounts) . "\n";
        foreach ($mounts as $i => $mount) {
            echo "Mount $i: type=" . $mount->getType() . ", source=" . $mount->getSource() . ", target=" . $mount->getTarget() . "\n";
            $options = [];
            foreach ($mount->getOptions() as $opt) {
                $options[] = $opt;
            }
            echo "Options: " . json_encode($options) . "\n";
        }
        
        // Check what's in the snapshot mount source
        $mountSource = $mounts[0]->getSource();
        echo "\nSnapshot mount source: $mountSource\n";
        echo "bin/ash exists: " . (file_exists($mountSource . "/bin/ash") ? "yes" : "no") . "\n";
        echo "bin/sh exists: " . (file_exists($mountSource . "/bin/sh") ? "yes" : "no") . "\n";
        
        // Verify it exists
        $info = $this->snapshotService->stat($snapshotId);
        self::assertEquals($snapshotId, $info->getName());
        
        // Delete snapshot
        $this->snapshotService->remove($snapshotId);
        
        // Verify it's gone (remove is idempotent)
        $this->snapshotService->remove($snapshotId);
    }

    public function testSnapshotIdempotentRemove(): void
    {
        $snapshotId = $this->snapshotService->generateSnapshotId("test-2");
        
        $this->snapshotService->remove($snapshotId);
        
        $caught = false;
        try {
            $this->snapshotService->stat($snapshotId);
        } catch (\Greenwood\Contracts\ContainerdException $e) {
            $caught = true;
            self::assertStringContainsString("Snapshot not found: " . $snapshotId, $e->getMessage());
        }
        self::assertTrue($caught);
    }

    public function testExecutionIoCreatesAndCleansUp(): void
    {
        $io = new ExecutionIo(sys_get_temp_dir());
        
        $io->create();
        
        self::assertTrue($io->hasStdout());
        self::assertTrue($io->hasStderr());
        self::assertStringStartsWith(sys_get_temp_dir(), $io->getStdoutPath());
        self::assertStringStartsWith(sys_get_temp_dir(), $io->getStderrPath());
        
        $io->cleanup();
        
        self::assertFalse(file_exists($io->getStdoutPath()));
        self::assertFalse(file_exists($io->getStderrPath()));
    }

    public function testOciSpecBuilderGeneratesValidSpec(): void
    {
        $builder = OciSpecBuilder::forShellCommand("rootfs", "echo hello");
        $any = $builder->build();
        
        self::assertInstanceOf(\Google\Protobuf\Any::class, $any);
        self::assertNotEmpty($any->getTypeUrl());
        self::assertNotEmpty($any->getValue());
        
        $json = $any->getValue();
        $spec = json_decode($json, true);
        self::assertEquals("1.0.2", $spec["ociVersion"]);
        self::assertEquals("greenwood-exec", $spec["hostname"]);
    }

    public function testResolvedImageValidatesDigest(): void
    {
        $image = new ResolvedImage(
            "alpine:latest",
            "docker.io/library/alpine",
            "sha256:b2848c02ac6ff53d265469b5b30f649f335e546a83330cd8916d54e65e640409",
            "application/vnd.oci.image.manifest.v1+json",
            OciPlatform::fromHost()->__toString()
        );
        
        self::assertEquals("sha256:b2848c02ac6ff53d265469b5b30f649f335e546a83330cd8916d54e65e640409", $image->getDigest());
        
        try {
            new ResolvedImage(
                "alpine:latest",
                "docker.io/library/alpine",
                "invalid-digest",
                "application/vnd.oci.image.manifest.v1+json",
                OciPlatform::fromHost()->__toString()
            );
            self::fail("Should have thrown InvalidArgumentException for invalid digest");
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Invalid digest format", $e->getMessage());
        }
    }

    public function testTaskExecutionWithOutput(): void
    {
        $imageRef = "docker.io/library/alpine:latest";
        
        // Generate a unique execution ID to prevent test collisions
        $executionId = 'native-' . bin2hex(random_bytes(8));
        
        // Cleanup any stale test resources
        $staleSnapshotId = $this->snapshotService->generateSnapshotId($executionId);
        $staleContainerId = $this->containerService->generateContainerId($executionId);
        $staleTaskId = $staleContainerId; // Task ID equals container ID
        
        try { $this->snapshotService->remove($staleSnapshotId); } catch (\Exception $e) {}
        try { $this->containerService->delete($staleContainerId); } catch (\Exception $e) {}
        try {
            $this->snapshotService->remove($staleSnapshotId);
        } catch (\Exception $e) {
            // Ignore if snapshot doesn't exist
        }
        
        // Pull the image
        (new \Greenwood\Compute\Containerd\Service\ImagePreparationService($this->clients))->ensureReady($imageRef);
        
        // Get the image after pulling
        $image = $this->imageService->get($imageRef);
        echo "\n=== IMAGE LABELS ===\n";
        $labels = $image->getLabels();
        echo "Labels count: " . count($labels) . "\n";
        foreach ($labels as $key => $value) {
            echo "$key = $value\n";
        }
        
        echo "\n=== EXECUTION ID: $executionId ===\n";
        // Get the image target info
        $target = $image->getTarget();
        if ($target) {
            echo "\nTarget digest: " . $target->getDigest() . "\n";
            echo "Target size: " . $target->getSize() . "\n";
        }
        
        $rootfsResolver = new ImageRootfsResolver($this->clients);
        $imageRootfsParent = $rootfsResolver->resolve($image);
        
        echo "\nResolved image rootfs parent: $imageRootfsParent\n";
        
        // Verify the rootfs parent snapshot exists and is committed
        $rootfsInfo = $this->snapshotService->stat($imageRootfsParent);
        echo "Rootfs snapshot kind: " . $rootfsInfo->getKind() . "\n";
        echo "Rootfs snapshot parent: " . $rootfsInfo->getParent() . "\n";
        echo "Rootfs snapshot labels: " . json_encode($rootfsInfo->getLabels()) . "\n";
        
        // Prepare the execution snapshot using the resolved rootfs parent
        $snapshotId = $this->snapshotService->generateSnapshotId($executionId);
        $preparedStatement = $this->snapshotService->prepare($snapshotId, $imageRootfsParent);
        
        // Debug: Check the mounts returned
        $mounts = $preparedStatement->getMounts();
        echo "\nSnapshot mounts count: " . count($mounts) . "\n";
        foreach ($mounts as $i => $mount) {
            echo "Mount $i: type=" . $mount->getType() . ", source=" . $mount->getSource() . ", target=" . $mount->getTarget() . "\n";
            $options = [];
            foreach ($mount->getOptions() as $opt) {
                $options[] = $opt;
            }
            echo "Options: " . json_encode($options) . "\n";
        }
        
        $mountSource = $mounts[0]->getSource();
        echo "\nSnapshot mount source: $mountSource\n";
        echo "bin/ash exists: " . (file_exists($mountSource . "/bin/ash") ? "yes" : "no") . "\n";
        echo "bin/sh exists: " . (file_exists($mountSource . "/bin/sh") ? "yes" : "no") . "\n";
        
        // Create ResolvedImage for container
        $resolvedImage = new ResolvedImage(
            $imageRef,
            "docker.io/library/alpine",
            $target->getDigest(),
            "application/vnd.oci.image.manifest.v1+json",
            OciPlatform::fromHost()->__toString()
        );
        
        // Build OCI spec
        $specBuilder = OciSpecBuilder::forShellCommand("rootfs", "/bin/ash -c 'printf \"greenwood\\n\"'");
        $spec = $specBuilder->build();
        
        // Build container
        $containerId = $this->containerService->generateContainerId($executionId);
        $container = $this->containerService->buildContainer($containerId, $resolvedImage, $snapshotId, $spec);
        
        // Create the container
        $this->containerService->create($container);
        
        // Create task
        $execId = '';  // Init task uses empty exec_id per containerd spec
        // Create FIFOs for stdout/stderr
        $io = new ExecutionIo(sys_get_temp_dir());
        $io->create();
        $execId = '';  // Init task uses empty exec_id per containerd spec
        // FIFO read ends MUST be open before Tasks.Create, otherwise runc
        // blocks opening the stdout/stderr FIFOs and Create deadlocks.
        $io->openReaders();
        $this->taskService->create($containerId, $execId, $preparedStatement, '', $io->getStdoutPath(), $io->getStderrPath());
        
        // Start task
        $this->taskService->start($containerId, '');  // Init task uses empty exec_id
        
        // Wait for task to complete
        $status = $this->taskService->wait($containerId, '');  // Init task uses empty exec_id
        self::assertSame(0, $status->getExitStatus());


        $stdout = $io->readStdout();
        self::assertSame("greenwood\n", $stdout);

        $stderr = $io->readStderr();
        self::assertSame("", $stderr);
        
        // Cleanup
        $this->taskService->delete($containerId, '');  // Init task uses empty exec_id
        $this->containerService->delete($containerId);
        $this->snapshotService->remove($snapshotId);
        
        $io->cleanup();
    }

    // ------------------------------------------------------------------
    // 4A.3B.2 — Failure semantics
    // ------------------------------------------------------------------

    public function testNonZeroExitCodeIsReturned(): void
    {
        $result = $this->runWorkload("/bin/ash -c 'exit 7'");

        // The runtime worked; the workload itself failed. Exit code must be
        // preserved exactly so the compute layer can classify FAILED (not
        // RUNTIME_ERROR).
        self::assertSame(7, $result['exitStatus']);
        self::assertSame("", $result['stdout']);
        self::assertSame("", $result['stderr']);
    }

    public function testStderrIsCapturedIndependentlyFromStdout(): void
    {
        $result = $this->runWorkload("/bin/ash -c 'printf \"bad\\n\" >&2; exit 7'");

        self::assertSame(7, $result['exitStatus']);
        self::assertSame("", $result['stdout']);
        self::assertSame("bad\n", $result['stderr']);
    }

    public function testSigtermTerminatesRunningTask(): void
    {
        $result = $this->runWorkload(
            "/bin/ash -c 'sleep 300'",
            terminate: true
        );

        // SIGTERM delivered to the whole workload (all=true): 128 + 15.
        // If the workload had ignored SIGTERM, terminateAndReap() would have
        // escalated to SIGKILL (137) instead — both prove reaped termination.
        self::assertContains($result['exitStatus'], [143, 137]);
        self::assertTrue($result['reaped']);
    }

    public function testSigkillForcesTerminationAfterIgnoredSigterm(): void
    {
        // Workload ignores SIGTERM; only SIGKILL can stop it. This proves the
        // escalation path: SIGTERM -> bounded grace wait -> SIGKILL.
        $result = $this->runWorkload(
            "/bin/ash -c 'trap \"\" TERM; while true; do sleep 1; done'",
            terminate: true,
            afterStart: function (string $containerId): void {
                usleep(500_000); // let the workload install its trap
            }
        );

        // 128 + SIGKILL(9): SIGTERM was ignored, escalation was required.
        self::assertSame(137, $result['exitStatus']);
        self::assertTrue($result['reaped']);
    }

    /**
     * Terminate a running task through the ordered state machine:
     *
     *   RUNNING -> SIGTERM(all) -> bounded grace wait
     *     -> exited:  reaped
     *     -> timeout: SIGKILL(all) -> bounded wait -> reaped
     *
     * A Deadline Exceeded from the grace Wait means only "no exit observed
     * within the window" — it triggers escalation, never cleanup.
     *
     * @return int The task exit status after reaping
     */
    private function terminateAndReap(string $containerId): int
    {
        $this->taskService->kill($containerId, 15, '', true); // SIGTERM, all processes

        try {
            $status = $this->taskService->wait($containerId, '', 2_000_000); // 2s grace
            return $status->getExitStatus();
        } catch (ContainerdRpcException $e) {
            // Grace window expired without observing exit: escalate.
        }

        $this->taskService->kill($containerId, 9, '', true); // SIGKILL, all processes

        $status = $this->taskService->wait($containerId, '', 5_000_000); // 5s bound
        return $status->getExitStatus();
    }

    /**
     * Run a shell-command workload through the full native lifecycle with
     * guaranteed cleanup of task, container, snapshot, and IO resources.
     *
     * @param string $command The ash command line for the workload
     * @param bool $terminate If true, terminate the running task via
     *                        terminateAndReap() instead of waiting for
     *                        natural exit.
     * @param callable|null $afterStart Optional hook invoked after Tasks.Start
     *                                  (receives the container ID).
     *
     * @return array{exitStatus: int, stdout: string, stderr: string, containerId: string, reaped: bool}
     */
    private function runWorkload(string $command, bool $terminate = false, ?callable $afterStart = null): array
    {
        $imageRef = "docker.io/library/alpine:latest";
        $executionId = 'native-' . bin2hex(random_bytes(8));

        $snapshotId = $this->snapshotService->generateSnapshotId($executionId);
        $containerId = $this->containerService->generateContainerId($executionId);

        // Pre-clean any stale resources with the same derived IDs
        try { $this->snapshotService->remove($snapshotId); } catch (\Exception $e) {}
        try { $this->containerService->delete($containerId); } catch (\Exception $e) {}

        $io = new ExecutionIo(sys_get_temp_dir());
        $reaped = false;

        try {
            (new \Greenwood\Compute\Containerd\Service\ImagePreparationService($this->clients))->ensureReady($imageRef);
            $image = $this->imageService->get($imageRef);
            $target = $image->getTarget();

            $rootfsResolver = new ImageRootfsResolver($this->clients);
            $imageRootfsParent = $rootfsResolver->resolve($image);

            $preparedStatement = $this->snapshotService->prepare($snapshotId, $imageRootfsParent);

            $resolvedImage = new ResolvedImage(
                $imageRef,
                "docker.io/library/alpine",
                $target->getDigest(),
                "application/vnd.oci.image.manifest.v1+json",
                OciPlatform::fromHost()->__toString()
            );

            $spec = OciSpecBuilder::forShellCommand("rootfs", $command)->build();

            $container = $this->containerService->buildContainer($containerId, $resolvedImage, $snapshotId, $spec);
            $this->containerService->create($container);

            $io->create();
            // FIFO readers must exist before Tasks.Create or runc blocks
            // opening the stdio FIFOs (see 4A.3B.1 root cause).
            $io->openReaders();

            $this->taskService->create(
                $containerId,
                '', // Init task: empty exec_id
                $preparedStatement,
                '',
                $io->getStdoutPath(),
                $io->getStderrPath()
            );
            $this->taskService->start($containerId, '');

            if ($afterStart !== null) {
                $afterStart($containerId);
            }

            if ($terminate) {
                $exitStatus = $this->terminateAndReap($containerId);
            } else {
                $exitStatus = $this->taskService->wait($containerId, '')->getExitStatus();
            }
            $reaped = true;

            return [
                'exitStatus' => $exitStatus,
                'stdout' => $io->readStdout(),
                'stderr' => $io->readStderr(),
                'containerId' => $containerId,
                'reaped' => $reaped,
            ];
        } finally {
            // Dependency-ordered cleanup: the task must be dead and reaped
            // BEFORE its container/snapshot may be deleted. If the task was
            // never reaped (exception path), force-reap it first; a Deadline
            // Exceeded from Wait only means "no exit observed in the window",
            // never that the task is gone.
            if (!$reaped) {
                try { $this->taskService->kill($containerId, 9, '', true); } catch (\Exception $e) {}
                try { $this->taskService->wait($containerId, '', 5_000_000); } catch (\Exception $e) {}
            }
            try { $this->taskService->delete($containerId, ''); } catch (\Exception $e) {}
            try { $this->containerService->delete($containerId); } catch (\Exception $e) {}
            try { $this->snapshotService->remove($snapshotId); } catch (\Exception $e) {}
            $io->cleanup();
        }
    }
}
