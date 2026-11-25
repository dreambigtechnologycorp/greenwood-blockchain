<?php
declare(strict_types=1);

namespace Greenwood\Tests\Unit\Persistence;

use Greenwood\Persistence\SqliteDurability;
use Greenwood\Persistence\StateSqlite;
use Greenwood\Provider\{ResourceAllocationJournal, ResourceTelemetryJournal, ServiceOperationMeter, SettlementOutbox, SystemServiceInstallJournal};
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SqliteDurabilityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/greenwood-rollback-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function open(int $timeout = 5000): PDO
    {
        $db = new PDO('sqlite:' . $this->directory . '/state.sqlite');
        SqliteDurability::configure($db, $timeout);
        return $db;
    }

    private function seedAccounts(PDO $db): void
    {
        $db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY, balance INTEGER NOT NULL)');
        $db->exec('INSERT INTO accounts VALUES (1, 100), (2, 0)');
    }

    public function testRollbackAndCommittedTransferPreserveBalancesAcrossReopen(): void
    {
        $db = $this->open(1000);
        self::assertSame('delete', $db->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame(3, (int) $db->query('PRAGMA synchronous')->fetchColumn());
        self::assertSame(1000, (int) $db->query('PRAGMA busy_timeout')->fetchColumn());
        $this->seedAccounts($db);

        $db->beginTransaction();
        $db->exec('UPDATE accounts SET balance = balance - 30 WHERE id = 1');
        $db->rollBack();
        self::assertSame([100, 0], $db->query('SELECT balance FROM accounts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));

        $db->beginTransaction();
        $db->exec('UPDATE accounts SET balance = balance - 30 WHERE id = 1');
        $db->exec('UPDATE accounts SET balance = balance + 30 WHERE id = 2');
        $db->commit();
        unset($db);

        $db = $this->open();
        self::assertSame([70, 30], $db->query('SELECT balance FROM accounts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(100, (int) $db->query('SELECT sum(balance) FROM accounts')->fetchColumn());
        self::assertSame('ok', $db->query('PRAGMA integrity_check')->fetchColumn());
        self::assertFileDoesNotExist($this->directory . '/state.sqlite-wal');
        self::assertFileDoesNotExist($this->directory . '/state.sqlite-journal');
    }

    public function testExistingWalRequiresOfflineMigrationWithoutDiscardingCommittedFrames(): void
    {
        $path = $this->directory . '/state.sqlite';
        $legacy = new PDO('sqlite:' . $path);
        self::assertSame('wal', $legacy->query('PRAGMA journal_mode=WAL')->fetchColumn());
        $legacy->exec('PRAGMA wal_autocheckpoint=0');
        $this->seedAccounts($legacy);
        self::assertFileExists($path . '-wal');
        $before = [hash_file('sha256', $path), hash_file('sha256', $path . '-wal')];

        $db = new PDO('sqlite:' . $path);
        try {
            SqliteDurability::configure($db);
            self::fail('Runtime startup must not migrate a legacy WAL database.');
        } catch (RuntimeException $error) {
            self::assertSame('SQLITE_OFFLINE_ROLLBACK_MIGRATION_REQUIRED', $error->getMessage());
        }
        self::assertSame('wal', $db->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame([100, 0], $db->query('SELECT balance FROM accounts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame($before, [hash_file('sha256', $path), hash_file('sha256', $path . '-wal')]);
    }

    public function testMemoryAndTemporaryStoresAreNotDurableProductionState(): void
    {
        foreach (['sqlite::memory:', 'sqlite:'] as $dsn) {
            try {
                SqliteDurability::configure(new PDO($dsn));
                self::fail('An ephemeral store was accepted as durable state.');
            } catch (RuntimeException $error) {
                self::assertSame('SQLITE_DURABLE_FILE_REQUIRED', $error->getMessage());
            }
        }
    }

    public function testRepeatedStateAccessCannotBypassWalMigrationRejection(): void
    {
        $path = $this->directory . '/state.sqlite';
        $legacy = new PDO('sqlite:' . $path);
        $legacy->query('PRAGMA journal_mode=WAL')->fetchColumn();
        $legacy->exec('CREATE TABLE untouched (value INTEGER)');
        $state = new StateSqlite($path);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $state->upsertAccount(str_repeat('a', 32), 100, 0);
                self::fail('A failed lazy-open must not leave a usable WAL connection.');
            } catch (RuntimeException $error) {
                self::assertSame('SQLITE_OFFLINE_ROLLBACK_MIGRATION_REQUIRED', $error->getMessage());
            }
        }
        self::assertSame(['untouched'], $legacy->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testConfigurationCannotImplicitlyCommitAnExistingTransaction(): void
    {
        $db = $this->open();
        $this->seedAccounts($db);
        $db->beginTransaction();
        $db->exec('UPDATE accounts SET balance = 0 WHERE id = 1');
        try {
            SqliteDurability::configure($db);
            self::fail('Configuration in a transaction must fail closed.');
        } catch (RuntimeException $error) {
            self::assertSame('SQLITE_DURABILITY_CONFIGURATION_INVALID', $error->getMessage());
        }
        self::assertTrue($db->inTransaction());
        $db->rollBack();
        self::assertSame(100, (int) $db->query('SELECT sum(balance) FROM accounts')->fetchColumn());
    }

    public function testPrivateProviderJournalsRejectUnsafeRollbackSidecarsBeforeOpeningSqlite(): void
    {
        $hex = str_repeat('a', 64);
        $scope = ['chain' => 'rollback-test', 'genesis' => $hex, 'provider_id' => $hex,
            'workload_id' => $hex, 'assignment_generation' => $hex, 'commitment' => $hex,
            'catalog_hash' => $hex, 'manifest_hash' => $hex,
            'interval_start_height' => 1, 'interval_end_height' => 2];
        $constructors = [
            'allocations.sqlite' => fn() => new ResourceAllocationJournal($this->directory, []),
            'resource-telemetry.sqlite' => fn() => new ResourceTelemetryJournal($this->directory),
            'operations.sqlite' => fn() => new ServiceOperationMeter($this->directory, $scope),
            'settlement-outbox.sqlite' => fn() => new SettlementOutbox($this->directory, 'rollback-test', $hex),
            'system-service-installs.sqlite' => fn() => new SystemServiceInstallJournal($this->directory, 'rollback-test', $hex),
        ];
        $unrelated = $this->directory . '/unrelated';
        file_put_contents($unrelated, 'preserve this synthetic file');
        chmod($unrelated, 0600);
        foreach ($constructors as $name => $construct) {
            $journal = $this->directory . '/' . $name . '-journal';
            foreach (['symlink', 'public-file'] as $kind) {
                if ($kind === 'symlink') {
                    symlink($unrelated, $journal);
                } else {
                    file_put_contents($journal, 'preserve this synthetic file');
                    chmod($journal, 0644);
                }
                clearstatcache();
                try {
                    $construct();
                    self::fail('Unsafe rollback sidecar was accepted: ' . $name);
                } catch (RuntimeException $error) {
                    self::assertStringStartsWith('Unsafe ', $error->getMessage());
                }
                self::assertSame('preserve this synthetic file', file_get_contents($unrelated));
                self::assertSame('preserve this synthetic file', file_get_contents($journal));
                self::assertFileDoesNotExist($this->directory . '/' . $name);
                unlink($journal);
            }
        }
    }

    public function testConcurrentWritersRemainSerializedAndCanRetryAfterCommit(): void
    {
        $first = $this->open();
        $this->seedAccounts($first);
        $second = $this->open(50);
        $first->exec('BEGIN IMMEDIATE');
        $first->exec('UPDATE accounts SET balance = balance - 30 WHERE id = 1');
        $first->exec('UPDATE accounts SET balance = balance + 30 WHERE id = 2');
        try {
            $second->exec('BEGIN IMMEDIATE');
            self::fail('A second writer acquired the active writer lock.');
        } catch (PDOException $error) {
            self::assertSame(5, $error->errorInfo[1]); // SQLITE_BUSY
        }
        $first->exec('COMMIT');

        $second->exec('BEGIN IMMEDIATE');
        self::assertSame([70, 30], $second->query('SELECT balance FROM accounts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $second->exec('UPDATE accounts SET balance = balance + 10 WHERE id = 1');
        $second->exec('UPDATE accounts SET balance = balance - 10 WHERE id = 2');
        $second->exec('COMMIT');
        self::assertSame([80, 20], $first->query('SELECT balance FROM accounts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testKilledWriterRecoversHotRollbackJournalWithoutPartialTransfer(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('proc_open')) {
            self::markTestSkipped('The process-crash regression requires Linux and proc_open.');
        }
        $db = $this->open();
        $this->seedAccounts($db);
        $db->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY, payload BLOB)');
        $db->exec('WITH RECURSIVE n(x) AS (VALUES(1) UNION ALL SELECT x+1 FROM n WHERE x<256) INSERT INTO pages SELECT x, zeroblob(8192) FROM n');
        unset($db);

        $bootstrap = dirname(__DIR__, 3) . '/scripts/economics-bootstrap.php';
        $script = 'require ' . var_export($bootstrap, true) . ';'
            . '$db = new PDO(' . var_export('sqlite:' . $this->directory . '/state.sqlite', true) . ');'
            . '\\Greenwood\\Persistence\\SqliteDurability::configure($db);'
            . '$db->exec("PRAGMA cache_size=1"); $db->exec("PRAGMA cache_spill=ON");'
            . '$db->beginTransaction();'
            . '$db->exec("UPDATE accounts SET balance = balance - 30 WHERE id = 1");'
            . '$db->exec("UPDATE pages SET payload = randomblob(8192)");'
            . 'fwrite(STDOUT, "READY\\n"); fflush(STDOUT); fgets(STDIN);';
        $process = proc_open([PHP_BINARY, '-r', $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            $read = [$pipes[1]];
            $write = $except = [];
            self::assertSame(1, stream_select($read, $write, $except, 10));
            self::assertSame("READY\n", fgets($pipes[1]));
            self::assertFileExists($this->directory . '/state.sqlite-journal');
            self::assertGreaterThan(512, filesize($this->directory . '/state.sqlite-journal'));
            self::assertTrue(proc_terminate($process, 9));
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }

        // This is process-crash recovery, not a claim of physical power-loss qualification.
        $db = $this->open();
        self::assertSame([100, 0], $db->query('SELECT balance FROM accounts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(0, (int) $db->query('SELECT count(*) FROM pages WHERE payload != zeroblob(8192)')->fetchColumn());
        self::assertSame('ok', $db->query('PRAGMA integrity_check')->fetchColumn());
        self::assertFileDoesNotExist($this->directory . '/state.sqlite-journal');
        self::assertFileDoesNotExist($this->directory . '/state.sqlite-wal');
    }
}
