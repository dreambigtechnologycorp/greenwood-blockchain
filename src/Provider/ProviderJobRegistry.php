<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Compute\Codec\ComputeReceiptV2Codec;
use Greenwood\Transaction\Envelope;
use PDO;

/** Provider-local durable intent and outbox. Never included in consensus state. */
final class ProviderJobRegistry
{
    private PDO $db;
    private mixed $lock = null;
    private mixed $identityLock = null;
    private const TRANSITIONS = [
        'DISCOVERED' => ['QUEUED', 'STALE', 'FAILED_LOCAL'],
        'QUEUED' => ['STARTING', 'STALE', 'FAILED_LOCAL'],
        'STARTING' => ['RUNNING', 'FAILED_LOCAL', 'STALE'],
        'RUNNING' => ['RECEIPT_READY', 'FAILED_LOCAL', 'STALE'],
        'RECEIPT_READY' => ['SUBMITTING', 'SETTLED', 'STALE', 'FAILED_LOCAL'],
        'SUBMITTING' => ['SUBMITTED', 'SETTLED', 'STALE', 'RECEIPT_READY'],
        'SUBMITTED' => ['SETTLED', 'STALE', 'RECEIPT_READY'],
        'SETTLED' => [], 'STALE' => [], 'FAILED_LOCAL' => [],
    ];

    public function __construct(string $directory, private readonly string $chainId, private readonly string $providerId)
    {
        if ($chainId === '' || strlen($providerId) !== 32) throw new \InvalidArgumentException('Invalid registry identity');
        // The signing lane is exclusive across data directories too. Production
        // supervisors run as root; workers cannot open this runtime lock directory.
        if (PHP_OS_FAMILY === 'Linux' && posix_geteuid() === 0) {
            $guard = '/run/greenwood-provider';
            if (!is_dir($guard) && !mkdir($guard, 0700) && !is_dir($guard)) throw new \RuntimeException('Cannot create provider ownership directory');
            $this->identityLock = fopen($guard . '/' . bin2hex($providerId) . '.lock', 'c');
            if ($this->identityLock === false || !flock($this->identityLock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Provider identity already has a supervisor');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new \RuntimeException('Cannot create provider data directory');
        $this->lock = fopen($directory . '/supervisor.lock', 'c');
        if ($this->lock === false || !flock($this->lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Provider data directory already has a supervisor');
        $this->db = new PDO('sqlite:' . $directory . '/provider.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA synchronous=FULL');
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY, value BLOB NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS provider_jobs (
            position INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id BLOB NOT NULL, generation INTEGER NOT NULL,
            provider_id BLOB NOT NULL, state TEXT NOT NULL,
            received_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
            execution_id TEXT, receipt BLOB, transaction_bytes BLOB,
            UNIQUE(request_id, generation))');
        $columns = array_column($this->db->query('PRAGMA table_info(provider_jobs)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach (['worker_pid' => 'INTEGER', 'last_error' => 'TEXT', 'protocol_execution_id' => 'BLOB', 'transaction_hash' => 'BLOB', 'receipt_id' => 'BLOB', 'worker_started_ns' => 'INTEGER', 'worker_launches' => 'INTEGER NOT NULL DEFAULT 0'] as $column => $type) if (!in_array($column, $columns, true)) $this->db->exec('ALTER TABLE provider_jobs ADD COLUMN ' . $column . ' ' . $type);
        // Only one transaction may own the provider nonce until finality/reconciliation.
        $this->db->exec("CREATE UNIQUE INDEX IF NOT EXISTS one_submission ON provider_jobs((1)) WHERE state IN ('SUBMITTING','SUBMITTED')");
        foreach (['chain_id' => $chainId, 'provider_id' => $providerId] as $name => $value) {
            $q = $this->db->prepare('INSERT OR IGNORE INTO metadata VALUES (?,?)'); $q->execute([$name, $value]);
            $q = $this->db->prepare('SELECT value FROM metadata WHERE key=?'); $q->execute([$name]);
            if ($q->fetchColumn() !== $value) throw new \RuntimeException('Provider registry belongs to another chain or identity');
        }
    }

    public function discover(string $requestId, int $generation): void
    {
        if (strlen($requestId) !== 32 || $generation < 1 || $generation > 0xffffffff) throw new \InvalidArgumentException('Invalid provider job key');
        $q = $this->db->prepare("INSERT OR IGNORE INTO provider_jobs(request_id,generation,provider_id,state,received_at,updated_at) VALUES (?,?,?,'DISCOVERED',?,?)");
        $q->execute([$requestId, $generation, $this->providerId, time(), time()]);
    }

    public function get(string $requestId, int $generation): array
    {
        $q = $this->db->prepare('SELECT * FROM provider_jobs WHERE request_id=? AND generation=?');
        $q->execute([$requestId, $generation]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: throw new \InvalidArgumentException('Unknown provider job');
    }

    public function jobs(): array { return $this->db->query('SELECT * FROM provider_jobs ORDER BY position')->fetchAll(PDO::FETCH_ASSOC); }

    public function transition(string $requestId, int $generation, string $from, string $to, ?string $executionId = null, ?string $receipt = null, ?string $transaction = null, ?int $workerPid = null, ?string $error = null): void
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) throw new \InvalidArgumentException('Invalid provider job transition');
        if ($to === 'STARTING' && ($executionId === null || $executionId === '')) throw new \InvalidArgumentException('Persist execution intent before launch');
        if ($to !== 'STARTING' && $executionId !== null) throw new \InvalidArgumentException('Execution identity replacement is prohibited');
        if ($to === 'RECEIPT_READY' && $from === 'RUNNING') {
            $value = ComputeReceiptV2Codec::decode($receipt ?? '');
            if ($value->body()->getRequestId() !== $requestId || $value->generation !== $generation || $value->body()->getProviderId() !== $this->providerId) throw new \InvalidArgumentException('Receipt does not match local job');
        } elseif ($receipt !== null) throw new \InvalidArgumentException('Receipt replacement is prohibited');
        if ($to === 'SUBMITTING') {
            $tx = Envelope::decode($transaction ?? ''); $tx->verify($this->chainId);
            $job = $this->get($requestId, $generation);
            if ($tx->getType() !== 9 || $tx->getPayload() !== $job['receipt']
                || \Greenwood\Compute\Protocol\ProviderId::fromPublicKey($tx->getSenderPublicKey())->toBytes() !== $this->providerId) throw new \InvalidArgumentException('Invalid settlement transaction');
            if (!ComputeReceiptV2Codec::decode($tx->getPayload())->verifySignature($tx->getSenderPublicKey())) throw new \InvalidArgumentException('Invalid durable receipt signature');
        } elseif ($transaction !== null) throw new \InvalidArgumentException('Transaction replacement is prohibited');
        if (($workerPid !== null && $workerPid < 1) || ($error !== null && strlen($error) > 512)) throw new \InvalidArgumentException('Invalid worker metadata');
        $q = $this->db->prepare('UPDATE provider_jobs SET state=?,updated_at=?,execution_id=COALESCE(?,execution_id),receipt=COALESCE(?,receipt),transaction_bytes=COALESCE(?,transaction_bytes),worker_pid=COALESCE(?,worker_pid),last_error=?,protocol_execution_id=COALESCE(?,protocol_execution_id),transaction_hash=COALESCE(?,transaction_hash),receipt_id=COALESCE(?,receipt_id),worker_started_ns=COALESCE(?,worker_started_ns) WHERE request_id=? AND generation=? AND state=?');
        $q->execute([$to, time(), $executionId, $receipt, $transaction, $workerPid, $error,
            $executionId === null ? null : ProviderContent::executionId($executionId),
            $transaction === null ? null : Envelope::decode($transaction)->getHash(),
            $receipt === null ? null : ComputeReceiptV2Codec::decode($receipt)->getId(),
            $to === 'STARTING' ? hrtime(true) : null,
            $requestId, $generation, $from]);
        if ($q->rowCount() !== 1) throw new \RuntimeException('Provider job state changed or is missing');
    }

    public function claimed(string $requestId, int $generation, string $runtimeId): array
    {
        $job = $this->get($requestId, $generation);
        if (!in_array($job['state'], ['STARTING', 'RUNNING'], true) || $job['execution_id'] !== $runtimeId
            || $job['protocol_execution_id'] !== ProviderContent::executionId($runtimeId)) throw new \RuntimeException('Worker requires matching durable launch claim');
        return $job;
    }
    public function workerStarted(string $id, int $generation, int $pid): void
    {
        if ($pid < 1) throw new \InvalidArgumentException('Invalid worker PID');
        $q = $this->db->prepare("UPDATE provider_jobs SET worker_pid=?,updated_at=?,worker_started_ns=?,worker_launches=worker_launches+1 WHERE request_id=? AND generation=? AND state IN ('STARTING','RUNNING')");
        $q->execute([$pid, time(), hrtime(true), $id, $generation]);
        if ($q->rowCount() !== 1) throw new \RuntimeException('Worker no longer owned');
    }
    public function noteFailure(string $id, int $generation, string $message): void
    {
        if (strlen($message) > 512) throw new \InvalidArgumentException('Failure metadata exceeds limit');
        $q = $this->db->prepare("UPDATE provider_jobs SET last_error=? WHERE request_id=? AND generation=? AND state IN ('STARTING','RUNNING')");
        $q->execute([$message, $id, $generation]);
    }

    public function observeHeight(int $height): void
    {
        if ($height < $this->observedHeight()) throw new \InvalidArgumentException('Finalized height regression');
        $q = $this->db->prepare("INSERT INTO metadata VALUES ('finalized_height',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
        $q->execute([(string)$height]);
    }
    public function observedHeight(): int { return (int)($this->db->query("SELECT value FROM metadata WHERE key='finalized_height'")->fetchColumn() ?: 0); }
    public function setDrain(bool $enabled): void
    {
        $q = $this->db->prepare("INSERT INTO metadata VALUES ('drain',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value"); $q->execute([$enabled ? '1' : '0']);
    }
    public function isDraining(): bool { return $this->db->query("SELECT value FROM metadata WHERE key='drain'")->fetchColumn() === '1'; }
    public function __destruct()
    {
        foreach ([$this->lock, $this->identityLock] as $lock) if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
}
