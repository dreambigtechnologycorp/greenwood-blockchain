<?php

declare(strict_types=1);

namespace Greenwood\Edge;

use PDO;

/** Edge-local desired state. Only the authenticated controller may write it. */
final class EdgeRouteRegistry
{
    private PDO $db;

    public function __construct(private readonly string $directory, private readonly string $domain)
    {
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/D', $domain)
            || strlen($domain) > 190) {
            throw new \InvalidArgumentException('Invalid managed edge domain');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create edge registry directory');
        }
        $this->db = new PDO('sqlite:' . $directory . '/routes.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS edge_policy (id INTEGER PRIMARY KEY CHECK(id=1), domain TEXT NOT NULL)');
        $insert = $this->db->prepare('INSERT OR IGNORE INTO edge_policy VALUES (1, ?)');
        $insert->execute([$domain]);
        if ($this->db->query('SELECT domain FROM edge_policy WHERE id=1')->fetchColumn() !== $domain) {
            throw new \RuntimeException('Edge domain change requires migration');
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS edge_routes (hostname TEXT PRIMARY KEY, record TEXT NOT NULL)');
    }

    public function hostname(int $applicationId, string $service): string
    {
        if ($applicationId < 1 || $applicationId > 0xffffffff
            || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $service)) {
            throw new \InvalidArgumentException('Service requires a canonical DNS label');
        }
        $label = 'gw-' . $applicationId . '-' . $service;
        if (strlen($label) > 63) {
            throw new \InvalidArgumentException('Managed hostname label too long');
        }
        return $label . '.' . $this->domain;
    }

    /** Serializes proxy reconciliation with desired-state changes across processes. */
    public function exclusive(\Closure $operation): mixed
    {
        $lock = fopen($this->directory . '/reconcile.lock', 'c');
        if ($lock === false) throw new \RuntimeException('Cannot open edge lock');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Edge reconciliation busy');
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Internal persistence API; callers hold exclusive(). Not a public request schema. */
    public function save(array $route): void
    {
        if ($this->hostname($route['application_id'], $route['service']) !== $route['hostname']) {
            throw new \InvalidArgumentException('Route ownership mismatch');
        }
        $q = $this->db->prepare('INSERT INTO edge_routes VALUES (?, ?) ON CONFLICT(hostname) DO UPDATE SET record=excluded.record');
        $q->execute([$route['hostname'], json_encode($route, JSON_THROW_ON_ERROR)]);
    }

    public function find(int $applicationId, string $service): ?array
    {
        $q = $this->db->prepare('SELECT record FROM edge_routes WHERE hostname=?');
        $q->execute([$this->hostname($applicationId, $service)]);
        $record = $q->fetchColumn();
        return $record === false ? null : json_decode($record, true, 512, JSON_THROW_ON_ERROR);
    }

    public function all(): array
    {
        return array_map(static fn(string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            $this->db->query('SELECT record FROM edge_routes ORDER BY hostname')->fetchAll(PDO::FETCH_COLUMN));
    }
}
