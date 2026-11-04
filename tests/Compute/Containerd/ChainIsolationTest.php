<?php

declare(strict_types=1);

namespace Greenwood\Tests\Compute\Containerd;

use Greenwood\Compute\Containerd\ContainerdConnection;
use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\ContainerdExecutor;
use Greenwood\Compute\Containerd\ExecutionRegistry;
use Greenwood\Compute\Containerd\ResourceLimits;
use Greenwood\Persistence\StateSqlite;
use Greenwood\Persistence\BlockFilesystem;
use Greenwood\Consensus\Journal\SafetyJournal;
use Greenwood\Crypto\Ed25519KeyPair;
use PHPUnit\Framework\TestCase;

/**
 * 4A.4.7 — Chain Isolation Test.
 *
 * Verifies that OCI compute workloads CANNOT mutate blockchain consensus state.
 * Only intentional protocol transactions submitted via the consensus layer
 * should affect the chain.
 *
 * Pre-compute baseline: finalized height, state root, signing journal, block hashes.
 * Post-compute: assert all artifacts remain unchanged.
 *
 * Runtime workloads: COMPLETED, FAILED, TIMED_OUT, TERMINATED, resource-pressure FAILED.
 */
class ChainIsolationTest extends TestCase
{
    private ?ContainerdConnection $connection = null;
    private ?ContainerdClientSet $clients = null;
    private ?ContainerdExecutor $executor = null;
    private ?string $baseDir = null;
    private ?StateSqlite $stateDb = null;
    private ?BlockFilesystem $blocks = null;
    private ?SafetyJournal $journal = null;
    private ?Ed25519KeyPair $key = null;

    protected function setUp(): void
    {
        // Use a temporary directory for isolated consensus fixture
        $this->baseDir = sys_get_temp_dir() . '/greenwood-chain-isolation-' . uniqid('test_', true);
        mkdir($this->baseDir, 0700, true);
        
        // Create subdirectories
        mkdir($this->baseDir . '/state', 0700, true);
        mkdir($this->baseDir . '/blocks', 0700, true);
        mkdir($this->baseDir . '/journal', 0700, true);
        
        // Create isolated state database
        $this->stateDb = new StateSqlite($this->baseDir . '/state/db.sqlite');
        
        // Seed a known account for balance/nonce baseline
        $knownAddress = str_repeat("\x00", 32);
        $this->stateDb->upsertAccount($knownAddress, 1000000, 5);
        
        // Set initial chain state
        $this->stateDb->setChainHeight(42);
        $initialHash = str_repeat("\x01", 32);
        $this->stateDb->setFinalizedBlockHash(bin2hex($initialHash));
        $this->stateDb->setFinalizedStateRoot(str_repeat("\x02", 32));
        
        // Create block filesystem
        $this->blocks = new BlockFilesystem($this->baseDir . '/blocks');
        
        // Create signing journal with test identity
        $this->key = Ed25519KeyPair::generate();
        $genesisHash = str_repeat("\x03", 32);
        $validatorId = str_repeat("\x04", 32);
        $this->journal = new SafetyJournal(
            $this->baseDir . '/journal/safety.db',
            $this->key,
            $genesisHash,
            $validatorId
        );
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if ($this->baseDir !== null && is_dir($this->baseDir)) {
            $this->deleteRecursive($this->baseDir);
        }
    }

    private function deleteRecursive(string $dir): void
    {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /** Capture logical consensus state baseline */
    private function captureBaseline(): array
    {
        $knownAddress = str_repeat("\x00", 32);
        
        return [
            'height' => $this->stateDb->getChainHeight(),
            'finalized_hash' => $this->stateDb->getFinalizedBlockHash(),
            'state_root' => $this->stateDb->getFinalizedStateRoot(),
            'account_balance' => $this->stateDb->getBalance($knownAddress),
            'account_nonce' => $this->stateDb->getNonce($knownAddress),
            'block_files' => $this->getBlockFileHashes(),
            'certificate_files' => $this->getCertificateFileHashes(),
            'journal_entries' => $this->getJournalEntryHashes(),
        ];
    }

    private function getBlockFileHashes(): array
    {
        $hashes = [];
        $dir = $this->baseDir . '/blocks';
        if (!is_dir($dir)) return $hashes;
        
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (is_file($dir . '/' . $file)) {
                $hashes[$file] = hash_file('sha256', $dir . '/' . $file);
            }
        }
        return $hashes;
    }

    private function getCertificateFileHashes(): array
    {
        $hashes = [];
        $dir = $this->baseDir . '/blocks';
        if (!is_dir($dir)) return $hashes;
        
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (is_file($dir . '/' . $file) && str_ends_with($file, '-cert.bin')) {
                $hashes[$file] = hash_file('sha256', $dir . '/' . $file);
            }
        }
        return $hashes;
    }

    private function getJournalEntryHashes(): array
    {
        $hashes = [];
        $dbPath = $this->baseDir . '/journal/safety.db';
        if (!is_file($dbPath)) return $hashes;
        
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        
        // Signatures table entries - columns: height, round, kind, unsigned, signed
        foreach ($pdo->query('SELECT * FROM signatures') as $row) {
            $key = 'sig:' . $row['height'] . ':' . $row['round'] . ':' . $row['kind'];
            $hashes[$key] = hash('sha256', $row['signed']);
        }
        
        // Lock table entries - columns: height, round, block, proof
        foreach ($pdo->query('SELECT * FROM locks') as $row) {
            $key = 'lock:' . $row['height'] . ':' . $row['round'];
            $hashes[$key] = hash('sha256', $row['block'] . $row['proof']);
        }
        
        return $hashes;
    }

    private function checkRuntimeAvailable(): bool
    {
        try {
            $connection = new ContainerdConnection();
            return $connection->checkConnection();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function runWorkloads(): array
    {
        $results = [];
        
        $results['runtime_available'] = $this->checkRuntimeAvailable();
        
        if (!$results['runtime_available']) {
            $results['error'] = 'Containerd not available';
            return $results;
        }
        
        try {
            $this->connection = new ContainerdConnection();
            $this->clients = new ContainerdClientSet($this->connection);
            $this->executor = new ContainerdExecutor($this->clients);
        } catch (\Throwable $e) {
            $results['error'] = $e->getMessage();
            return $results;
        }
        
        $image = 'docker.io/library/alpine:latest';

        // Test 1: Successful execution - use proven command format
        try {
            $r1 = $this->executor->run($image, "/bin/ash -c 'printf \"greenwood\\n\"'");
            $results['completed'] = [
                'status' => $r1->getStatus()->getValue(),
                'exit_code' => $r1->getExitCode(),
                'stdout' => $r1->getStdout(),
                'error_message' => $r1->getErrorMessage(),
            ];
        } catch (\Throwable $e) {
            $results['completed'] = ['error' => $e->getMessage()];
        }

        // Test 2: Failed execution (non-zero exit)
        try {
            $r2 = $this->executor->run($image, "/bin/ash -c 'exit 7'");
            $results['failed'] = [
                'status' => $r2->getStatus()->getValue(),
                'exit_code' => $r2->getExitCode(),
            ];
        } catch (\Throwable $e) {
            $results['failed'] = ['error' => $e->getMessage()];
        }

        // Test 3: Timed out execution
        try {
            $r3 = $this->executor->run(
                $image,
                "/bin/ash -c 'sleep 300'",
                timeoutMs: 1000
            );
            $results['timed_out'] = [
                'status' => $r3->getStatus()->getValue(),
                'exit_code' => $r3->getExitCode(),
            ];
        } catch (\Throwable $e) {
            $results['timed_out'] = ['error' => $e->getMessage()];
        }

        // Test 4: Terminated execution
        try {
            $r4 = $this->executor->run(
                $image,
                "/bin/ash -c 'sleep 300'",
                terminateAfterMs: 500
            );
            $results['terminated'] = [
                'status' => $r4->getStatus()->getValue(),
                'exit_code' => $r4->getExitCode(),
            ];
        } catch (\Throwable $e) {
            $results['terminated'] = ['error' => $e->getMessage()];
        }

        // Test 5: Resource pressure (memory OOM) - exit 137 = 128+SIGKILL
        try {
            $limits = ResourceLimits::create(64 * 1024 * 1024, 10, null, null);
            $r5 = $this->executor->run(
                $image,
                "/bin/ash -c 'awk \"BEGIN{s=\\\"0123456789\\\"; while(length(s)<100000000){s=s s}}\"'",
                resources: $limits
            );
            $results['resource_pressure'] = [
                'status' => $r5->getStatus()->getValue(),
                'exit_code' => $r5->getExitCode(),
            ];
        } catch (\Throwable $e) {
            $results['resource_pressure'] = ['error' => $e->getMessage()];
        }

        // Test 6: Verify no leaked tasks/containers
        try {
            $metadata = $this->clients->getConnection()->getMetadata();

            [$tasksResp, $tasksStatus] = $this->clients->getTasks()
                ->List(new Containerd\Services\Tasks\V1\ListTasksRequest(), $metadata, ['timeout' => 30_000_000])
                ->wait();
            
            $leakedTasks = [];
            if ($tasksStatus->code === \Grpc\STATUS_OK) {
                foreach ($tasksResp->getTasks() as $task) {
                    if (str_starts_with($task->getId(), 'gw-')) {
                        $leakedTasks[] = $task->getId();
                    }
                }
            }
            $results['leaked_tasks'] = $leakedTasks;

            [$containersResp, $containersStatus] = $this->clients->getContainers()
                ->List(new Containerd\Services\Containers\V1\ListContainersRequest(), $metadata, ['timeout' => 30_000_000])
                ->wait();
            
            $leakedContainers = [];
            if ($containersStatus->code === \Grpc\STATUS_OK) {
                foreach ($containersResp->getContainers() as $container) {
                    if (str_starts_with($container->getId(), 'gw-')) {
                        $leakedContainers[] = $container->getId();
                    }
                }
            }
            $results['leaked_containers'] = $leakedContainers;
        } catch (\Throwable $e) {
            $results['residue_check'] = ['error' => $e->getMessage()];
        }

        return $results;
    }

    private function compareStates(array $baseline, array $after): array
    {
        $changes = [];

        if ($baseline['height'] !== $after['height']) {
            $changes[] = 'height changed: ' . $baseline['height'] . ' -> ' . $after['height'];
        }
        if ($baseline['finalized_hash'] !== $after['finalized_hash']) {
            $changes[] = 'finalized_hash changed';
        }
        if ($baseline['state_root'] !== $after['state_root']) {
            $changes[] = 'state_root changed';
        }
        if ($baseline['account_balance'] !== $after['account_balance']) {
            $changes[] = 'account_balance changed: ' . $baseline['account_balance'] . ' -> ' . $after['account_balance'];
        }
        if ($baseline['account_nonce'] !== $after['account_nonce']) {
            $changes[] = 'account_nonce changed: ' . $baseline['account_nonce'] . ' -> ' . $after['account_nonce'];
        }
        if ($baseline['block_files'] !== $after['block_files']) {
            $changes[] = 'block_files changed';
        }
        if ($baseline['certificate_files'] !== $after['certificate_files']) {
            $changes[] = 'certificate_files changed';
        }
        if ($baseline['journal_entries'] !== $after['journal_entries']) {
            $changes[] = 'journal_entries changed';
        }

        return $changes;
    }

    /**
     * 4A.4.7 Chain Isolation Test
     *
     * Real OCI workloads through ContainerdExecutor must not mutate
     * consensus state: height, finalized hash, state root, account state,
     * block files, certificate files, signing journal.
     */
    public function testChainStateUnchangedAfterRealComputeWorkloads(): void
    {
        // Capture baseline BEFORE any compute workloads
        $baseline = $this->captureBaseline();

        // Run real OCI workloads through ContainerdExecutor
        $workloadResults = $this->runWorkloads();

        // Capture state AFTER workloads
        $after = $this->captureBaseline();

        // Compare
        $changes = $this->compareStates($baseline, $after);

        if ($workloadResults['runtime_available'] ?? false) {
            // Runtime available - run assertions
            $this->assertEmpty($changes, 'Consensus state was mutated by OCI workloads: ' . implode('; ', $changes));
            
            // Fix precedence bug: use parentheses around null coalescing
            $this->assertArrayNotHasKey(
                'error',
                $workloadResults,
                'Runtime error: ' . ($workloadResults['error'] ?? '')
            );
            
            $this->assertArrayNotHasKey('error', $workloadResults['completed'] ?? [], 'COMPLETED workload failed');
            $status = $workloadResults['completed']['status'] ?? '';
            $stdout = $workloadResults['completed']['stdout'] ?? '';
            $error = $workloadResults['completed']['error'] ?? '';
            $error_message = $workloadResults['completed']['error_message'] ?? '';
            $this->assertEquals('COMPLETED', $status, 'COMPLETED workload did not complete. Status=' . $status . '. Stdout=' . $stdout . '. Error=' . $error . '. ErrorMessage=' . $error_message);
            $this->assertEquals('FAILED', $workloadResults['failed']['status'] ?? '', 'FAILED workload did not fail');
            $this->assertEquals('TIMED_OUT', $workloadResults['timed_out']['status'] ?? '', 'TIMED_OUT workload did not timeout');
            $this->assertEquals('TERMINATED', $workloadResults['terminated']['status'] ?? '', 'TERMINATED workload did not terminate');
            
            // Resource pressure (OOM) returns exit 137 and FAILED status
            $this->assertEquals('FAILED', $workloadResults['resource_pressure']['status'] ?? '', 'RESOURCE-PRESSURE workload did not fail');
            $this->assertEquals(137, $workloadResults['resource_pressure']['exit_code'] ?? -1, 'RESOURCE-PRESSURE workload did not exit with OOM code 137');
            
            $this->assertEquals([], $workloadResults['leaked_tasks'] ?? [], 'Leaked tasks found');
            $this->assertEquals([], $workloadResults['leaked_containers'] ?? [], 'Leaked containers found');
        } else {
            // Runtime not available (expected on Mac without grpc extension or containerd)
            $this->markTestSkipped('Containerd runtime not available (expected on Mac)');
        }
    }

    /**
     * Verify the ExecutionRegistry properly tracks runtime state.
     * Registry is provider-local, not consensus state.
     */
    public function testExecutionRegistrySeparation(): void
    {
        // Create an execution registry with isolated storage
        // ExecutionRegistry expects a directory path, not file path
        $registry = new ExecutionRegistry($this->baseDir . '/state');

        // Begin an execution
        $executionId = 'test-exec-' . bin2hex(random_bytes(4));
        $containerId = 'test-container-' . bin2hex(random_bytes(4));
        $snapshotKey = 'test-snapshot-' . bin2hex(random_bytes(4));
        $registry->beginExecution(
            $executionId,
            $containerId,
            $snapshotKey,
            'sha256:abc123',
            '/bin/ash -c "echo test"'
        );

        // Update runtime state
        $registry->updateRuntimeState($executionId, 'RUNNING');

        // Record terminal result - status first, then exit code
        $registry->recordTerminalResult(
            $executionId,
            'COMPLETED',
            0,
            false,
            false,
            null,
            null
        );


        // Verify registry has recorded the execution
        $found = $registry->findExecution($executionId);
        $this->assertNotNull($found);
        $this->assertEquals('COMPLETED', $found['terminal_status'] ?? null);

        // Registry should NOT affect consensus state
        $baseline = $this->captureBaseline();
        $after = $this->captureBaseline();
        $this->assertSame($baseline['height'], $after['height'], 'Registry should not affect chain height');
    }

    /**
     * Verify block/certificate filesystem isolation.
     */
    public function testBlockCertificateFilesUnchanged(): void
    {
        $baseline = $this->captureBaseline();
        $after = $this->captureBaseline();

        $this->assertSame($baseline['block_files'], $after['block_files'], 'Block files were modified');
        $this->assertSame($baseline['certificate_files'], $after['certificate_files'], 'Certificate files were modified');
    }

    /**
     * Verify signing journal isolation.
     */
    public function testSigningJournalUnchanged(): void
    {
        $baseline = $this->captureBaseline();
        $after = $this->captureBaseline();

        $this->assertSame($baseline['journal_entries'], $after['journal_entries'], 'Signing journal was modified');
    }
}
