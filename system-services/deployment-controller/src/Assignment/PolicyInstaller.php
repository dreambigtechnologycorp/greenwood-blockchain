<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Assignment;

use Greenwood\ControlPlane\SignedPolicySnapshot;

final class PolicyInstaller
{
    public static function install(array $config, string $bytes): array
    {
        if (strlen($bytes) > 1048576) throw new \RuntimeException('POLICY_BUNDLE_TOO_LARGE');
        $bundle = json_decode($bytes, true, 20, JSON_THROW_ON_ERROR);
        if (!is_array($bundle) || count($bundle) !== 2) throw new \RuntimeException('INVALID_POLICY_BUNDLE');
        $lock = fopen(dirname($config['policy_ledger']) . '/policy-install.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('POLICY_INSTALL_LOCK_FAILED');
        $staged = [];
        $result = [];
        try {
            foreach (['revocations', 'allocations'] as $purpose) {
                $revocation = $purpose === 'revocations';
                $pin = $config[$revocation ? 'revocation_public_key' : 'allocation_public_key'];
                if (($bundle[$purpose]['public_key_hex'] ?? null) !== $pin) throw new \RuntimeException('POLICY_ISSUER_MISMATCH');
                $target = $revocation ? $config['tls']['revocations'] : $config['approved_allocations_file'];
                if (is_link($target) || is_link(dirname($target)) || (fileperms(dirname($target)) & 0077) !== 0
                    || fileowner(dirname($target)) !== posix_geteuid()) throw new \RuntimeException('UNSAFE_POLICY_DIRECTORY');
                $stage = tempnam(dirname($target), '.policy-');
                if ($stage === false) throw new \RuntimeException('POLICY_STAGE_FAILED');
                $staged[$target] = $stage;
                self::write($stage, json_encode($bundle[$purpose]['snapshot'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                // Advance the real ledger before publication. A crash can deny the old
                // snapshot, but can never silently roll the receiver back.
                $policy = new SignedPolicySnapshot($stage, $config['policy_ledger'], $purpose, $pin,
                    $config[$revocation ? 'revocation_max_age' : 'allocation_max_age']);
                $payload = $policy->read();
                $result[$purpose] = ['revision' => $payload['revision'], 'valid_until' => $payload['valid_until'],
                    'digest' => hash('sha256', SignedPolicySnapshot::signingBytes($payload))];
            }
            foreach ($staged as $target => $stage) {
                if (!rename($stage, $target)) throw new \RuntimeException('POLICY_RENAME_FAILED');
                $dir = fopen(dirname($target), 'r');
                try { if (!$dir || !fsync($dir)) throw new \RuntimeException('POLICY_DIRECTORY_SYNC_FAILED'); }
                finally { if ($dir) fclose($dir); }
            }
            return $result;
        } finally {
            foreach ($staged as $stage) if (is_file($stage)) unlink($stage);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function write(string $path, string $bytes): void
    {
        $file = fopen($path, 'wb');
        if (!$file) throw new \RuntimeException('POLICY_WRITE_FAILED');
        try {
            if (!chmod($path, 0600) || fwrite($file, $bytes) !== strlen($bytes) || !fflush($file) || !fsync($file)) {
                throw new \RuntimeException('POLICY_SYNC_FAILED');
            }
        } finally { fclose($file); }
    }
}
