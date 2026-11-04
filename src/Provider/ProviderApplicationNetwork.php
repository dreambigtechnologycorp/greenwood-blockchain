<?php

declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Compute\Containerd\OciImageRuntimeConfig;
use PDO;

/** Durable network effects, separate from container RUNNING and public publication. */
final class ProviderApplicationNetwork
{
    private PDO $db;
    public function __construct(
        private readonly string $directory,
        private readonly ProviderApplicationBindingRegistry $bindings,
        private readonly \Closure $attach,
        private readonly \Closure $probe,
        private readonly \Closure $detach,
    ) {
        $this->db = new PDO('sqlite:' . $directory . '/network.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS networks (application_id INTEGER, service TEXT, record TEXT NOT NULL, PRIMARY KEY(application_id,service))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS network_events (id INTEGER PRIMARY KEY, application_id INTEGER, service TEXT, recorded_at INTEGER, record TEXT NOT NULL)');
    }

    public function reserve(int $id, string $service, OciImageRuntimeConfig $image, ?string $imageDigest = null): void
    {
        $existing = $this->find($id, $service);
        if ($imageDigest !== null && !preg_match('/^sha256:[a-f0-9]{64}$/D', $imageDigest)) throw new \InvalidArgumentException('Invalid reservation image digest');
        if ($imageDigest !== null && ($existing['state'] ?? null) === 'RESERVED'
            && isset($existing['reservation_image_digest']) && $existing['reservation_image_digest'] !== $imageDigest) {
            throw new \RuntimeException('Reserved image conflicts');
        }
        $binding = $this->bindings->reserve($id, $service, $image, ($existing['state'] ?? null) === 'DETACHED');
        if ($existing === null || $existing['state'] === 'DETACHED') {
            if ($imageDigest !== null) $binding['reservation_image_digest'] = $imageDigest;
            $this->save($binding + ['ready' => false]);
        }
    }

    public function reconcile(array $runtime, \Closure $inspect): array
    {
        return $this->locked(function () use ($runtime, $inspect): array {
            $row = $this->find((int)$runtime['application_id'], $runtime['service'])
                ?? throw new \RuntimeException('Network reservation missing');
            // A crash after this write is explicitly not healthy.
            $row['state'] = 'ATTACHING'; $row['ready'] = false; unset($row['error_class']);
            $row['image_digest'] = $runtime['image_digest'];
            $row = $this->save($row);
            try {
                $observation = $inspect($runtime);
                if (($observation['runtime_state'] ?? null) !== 'RUNNING' || (int)($observation['pid'] ?? 0) < 1) {
                    throw new \RuntimeException('Network requires observed running runtime');
                }
                $row['pid'] = (int)$observation['pid'];
                $effect = ($this->attach)($row, $row['pid']);
                if (!is_array($effect) || !is_string($effect['namespace_identity'] ?? null)) {
                    throw new \RuntimeException('Network attachment not confirmed');
                }
                $row['namespace_identity'] = $effect['namespace_identity'];
                $row['state'] = 'ATTACHED'; $row = $this->save($row);
                $again = $inspect($runtime);
                if (($again['runtime_state'] ?? null) !== 'RUNNING' || ($again['pid'] ?? null) !== $row['pid']
                    || ($this->probe)($row) !== true) {
                    throw new \RuntimeException('Network health not confirmed');
                }
                $row['state'] = 'HEALTHY'; $row['ready'] = true; $row = $this->save($row);
                return $row;
            } catch (\Throwable $e) {
                $row['state'] = 'RECONCILE_REQUIRED'; $row['ready'] = false;
                $row['error_class'] = $e::class; $row = $this->save($row);
                throw $e;
            }
        });
    }

    public function cleanup(int $id, string $service): ?array
    {
        return $this->locked(function () use ($id, $service): ?array {
            $row = $this->find($id, $service);
            if ($row === null) return null;
            $row['state'] = 'DETACHING'; $row['ready'] = false; unset($row['error_class']); $row = $this->save($row);
            try {
                if (($this->detach)($row) !== true) throw new \RuntimeException('Network cleanup not confirmed');
                $row['state'] = 'DETACHED'; $row = $this->save($row);
            } catch (\Throwable $e) {
                $row['state'] = 'CLEANUP_REQUIRED'; $row['error_class'] = $e::class; $row = $this->save($row);
                throw $e;
            }
            return $row;
        });
    }

    public function find(int $id, string $service): ?array
    {
        $q = $this->db->prepare('SELECT record FROM networks WHERE application_id=? AND service=?'); $q->execute([$id, $service]);
        $v = $q->fetchColumn(); return $v === false ? null : json_decode($v, true, 512, JSON_THROW_ON_ERROR);
    }

    public function invalidate(int $id, string $service): void
    {
        $this->locked(function () use ($id, $service): void {
            $row = $this->find($id, $service);
            if ($row !== null) {
                $row['state'] = 'RECONCILE_REQUIRED';
                $row['ready'] = false;
                $this->save($row);
            }
        });
    }

    /** Persisted observations are evidence, never a substitute for a fresh health check. */
    public function observe(int $id, string $service): ?array
    {
        $row = $this->find($id, $service);
        if ($row !== null) {
            $row['ready'] = false;
            if ($row['state'] === 'HEALTHY') {
                try { $row['ready'] = ($this->probe)($row) === true; } catch (\Throwable) {}
            }
            $row['observed_at'] = time();
        }
        return $row;
    }

    private function save(array $row): array
    {
        $row['observed_at'] = time();
        $json = json_encode($row, JSON_THROW_ON_ERROR);
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('INSERT INTO networks VALUES (?,?,?) ON CONFLICT(application_id,service) DO UPDATE SET record=excluded.record');
            $q->execute([$row['application_id'], $row['service'], $json]);
            $q = $this->db->prepare('INSERT INTO network_events(application_id,service,recorded_at,record) VALUES (?,?,?,?)');
            $q->execute([$row['application_id'], $row['service'], $row['observed_at'], $json]);
            $this->db->commit();
            return $row;
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    private function locked(\Closure $op): mixed
    {
        $f = fopen($this->directory . '/network.lock', 'c');
        if ($f === false) throw new \RuntimeException('Network lock unavailable');
        try {
            if (!flock($f, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Network reconciliation busy');
            return $op();
        } finally { flock($f, LOCK_UN); fclose($f); }
    }
}
