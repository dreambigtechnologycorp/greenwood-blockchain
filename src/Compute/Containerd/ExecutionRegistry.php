<?php
declare(strict_types=1);

namespace Greenwood\Compute\Containerd;

use PDO;
use RuntimeException;
use InvalidArgumentException;

/**
 * Provider-local execution registry using SQLite.
 *
 * This is NOT consensus-critical state. It is purely for runtime recovery
 * when the provider process restarts.
 *
 * Records are written durably before state transitions that would otherwise
 * be lost on crash/restart.
 */
final class ExecutionRegistry
{
    private const DB_FILENAME = 'execution_registry.sqlite';

    private PDO $pdo;

    public function __construct(private string $storagePath)
    {
        $this->initialize();
    }

    private function initialize(): void
    {
        $dbPath = $this->storagePath . '/' . self::DB_FILENAME;

        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode=WAL');

        $this->createTable();
    }

    private function createTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS executions ("
            . "id INTEGER PRIMARY KEY AUTOINCREMENT,"
            . "execution_id TEXT NOT NULL UNIQUE,"
            . "container_id TEXT NOT NULL,"
            . "snapshot_key TEXT NOT NULL,"
            . "request_id TEXT,"
            . "source_reference TEXT,"
            . "image_ref TEXT NOT NULL,"
            . "command TEXT NOT NULL,"
            . "intent TEXT NOT NULL,"
            . "started_at INTEGER NOT NULL,"
            . "deadline_at INTEGER,"
            . "last_known_runtime_state TEXT NOT NULL,"
            . "terminal_status TEXT,"
            . "exit_code INTEGER,"
            . "stdout_truncated INTEGER,"
            . "stderr_truncated INTEGER,"
            . "stdout_bytes_observed INTEGER,"
            . "stderr_bytes_observed INTEGER,"
            . "created_at INTEGER NOT NULL,"
            . "updated_at INTEGER NOT NULL"
            . ")";

        $this->pdo->exec($sql);

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_execution_id ON executions(execution_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_container_id ON executions(container_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_snapshot_key ON executions(snapshot_key)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_last_known_state ON executions(last_known_runtime_state)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_created_at ON executions(created_at)');
    }

    public function getDbPath(): string
    {
        return $this->storagePath . '/' . self::DB_FILENAME;
    }

    public function beginExecution(
        string $executionId,
        string $containerId,
        string $snapshotKey,
        string $imageRef,
        string $command,
        ?string $requestId = null,
        ?string $sourceRef = null,
        ?int $deadlineAt = null
    ): void {
        $now = time();

        $stmt = $this->pdo->prepare(
            "INSERT INTO executions ("
            . "execution_id, container_id, snapshot_key,"
            . "request_id, source_reference,"
            . "image_ref, command,"
            . "intent, started_at, deadline_at,"
            . "last_known_runtime_state,"
            . "created_at, updated_at"
            . ") VALUES ("
            . ":execution_id, :container_id, :snapshot_key,"
            . ":request_id, :source_reference,"
            . ":image_ref, :command,"
            . ":intent, :started_at, :deadline_at,"
            . ":last_known_runtime_state,"
            . ":created_at, :updated_at"
            . ")"
        );

        $stmt->execute([
            ':execution_id' => $executionId,
            ':container_id' => $containerId,
            ':snapshot_key' => $snapshotKey,
            ':request_id' => $requestId,
            ':source_reference' => $sourceRef,
            ':image_ref' => $imageRef,
            ':command' => $command,
            ':intent' => 'NORMAL',
            ':started_at' => $now,
            ':deadline_at' => $deadlineAt,
            ':last_known_runtime_state' => 'RUNNING',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function recordExplicitTermination(string $executionId): void
    {
        $this->recordIntent($executionId, 'EXPLICIT_TERMINATION_REQUESTED', 'RUNNING');
    }

    public function recordTimeoutTermination(string $executionId): void
    {
        $this->recordIntent($executionId, 'TIMEOUT_TERMINATION_REQUESTED', 'RUNNING');
    }

    private function recordIntent(string $executionId, string $intent, string $currentState): void
    {
        $validIntent = ['NORMAL', 'EXPLICIT_TERMINATION_REQUESTED', 'TIMEOUT_TERMINATION_REQUESTED'];
        if (!in_array($intent, $validIntent, true)) {
            throw new InvalidArgumentException('Invalid intent: ' . $intent);
        }
        $validState = ['RUNNING', 'STOPPED', 'PAUSED', 'UNKNOWN'];
        if (!in_array($currentState, $validState, true)) {
            throw new InvalidArgumentException('Invalid runtime state: ' . $currentState);
        }

        $now = time();

        $stmt = $this->pdo->prepare(
            "UPDATE executions SET "
            . "intent = :intent, "
            . "last_known_runtime_state = :last_known_state, "
            . "updated_at = :updated_at "
            . "WHERE execution_id = :execution_id"
        );

        $stmt->execute([
            ':intent' => $intent,
            ':last_known_state' => $currentState,
            ':updated_at' => $now,
            ':execution_id' => $executionId,
        ]);
    }

    public function updateRuntimeState(string $executionId, string $runtimeState): void
    {
        $validState = ['RUNNING', 'STOPPED', 'PAUSED', 'UNKNOWN'];
        if (!in_array($runtimeState, $validState, true)) {
            throw new InvalidArgumentException('Invalid runtime state: ' . $runtimeState);
        }

        $now = time();

        $stmt = $this->pdo->prepare(
            "UPDATE executions SET "
            . "last_known_runtime_state = :runtime_state, "
            . "updated_at = :updated_at "
            . "WHERE execution_id = :execution_id"
        );

        $stmt->execute([
            ':runtime_state' => $runtimeState,
            ':updated_at' => $now,
            ':execution_id' => $executionId,
        ]);
    }

    public function recordTerminalResult(
        string $executionId,
        string $terminalStatus,
        ?int $exitCode,
        bool $stdoutTruncated,
        bool $stderrTruncated,
        ?int $stdoutBytesObserved,
        ?int $stderrBytesObserved
    ): void {
        $validStatus = ['COMPLETED', 'FAILED', 'TIMED_OUT', 'TERMINATED', 'RUNTIME_ERROR'];
        if (!in_array($terminalStatus, $validStatus, true)) {
            throw new InvalidArgumentException('Invalid terminal status: ' . $terminalStatus);
        }

        $now = time();

        $stmt = $this->pdo->prepare(
            "UPDATE executions SET "
            . "terminal_status = :terminal_status, "
            . "exit_code = :exit_code, "
            . "stdout_truncated = :stdout_truncated, "
            . "stderr_truncated = :stderr_truncated, "
            . "stdout_bytes_observed = :stdout_bytes_observed, "
            . "stderr_bytes_observed = :stderr_bytes_observed, "
            . "last_known_runtime_state = 'STOPPED', "
            . "updated_at = :updated_at "
            . "WHERE execution_id = :execution_id"
        );

        $stmt->execute([
            ':terminal_status' => $terminalStatus,
            ':exit_code' => $exitCode,
            ':stdout_truncated' => $stdoutTruncated ? 1 : 0,
            ':stderr_truncated' => $stderrTruncated ? 1 : 0,
            ':stdout_bytes_observed' => $stdoutBytesObserved,
            ':stderr_bytes_observed' => $stderrBytesObserved,
            ':updated_at' => $now,
            ':execution_id' => $executionId,
        ]);
    }

    public function findExecution(string $executionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM executions WHERE execution_id = :execution_id');
        $stmt->execute([':execution_id' => $executionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->normalizeRow($row);
    }

    public function findExecutionByContainer(string $containerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM executions WHERE container_id = :container_id');
        $stmt->execute([':container_id' => $containerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->normalizeRow($row);
    }

    public function findExecutionBySnapshot(string $snapshotKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM executions WHERE snapshot_key = :snapshot_key');
        $stmt->execute([':snapshot_key' => $snapshotKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->normalizeRow($row);
    }

    /**@return array<int, array>*/
    public function listActiveExecutions(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM executions WHERE last_known_runtime_state IN (?, ?, ?)');
        $stmt->execute(['RUNNING', 'PAUSED', 'UNKNOWN']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'normalizeRow'], $rows);
    }

    /**@return array<int, array>*/
    public function listPendingCleanup(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM executions WHERE terminal_status IS NOT NULL ORDER BY created_at');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'normalizeRow'], $rows);
    }

    /**@return array<int, array>*/
    public function listOrphans(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM executions WHERE terminal_status IS NULL ORDER BY created_at');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'normalizeRow'], $rows);
    }

    public function deleteExecution(string $executionId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM executions WHERE execution_id = :execution_id');
        $stmt->execute([':execution_id' => $executionId]);
    }

    /**@return array*/
    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'execution_id' => $row['execution_id'],
            'container_id' => $row['container_id'],
            'snapshot_key' => $row['snapshot_key'],
            'request_id' => $row['request_id'],
            'source_reference' => $row['source_reference'],
            'image_ref' => $row['image_ref'],
            'command' => $row['command'],
            'intent' => $row['intent'],
            'started_at' => (int) $row['started_at'],
            'deadline_at' => $row['deadline_at'] !== null ? (int) $row['deadline_at'] : null,
            'last_known_runtime_state' => $row['last_known_runtime_state'],
            'terminal_status' => $row['terminal_status'],
            'exit_code' => $row['exit_code'] !== null ? (int) $row['exit_code'] : null,
            'stdout_truncated' => (bool) $row['stdout_truncated'],
            'stderr_truncated' => (bool) $row['stderr_truncated'],
            'stdout_bytes_observed' => $row['stdout_bytes_observed'] !== null ? (int) $row['stdout_bytes_observed'] : null,
            'stderr_bytes_observed' => $row['stderr_bytes_observed'] !== null ? (int) $row['stderr_bytes_observed'] : null,
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
        ];
    }

    public function clearAll(): void
    {
        $this->pdo->exec('DELETE FROM executions');
    }
}
