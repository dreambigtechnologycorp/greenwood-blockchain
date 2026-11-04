<?php

declare(strict_types=1);

namespace Greenwood\Tests\Compute\Containerd;

use Greenwood\Compute\Containerd\ExecutionRegistry;
use PHPUnit\Framework\TestCase;

class ExecutionRegistryTest extends TestCase
{
    private string $tempDir;
    private ?ExecutionRegistry $registry = null;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/greenwood-test-' . uniqid('reg_', true);
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->registry !== null) {
            $dbPath = $this->registry->getDbPath();
            if (file_exists($dbPath)) {
                unlink($dbPath);
            }
        }
        if (is_dir($this->tempDir)) {
            $this->deleteRecursive($this->tempDir);
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

    private function getRegistry(): ExecutionRegistry
    {
        if ($this->registry === null) {
            $this->registry = new ExecutionRegistry($this->tempDir);
        }
        return $this->registry;
    }

    public function testBeginExecutionRecordsEntry(): void
    {
        $registry = $this->getRegistry();

        $registry->beginExecution(
            'exec-001',
            'container-001',
            'snapshot-001',
            'docker.io/library/alpine:latest',
            "/bin/ash -c 'echo hello'"
        );

        $entry = $registry->findExecution('exec-001');

        self::assertNotNull($entry);
        self::assertSame('exec-001', $entry['execution_id']);
        self::assertSame('container-001', $entry['container_id']);
        self::assertSame('snapshot-001', $entry['snapshot_key']);
        self::assertSame('NORMAL', $entry['intent']);
        self::assertSame('RUNNING', $entry['last_known_runtime_state']);
        self::assertNotNull($entry['started_at']);
        self::assertNull($entry['terminal_status']);
    }

    public function testExplicitTerminationRecordsIntent(): void
    {
        $registry = $this->getRegistry();

        $registry->beginExecution('exec-001', 'container-001', 'snapshot-001', 'alpine', '/bin/sh');
        $registry->recordExplicitTermination('exec-001');

        $entry = $registry->findExecution('exec-001');

        self::assertNotNull($entry);
        self::assertSame('EXPLICIT_TERMINATION_REQUESTED', $entry['intent']);
        self::assertSame('RUNNING', $entry['last_known_runtime_state']);
        self::assertNull($entry['terminal_status']);
    }

    public function testTimeoutTerminationRecordsIntent(): void
    {
        $registry = $this->getRegistry();

        $registry->beginExecution('exec-001', 'container-001', 'snapshot-001', 'alpine', '/bin/sh');
        $registry->recordTimeoutTermination('exec-001');

        $entry = $registry->findExecution('exec-001');

        self::assertNotNull($entry);
        self::assertSame('TIMEOUT_TERMINATION_REQUESTED', $entry['intent']);
        self::assertSame('RUNNING', $entry['last_known_runtime_state']);
    }

    public function testRecordTerminalResult(): void
    {
        $registry = $this->getRegistry();

        $registry->beginExecution('exec-001', 'container-001', 'snapshot-001', 'alpine', '/bin/sh');
        $registry->recordTimeoutTermination('exec-001');

        // Record with truncation = true, bytes = 100 and 50
        $registry->recordTerminalResult(
            'exec-001',
            'TIMED_OUT',
            137,
            true,   // stdout_truncated
            true,   // stderr_truncated
            100,
            50
        );

        $entry = $registry->findExecution('exec-001');

        self::assertNotNull($entry);
        self::assertSame('TIMED_OUT', $entry['terminal_status']);
        self::assertSame(137, $entry['exit_code']);
        self::assertTrue($entry['stdout_truncated']);
        self::assertTrue($entry['stderr_truncated']);
        self::assertSame(100, $entry['stdout_bytes_observed']);
        self::assertSame(50, $entry['stderr_bytes_observed']);
        self::assertSame('STOPPED', $entry['last_known_runtime_state']);
    }

    public function testRecordTerminalResultNoTruncation(): void
    {
        $registry = $this->getRegistry();

        $registry->beginExecution('exec-001', 'container-001', 'snapshot-001', 'alpine', '/bin/sh');

        // Record without truncation
        $registry->recordTerminalResult(
            'exec-001',
            'COMPLETED',
            0,
            false,  // stdout_truncated
            false,  // stderr_truncated
            null,   // stdout_bytes_observed
            null    // stderr_bytes_observed
        );

        $entry = $registry->findExecution('exec-001');

        self::assertNotNull($entry);
        self::assertSame('COMPLETED', $entry['terminal_status']);
        self::assertSame(0, $entry['exit_code']);
        self::assertFalse($entry['stdout_truncated']);
        self::assertFalse($entry['stderr_truncated']);
        self::assertNull($entry['stdout_bytes_observed']);
        self::assertNull($entry['stderr_bytes_observed']);
        self::assertSame('STOPPED', $entry['last_known_runtime_state']);
    }

    public function testFindExecutionByContainer(): void
    {
        $registry = $this->getRegistry();

        $registry->beginExecution('exec-001', 'container-001', 'snapshot-001', 'alpine', '/bin/sh');

        $entry = $registry->findExecutionByContainer('container-001');

        self::assertNotNull($entry);
        self::assertSame('exec-001', $entry['execution_id']);
    }

    public function testFindExecutionBySnapshot(): void
    {
        $registry = $this->getRegistry();

        $registry->beginExecution('exec-001', 'container-001', 'snapshot-001', 'alpine', '/bin/sh');

        $entry = $registry->findExecutionBySnapshot('snapshot-001');

        self::assertNotNull($entry);
        self::assertSame('exec-001', $entry['execution_id']);
    }

    public function testListActiveExecutions(): void
    {
        $registry = $this->getRegistry();

        $registry->beginExecution('exec-001', 'container-001', 'snapshot-001', 'alpine', '/bin/sh');
        $registry->beginExecution('exec-002', 'container-002', 'snapshot-002', 'alpine', '/bin/sh');

        $active = $registry->listActiveExecutions();

        self::assertCount(2, $active);
    }

    public function testListPendingCleanup(): void
    {
        $registry = $this->getRegistry();

        $registry->beginExecution('exec-001', 'container-001', 'snapshot-001', 'alpine', '/bin/sh');
        $registry->recordTerminalResult('exec-001', 'COMPLETED', 0, false, false, null, null);

        $registry->beginExecution('exec-002', 'container-002', 'snapshot-002', 'alpine', '/bin/sh');

        $pending = $registry->listPendingCleanup();

        self::assertCount(1, $pending);
        self::assertSame('exec-001', $pending[0]['execution_id']);
    }

    public function testListOrphans(): void
    {
        $registry = $this->getRegistry();

        $registry->beginExecution('exec-001', 'container-001', 'snapshot-001', 'alpine', '/bin/sh');
        $registry->beginExecution('exec-002', 'container-002', 'snapshot-002', 'alpine', '/bin/sh');

        $orphans = $registry->listOrphans();

        self::assertCount(2, $orphans);
    }

    public function testDeleteExecution(): void
    {
        $registry = $this->getRegistry();

        $registry->beginExecution('exec-001', 'container-001', 'snapshot-001', 'alpine', '/bin/sh');
        $registry->deleteExecution('exec-001');

        $entry = $registry->findExecution('exec-001');

        self::assertNull($entry);
    }

    public function testDatabasePersists(): void
    {
        $dbPath = $this->tempDir . '/execution_registry.sqlite';

        $registry1 = new ExecutionRegistry($this->tempDir);
        $registry1->beginExecution('exec-001', 'container-001', 'snapshot-001', 'alpine', '/bin/sh');

        $registry2 = new ExecutionRegistry($this->tempDir);
        $entry = $registry2->findExecution('exec-001');

        self::assertNotNull($entry);
        self::assertSame('exec-001', $entry['execution_id']);
    }

    public function testClearAll(): void
    {
        $registry = $this->getRegistry();

        $registry->beginExecution('exec-001', 'container-001', 'snapshot-001', 'alpine', '/bin/sh');
        $registry->beginExecution('exec-002', 'container-002', 'snapshot-002', 'alpine', '/bin/sh');

        $registry->clearAll();

        $active = $registry->listActiveExecutions();
        self::assertCount(0, $active);
    }

    public function testIntentValues(): void
    {
        $registry = $this->getRegistry();

        $registry->beginExecution('exec-001', 'container-001', 'snapshot-001', 'alpine', '/bin/sh');
        self::assertSame('NORMAL', $registry->findExecution('exec-001')['intent']);

        $registry->recordExplicitTermination('exec-001');
        self::assertSame('EXPLICIT_TERMINATION_REQUESTED', $registry->findExecution('exec-001')['intent']);

        $registry->recordTimeoutTermination('exec-001');
        self::assertSame('TIMEOUT_TERMINATION_REQUESTED', $registry->findExecution('exec-001')['intent']);
    }

    public function testRuntimeStateTransitions(): void
    {
        $registry = $this->getRegistry();

        $registry->beginExecution('exec-001', 'container-001', 'snapshot-001', 'alpine', '/bin/sh');
        self::assertSame('RUNNING', $registry->findExecution('exec-001')['last_known_runtime_state']);

        $registry->updateRuntimeState('exec-001', 'PAUSED');
        self::assertSame('PAUSED', $registry->findExecution('exec-001')['last_known_runtime_state']);

        $registry->updateRuntimeState('exec-001', 'STOPPED');
        self::assertSame('STOPPED', $registry->findExecution('exec-001')['last_known_runtime_state']);
    }

    public function testDeadlineAt(): void
    {
        $registry = $this->getRegistry();

        $deadline = time() + 300;
        $registry->beginExecution('exec-001', 'container-001', 'snapshot-001', 'alpine', '/bin/sh', null, null, $deadline);

        $entry = $registry->findExecution('exec-001');
        self::assertSame($deadline, $entry['deadline_at']);
    }
}
