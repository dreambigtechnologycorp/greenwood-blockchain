<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Util;

/** Invoke only AFTER authenticating and deriving the account ID from the signer. */
final class RateLimiter
{
    public function __construct(private readonly \PDO $db, private readonly int $limit=100, private readonly int $window=60) {
        if ($limit<1 || $window<1) throw new \InvalidArgumentException('Invalid rate policy');
        $db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout=5000');
        $db->exec('CREATE TABLE IF NOT EXISTS account_rate (account TEXT NOT NULL, time INTEGER NOT NULL)');
        $db->exec('CREATE INDEX IF NOT EXISTS account_rate_lookup ON account_rate(account,time)');
    }
    public function isAllowed(string $accountId): bool {
        if (!preg_match('/^[a-f0-9]{64}$/D',$accountId)) throw new \InvalidArgumentException('Authenticated account ID required');
        $now=time(); $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->db->prepare('DELETE FROM account_rate WHERE time<=?'); $q->execute([$now-$this->window]);
            $q=$this->db->prepare('SELECT COUNT(*) FROM account_rate WHERE account=?'); $q->execute([$accountId]);
            $allowed=(int)$q->fetchColumn()<$this->limit;
            if ($allowed) { $q=$this->db->prepare('INSERT INTO account_rate VALUES (?,?)'); $q->execute([$accountId,$now]); }
            $this->db->exec('COMMIT'); return $allowed;
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
}
