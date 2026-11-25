<?php
declare(strict_types=1);

namespace Greenwood\Persistence;

use PDO;
use RuntimeException;

/** Canonical disk-backed rollback-journal policy for Greenwood's writable state. */
final class SqliteDurability
{
    public const JOURNAL_MODE = 'delete';
    public const SYNCHRONOUS = 3; // EXTRA: also sync the directory after journal unlink.

    public static function configure(PDO $db, int $busyTimeoutMilliseconds = 5000): void
    {
        if ($busyTimeoutMilliseconds < 1 || $busyTimeoutMilliseconds > 60000
            || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || $db->inTransaction()) {
            throw new RuntimeException('SQLITE_DURABILITY_CONFIGURATION_INVALID');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $databases = $db->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
        $main = array_values(array_filter($databases, static fn(array $row): bool => $row['name'] === 'main'));
        if (count($main) !== 1 || !is_string($main[0]['file']) || $main[0]['file'] === '') {
            throw new RuntimeException('SQLITE_DURABLE_FILE_REQUIRED');
        }

        $db->exec('PRAGMA busy_timeout=' . $busyTimeoutMilliseconds);
        // A former release may have committed data only in its WAL. Converting
        // that persistent mode requires an explicit stopped-service migration;
        // daemon startup must not checkpoint, delete sidecars, or silently do it.
        if (strtolower((string)$db->query('PRAGMA main.journal_mode')->fetchColumn()) === 'wal') {
            throw new RuntimeException('SQLITE_OFFLINE_ROLLBACK_MIGRATION_REQUIRED');
        }
        $mode = strtolower((string)$db->query('PRAGMA main.journal_mode=DELETE')->fetchColumn());
        $db->exec('PRAGMA main.synchronous=EXTRA');
        if ($mode !== self::JOURNAL_MODE
            || strtolower((string)$db->query('PRAGMA main.journal_mode')->fetchColumn()) !== self::JOURNAL_MODE
            || (int)$db->query('PRAGMA main.synchronous')->fetchColumn() !== self::SYNCHRONOUS
            || (int)$db->query('PRAGMA busy_timeout')->fetchColumn() !== $busyTimeoutMilliseconds) {
            throw new RuntimeException('SQLITE_DURABILITY_CONFIGURATION_REJECTED');
        }
    }

    private function __construct()
    {
    }
}
