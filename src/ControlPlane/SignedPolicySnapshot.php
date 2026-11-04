<?php
declare(strict_types=1);
namespace Greenwood\ControlPlane;

/** Independent policy signatures with durable revision and equivocation checks. */
final class SignedPolicySnapshot
{
    private readonly \PDO $db;

    public function __construct(
        private readonly string $path,
        string $ledger,
        private readonly string $purpose,
        private readonly string $issuerPublicKey,
        private readonly int $maxAge,
    ) {
        if (!in_array($purpose, ['revocations', 'allocations'], true)
            || !preg_match('/^[a-f0-9]{64}$/D', $issuerPublicKey) || $maxAge < 1 || $maxAge > 86400) {
            throw new \InvalidArgumentException('INVALID_POLICY_TRUST_CONFIGURATION');
        }
        $directory = dirname($ledger);
        if (is_link($directory) || !is_dir($directory) || (fileperms($directory) & 0077) !== 0
            || fileowner($directory) !== posix_geteuid() || is_link($ledger)
            || (file_exists($ledger) && ((fileperms($ledger) & 0077) !== 0 || fileowner($ledger) !== posix_geteuid()))) {
            throw new \RuntimeException('PRIVATE_POLICY_LEDGER_REQUIRED');
        }
        $this->db = new \PDO('sqlite:' . $ledger, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        chmod($ledger, 0600);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS policy_revisions(purpose TEXT PRIMARY KEY, revision INTEGER NOT NULL, digest TEXT NOT NULL)');
    }

    public static function signingBytes(array $payload): string
    {
        ksort($payload);
        return "GREENWOOD-CONTROL-POLICY-V1\n" . json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function read(): array
    {
        clearstatcache(true, $this->path);
        if (is_link($this->path) || !is_file($this->path) || filesize($this->path) > 1048576
            || (fileperms($this->path) & 0022) !== 0 || !in_array(fileowner($this->path), [0, posix_geteuid()], true)) {
            throw new \RuntimeException('UNSAFE_SIGNED_POLICY');
        }
        $record = json_decode(file_get_contents($this->path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($record) || count($record) !== 2 || !is_array($record['payload'] ?? null)
            || !is_string($record['signature'] ?? null) || !preg_match('/^[a-f0-9]{128}$/D', $record['signature'])) {
            throw new \RuntimeException('INVALID_SIGNED_POLICY');
        }
        $p = $record['payload'];
        $list = $this->purpose === 'revocations' ? 'revoked_sha256' : 'approved_generations';
        $keys = ['schema_version', 'purpose', 'chain_id', 'revision', 'issued_at', 'valid_until', $list];
        if (count($p) !== count($keys) || array_diff(array_keys($p), $keys)
            || ($p['schema_version'] ?? null) !== 1 || ($p['purpose'] ?? null) !== $this->purpose
            || ($p['chain_id'] ?? null) !== 'greenwood-physical-v1'
            || !is_int($p['revision'] ?? null) || $p['revision'] < 1
            || !is_int($p['issued_at'] ?? null) || !is_int($p['valid_until'] ?? null)
            || $p['issued_at'] > time() + 2 || $p['issued_at'] < time() - $this->maxAge
            || $p['valid_until'] <= time() || $p['valid_until'] <= $p['issued_at']
            || $p['valid_until'] - $p['issued_at'] > $this->maxAge
            || !is_array($p[$list] ?? null) || !array_is_list($p[$list])) {
            throw new \RuntimeException('STALE_OR_INVALID_SIGNED_POLICY');
        }
        foreach ($p[$list] as $value) {
            if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) {
                throw new \RuntimeException('INVALID_POLICY_ENTRY');
            }
        }
        $bytes = self::signingBytes($p);
        if (!sodium_crypto_sign_verify_detached(hex2bin($record['signature']), $bytes, hex2bin($this->issuerPublicKey))) {
            throw new \RuntimeException('POLICY_SIGNATURE_REJECTED');
        }
        $digest = hash('sha256', $bytes);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q = $this->db->prepare('SELECT revision,digest FROM policy_revisions WHERE purpose=?');
            $q->execute([$this->purpose]);
            $old = $q->fetch(\PDO::FETCH_ASSOC);
            if ($old && ((int)$old['revision'] > $p['revision']
                || ((int)$old['revision'] === $p['revision'] && !hash_equals($old['digest'], $digest)))) {
                throw new \RuntimeException('POLICY_ROLLBACK_OR_EQUIVOCATION');
            }
            $q = $this->db->prepare('INSERT OR REPLACE INTO policy_revisions VALUES(?,?,?)');
            $q->execute([$this->purpose, $p['revision'], $digest]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
        return $p;
    }
}
