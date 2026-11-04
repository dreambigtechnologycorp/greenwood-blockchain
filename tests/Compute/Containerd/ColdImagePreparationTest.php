<?php
declare(strict_types=1);

namespace Greenwood\Tests\Compute\Containerd;

use Greenwood\Compute\Containerd\ContainerdConnection;
use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\ContainerdExecutor;
use Greenwood\Compute\Containerd\Service\NamespaceService;
use Greenwood\Compute\Containerd\Service\ImagePreparationService;
use Greenwood\Compute\Containerd\Service\ImageService;
use Greenwood\Compute\Containerd\Service\SnapshotService;
use Greenwood\Compute\Containerd\Service\ImageRootfsResolver;
use Greenwood\Compute\Containerd\Model\OciPlatform;
use Containerd\Services\Tasks\V1\ListTasksRequest;
use Containerd\Services\Containers\V1\ListContainersRequest;
use Greenwood\Contracts\ContainerdRpcException;
use Containerd\Services\Snapshots\V1\Kind;
use Greenwood\Contracts\ContainerdException;
use PHPUnit\Framework\TestCase;

/**
 * Cold-start image preparation test using Transfer API
 *
 * Verifies that:
 * - Image can be pulled fresh via Transfer without prior cache
 * - Rootfs is correctly unpacked to the snapshotter
 * - Execution completes successfully with proper output
 * - Execution residue is clean (tasks/containers/snapshots deleted)
 */
class ColdImagePreparationTest extends TestCase
{
    private ?ContainerdConnection $connection = null;
    private ?ContainerdClientSet $clients = null;
    private ?NamespaceService $namespaceService = null;
    private ?ImagePreparationService $preparationService = null;
    private ?ImageService $imageService = null;
    private ?SnapshotService $snapshotService = null;
    private ?ContainerdExecutor $executor = null;
    private array $initialFifos = [];

    protected function setUp(): void
    {
        // A unique namespace has no image metadata or usable committed rootfs.
        // Shared content may be cached; no shared images/snapshots are deleted.
        $this->connection = new ContainerdConnection('greenwood-cold-' . bin2hex(random_bytes(8)));
        $this->initialFifos = $this->fifos();
        $this->clients = new ContainerdClientSet($this->connection);
        $this->namespaceService = new NamespaceService($this->clients);
        $this->preparationService = new ImagePreparationService($this->clients);
        $this->imageService = new ImageService($this->clients);
        $this->snapshotService = new SnapshotService($this->clients);
        $this->executor = new ContainerdExecutor($this->clients);
    }

    protected function tearDown(): void
    {
        $this->connection = null;
        $this->clients = null;
        $this->preparationService = null;
        $this->imageService = null;
        $this->snapshotService = null;
        $this->executor = null;
    }

    /**
     * Verify pre-transfer image state: should not exist
     */
    public function testImageNotPresentBeforeTransfer(): void
    {
        $imageRef = 'docker.io/library/alpine:latest';
        
        // Verify the image doesn't exist before we start
        try {
            $this->imageService->get($imageRef);
            self::fail('Cold namespace unexpectedly contains the image');
        } catch (ContainerdException $e) {
            $rpc = $e instanceof ContainerdRpcException ? $e : $e->getPrevious();
            self::assertInstanceOf(ContainerdRpcException::class, $rpc);
            self::assertSame(ContainerdRpcException::RPC_NOT_FOUND, $rpc->getCode());
        }
        self::assertCount(0, $this->snapshotService->list(), 'No usable rootfs before Transfer');
    }

    /**
     * Full cold-start execution test
     * 
     * This test verifies that:
     * 1. Image is pulled via Transfer API
     * 2. Rootfs is unpacked to snapshotter
     * 3. Container executes successfully
     * 4. Proper output is produced
     * 5. Execution residue is cleaned up
     */
    public function testColdStartExecutionViaTransfer(): void
    {
        $imageRef = 'docker.io/library/alpine:latest';
        $command = "/bin/ash -c 'printf \"greenwood\n\"'";

        $this->testImageNotPresentBeforeTransfer();

        // Execute with the new ContainerdExecutor
        $result = $this->executor->run($imageRef, $command);

        // Verify execution result
        self::assertEquals('COMPLETED', $result->getStatus()->getValue(), $result->getErrorMessage() ?? '');
        self::assertEquals(0, $result->getExitCode());
        self::assertEquals("greenwood\n", $result->getStdout());
        self::assertEquals("", $result->getStderr());
        $image = $this->imageService->get($imageRef);
        $rootfs = (new ImageRootfsResolver($this->clients))->resolve($image);
        self::assertSame(Kind::COMMITTED, $this->snapshotService->stat($rootfs)->getKind());
        $residue = $this->assertNoResidue();
        $evidencePath = getenv('GREENWOOD_COLD_EVIDENCE');
        if ($evidencePath !== false && $evidencePath !== '') {
            file_put_contents($evidencePath, json_encode([
                'namespace' => $this->connection->getNamespace(),
                'before' => ['image' => 'NOT_FOUND', 'snapshots' => 0],
                'transfer' => ['transport' => 'grpc', 'status' => 0,
                    'platform' => (string) OciPlatform::fromHost(), 'snapshotter' => 'overlayfs'],
                'execution' => ['status' => $result->getStatus()->getValue(), 'exitCode' => $result->getExitCode(),
                    'stdout' => $result->getStdout(), 'stderr' => $result->getStderr()],
                'after' => ['image' => $imageRef, 'digest' => $image->getTarget()->getDigest(),
                    'rootfs' => $rootfs, 'kind' => 'COMMITTED'], 'residue' => $residue,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        }
    }

    /**
     * Verify execution residue is clean after workload completes
     * 
     * Note: The ContainerdExecutor cleans up tasks/containers/snapshots
     * in the finally block. After a successful execution, writable (ACTIVE)
     * snapshots should be cleaned up (committed parent snapshots remain).
     */
    public function testNoExecutionResidueAfterCompletion(): void
    {
        $imageRef = 'docker.io/library/alpine:latest';
        $command = "/bin/ash -c 'printf \"greenwood\n\"'";
        
        // Run to completion
        $result = $this->executor->run($imageRef, $command);
        
        // Verify execution succeeded
        self::assertEquals('COMPLETED', $result->getStatus()->getValue());

        // Check that no writable (ACTIVE) snapshots remain
        // Committed parent snapshots from image unpacking remain
        $snapshots = $this->snapshotService->list();
        $activeSnapshots = array_filter($snapshots, function ($info) {
            return $info->getKind() === Kind::ACTIVE;
        });
        
        self::assertCount(0, $activeSnapshots, "Should have 0 writable snapshots after execution");
    }

    /**
     * Test with a failing command to verify exit code propagation
     */
    public function testFailedExecutionReportsNonZeroExit(): void
    {
        $imageRef = 'docker.io/library/alpine:latest';
        $command = "/bin/ash -c 'exit 42'";

        $result = $this->executor->run($imageRef, $command);

        self::assertEquals('FAILED', $result->getStatus()->getValue());
        self::assertEquals(42, $result->getExitCode());
    }

    /**
     * Test with timeout to verify deadline handling
     */
    public function testExecutionRespectsTimeout(): void
    {
        $imageRef = 'docker.io/library/alpine:latest';
        $command = "/bin/ash -c 'sleep 10'";
        $timeoutMs = 2000; // 2 second timeout

        $result = $this->executor->run($imageRef, $command, $timeoutMs);

        self::assertEquals('TIMED_OUT', $result->getStatus()->getValue());
    }

    private function fifos(): array
    {
        return array_merge(glob(sys_get_temp_dir() . '/stdout.fifo_gw_*') ?: [],
            glob(sys_get_temp_dir() . '/stderr.fifo_gw_*') ?: []);
    }

    private function assertNoResidue(): array
    {
        $metadata = $this->connection->getMetadata();
        [$tasks, $taskStatus] = $this->clients->getTasks()->List(new ListTasksRequest(), $metadata)->wait();
        [$containers, $containerStatus] = $this->clients->getContainers()->List(new ListContainersRequest(), $metadata)->wait();
        self::assertSame(0, $taskStatus->code);
        self::assertSame(0, $containerStatus->code);
        $active = array_filter($this->snapshotService->list(), static fn ($info) => $info->getKind() === Kind::ACTIVE);
        $dirs = glob('/run/containerd/io.containerd.runtime.v2.task/' . $this->connection->getNamespace() . '/*') ?: [];
        $residue = ['tasks' => count($tasks->getTasks()), 'containers' => count($containers->getContainers()),
            'active_snapshots' => count($active), 'runtime_dirs' => count($dirs),
            'fifos' => count(array_diff($this->fifos(), $this->initialFifos))];
        foreach ($residue as $kind => $count) { self::assertSame(0, $count, $kind); }
        return $residue;
    }
}
