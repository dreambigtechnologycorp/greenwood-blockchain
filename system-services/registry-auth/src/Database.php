<?php

declare(strict_types=1);

namespace Greenwood\SystemServices\RegistryAuth;

use PDO;

final class Database
{
    public static function open(string $path): PDO
    {
        $directory = dirname($path);
        if (!str_starts_with($path, '/') || is_link($directory) || is_link($path)) {
            throw new \RuntimeException('Protected absolute registry database required');
        }

        if (
            !is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new \RuntimeException(
                'Unable to create registry auth data directory'
            );
        }
        if (fileowner($directory) !== posix_geteuid() || (fileperms($directory) & 0077) !== 0
            || (file_exists($path) && (!is_file($path) || fileowner($path) !== posix_geteuid()
                || (fileperms($path) & 0077) !== 0))) {
            throw new \RuntimeException('Private registry database permissions required');
        }

        $mask = umask(0077);
        try {
            $db = new PDO(
            'sqlite:' . $path,
            null,
            null,
            [
                PDO::ATTR_ERRMODE =>
                    PDO::ERRMODE_EXCEPTION,
            ]
            );
        } finally {
            umask($mask);
        }

        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA synchronous=FULL');
        $db->exec('PRAGMA foreign_keys=ON');
        $db->exec('PRAGMA busy_timeout=5000');

        $db->exec(
            'CREATE TABLE IF NOT EXISTS registry_challenges (
                id TEXT PRIMARY KEY,
                chain_id TEXT NOT NULL,
                nonce TEXT NOT NULL,
                application_id INTEGER NOT NULL,
                service TEXT NOT NULL,
                repository TEXT NOT NULL,
                actions_json TEXT NOT NULL,
                issued_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                consumed_at INTEGER NULL
            )'
        );

        $db->exec(
            'CREATE INDEX IF NOT EXISTS registry_challenges_expires_at
             ON registry_challenges(expires_at)'
        );

        $db->exec(
            'CREATE TABLE IF NOT EXISTS registry_sessions (
                session_id TEXT PRIMARY KEY,
                credential_hash TEXT NOT NULL,
                account_id TEXT NOT NULL,
                repository TEXT NOT NULL,
                actions_json TEXT NOT NULL,
                issued_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            )'
        );

        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS
             uq_registry_sessions_credential_hash
             ON registry_sessions(credential_hash)'
        );

        $db->exec(
            'CREATE INDEX IF NOT EXISTS registry_sessions_expires_at
             ON registry_sessions(expires_at)'
        );

        return $db;
    }

    private function __construct()
    {
    }
}
