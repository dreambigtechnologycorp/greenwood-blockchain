<?php

declare(strict_types=1);

namespace Greenwood\Provider;

use PDO;

final class ProviderApplicationRegistry
{
    private PDO $db;

    private const STATES = [
        'STARTING',
        'RUNNING',
        'STOPPING',
        'STOPPED',
        'FAILED',
    ];

    public function __construct(
        private readonly string $directory
    ) {
        if (
            !is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new \RuntimeException(
                'Cannot create provider application registry directory'
            );
        }

        $this->db = new PDO(
            'sqlite:' . $directory . '/applications.sqlite',
            null,
            null,
            [
                PDO::ATTR_ERRMODE =>
                    PDO::ERRMODE_EXCEPTION,
            ]
        );

        $this->db->exec(
            'PRAGMA journal_mode=WAL'
        );

        $this->db->exec(
            'PRAGMA synchronous=FULL'
        );

        $this->db->exec(
            'PRAGMA busy_timeout=5000'
        );

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS provider_applications (
                application_id INTEGER NOT NULL,
                service TEXT NOT NULL,
                service_id TEXT NOT NULL UNIQUE,
                image_ref TEXT NOT NULL,
                image_digest TEXT NOT NULL,
                state TEXT NOT NULL,
                container_id TEXT,
                view_key TEXT,
                parent_snapshot TEXT,
                lease_id TEXT,
                pid INTEGER,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY(application_id, service)
            )'
        );
    }

    /** Serialize the whole runtime/network mutation, not just SQLite writes. */
    public function exclusive(int $applicationId, string $service, \Closure $operation): mixed
    {
        $file = $this->directory . '/operation-' . hash('sha256', $applicationId . "\0" . $service) . '.lock';
        $lock = fopen($file, 'c');
        if ($lock === false) throw new \RuntimeException('Application operation lock unavailable');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Application operation busy');
            return $operation();
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function beginStart(
        int $applicationId,
        string $service,
        string $serviceId,
        string $imageRef,
        string $imageDigest
    ): array {
        $this->validateIdentity(
            $applicationId,
            $service,
            $serviceId,
            $imageRef,
            $imageDigest
        );

        $this->db->exec(
            'BEGIN IMMEDIATE'
        );

        try {
            $existing =
                $this->find(
                    $applicationId,
                    $service
                );

            if ($existing === null) {
                $now = time();

                $q = $this->db->prepare(
                    'INSERT INTO provider_applications(
                        application_id,
                        service,
                        service_id,
                        image_ref,
                        image_digest,
                        state,
                        container_id,
                        view_key,
                        lease_id,
                        created_at,
                        updated_at
                    ) VALUES (?,?,?,?,?,\'STARTING\',?,?,?,?,?)'
                );

                $q->execute([
                    $applicationId,
                    $service,
                    $serviceId,
                    $imageRef,
                    $imageDigest,
                    $serviceId,
                    'gw-system-view-' . $serviceId,
                    'gw-system-lease-' . $serviceId,
                    $now,
                    $now,
                ]);

                $this->db->commit();

                return $this->get(
                    $applicationId,
                    $service
                );
            }

            if (
                in_array(
                    $existing['state'],
                    ['STARTING', 'RUNNING', 'STOPPING'],
                    true
                )
            ) {
                if (
                    $existing['service_id'] !== $serviceId
                    || $existing['image_ref'] !== $imageRef
                    || $existing['image_digest'] !== $imageDigest
                ) {
                    throw new \RuntimeException(
                        'Application service already owns another runtime'
                    );
                }

                $this->db->commit();

                return $existing;
            }

            $q = $this->db->prepare(
                'UPDATE provider_applications
                 SET service_id=?,
                     image_ref=?,
                     image_digest=?,
                     state=\'STARTING\',
                     container_id=?,
                     view_key=?,
                     parent_snapshot=NULL,
                     lease_id=?,
                     pid=NULL,
                     updated_at=?
                 WHERE application_id=?
                   AND service=?
                   AND state IN (\'STOPPED\',\'FAILED\')'
            );

            $q->execute([
                $serviceId,
                $imageRef,
                $imageDigest,
                $serviceId,
                'gw-system-view-' . $serviceId,
                'gw-system-lease-' . $serviceId,
                time(),
                $applicationId,
                $service,
            ]);

            if ($q->rowCount() !== 1) {
                throw new \RuntimeException(
                    'Application service state changed'
                );
            }

            $this->db->commit();

            return $this->get(
                $applicationId,
                $service
            );
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $error;
        }
    }

    public function markRunning(
        int $applicationId,
        string $service,
        string $containerId,
        string $viewKey,
        string $parentSnapshot,
        string $leaseId,
        int $pid
    ): array {
        foreach (
            [
                $containerId,
                $viewKey,
                $parentSnapshot,
                $leaseId,
            ]
            as $value
        ) {
            if (
                $value === ''
                || str_contains($value, "\0")
            ) {
                throw new \InvalidArgumentException(
                    'Invalid application runtime handle'
                );
            }
        }

        if ($pid < 1) {
            throw new \InvalidArgumentException(
                'Invalid application runtime PID'
            );
        }

        $q = $this->db->prepare(
            'UPDATE provider_applications
             SET state=\'RUNNING\',
                 container_id=?,
                 view_key=?,
                 parent_snapshot=?,
                 lease_id=?,
                 pid=?,
                 updated_at=?
             WHERE application_id=?
               AND service=?
               AND state=\'STARTING\''
        );

        $q->execute([
            $containerId,
            $viewKey,
            $parentSnapshot,
            $leaseId,
            $pid,
            time(),
            $applicationId,
            $service,
        ]);

        if ($q->rowCount() !== 1) {
            throw new \RuntimeException(
                'Application start intent is missing or changed'
            );
        }

        return $this->get(
            $applicationId,
            $service
        );
    }

    public function markStopping(
        int $applicationId,
        string $service
    ): array {
        $row =
            $this->get(
                $applicationId,
                $service
            );

        if ($row['state'] === 'STOPPING') {
            return $row;
        }

        if ($row['state'] !== 'RUNNING') {
            throw new \RuntimeException(
                'Application service is not running'
            );
        }

        $q = $this->db->prepare(
            'UPDATE provider_applications
             SET state=\'STOPPING\',
                 updated_at=?
             WHERE application_id=?
               AND service=?
               AND state=\'RUNNING\''
        );

        $q->execute([
            time(),
            $applicationId,
            $service,
        ]);

        if ($q->rowCount() !== 1) {
            throw new \RuntimeException(
                'Application service state changed'
            );
        }

        return $this->get(
            $applicationId,
            $service
        );
    }

    public function markStopped(
        int $applicationId,
        string $service
    ): array {
        $q = $this->db->prepare(
            'UPDATE provider_applications
             SET state=\'STOPPED\',
                 updated_at=?
             WHERE application_id=?
               AND service=?
               AND state=\'STOPPING\''
        );

        $q->execute([
            time(),
            $applicationId,
            $service,
        ]);

        if ($q->rowCount() !== 1) {
            throw new \RuntimeException(
                'Application stop intent is missing or changed'
            );
        }

        return $this->get(
            $applicationId,
            $service
        );
    }

    public function markFailed(
        int $applicationId,
        string $service
    ): array {
        $q = $this->db->prepare(
            'UPDATE provider_applications
             SET state=\'FAILED\',
                 updated_at=?
             WHERE application_id=?
               AND service=?
               AND state IN (\'STARTING\',\'RUNNING\',\'STOPPING\')'
        );

        $q->execute([
            time(),
            $applicationId,
            $service,
        ]);

        if ($q->rowCount() !== 1) {
            throw new \RuntimeException(
                'Application failure state is not transitionable'
            );
        }

        return $this->get(
            $applicationId,
            $service
        );
    }

    public function get(
        int $applicationId,
        string $service
    ): array {
        return $this->find(
            $applicationId,
            $service
        ) ?? throw new \InvalidArgumentException(
            'Unknown provider application service'
        );
    }

    public function find(
        int $applicationId,
        string $service
    ): ?array {
        $q = $this->db->prepare(
            'SELECT *
             FROM provider_applications
             WHERE application_id=?
               AND service=?'
        );

        $q->execute([
            $applicationId,
            $service,
        ]);

        $row = $q->fetch(
            PDO::FETCH_ASSOC
        );

        return $row ?: null;
    }

    /** Refresh a confirmed restart without changing deployment identity or start intent. */
    public function refreshRunningPid(int $id, string $service, int $pid): array
    {
        if ($pid < 1) throw new \InvalidArgumentException('Invalid runtime PID');
        $q = $this->db->prepare("UPDATE provider_applications SET pid=?,updated_at=? WHERE application_id=? AND service=? AND state='RUNNING'");
        $q->execute([$pid, time(), $id, $service]);
        if ($q->rowCount() !== 1) throw new \RuntimeException('Running application intent changed');
        return $this->get($id, $service);
    }

    public function active(): array
    {
        $q = $this->db->query(
            'SELECT *
             FROM provider_applications
             WHERE state IN (
                 \'STARTING\',
                 \'RUNNING\',
                 \'STOPPING\'
             )
             ORDER BY application_id, service'
        );

        return $q->fetchAll(
            PDO::FETCH_ASSOC
        );
    }

    private function validateIdentity(
        int $applicationId,
        string $service,
        string $serviceId,
        string $imageRef,
        string $imageDigest
    ): void {
        if (
            $applicationId < 1
            || $applicationId > 0xffffffff
        ) {
            throw new \InvalidArgumentException(
                'Invalid application ID'
            );
        }

        if (
            !preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D',
                $service
            )
        ) {
            throw new \InvalidArgumentException(
                'Invalid application service'
            );
        }

        if (
            !preg_match(
                '/^[a-z0-9][a-z0-9._-]{0,62}$/D',
                $serviceId
            )
        ) {
            throw new \InvalidArgumentException(
                'Invalid runtime service ID'
            );
        }

        if (
            !preg_match(
                '/^sha256:[a-f0-9]{64}$/D',
                $imageDigest
            )
            || !str_ends_with(
                $imageRef,
                '@' . $imageDigest
            )
        ) {
            throw new \InvalidArgumentException(
                'Application image must be digest-qualified'
            );
        }
    }
}
