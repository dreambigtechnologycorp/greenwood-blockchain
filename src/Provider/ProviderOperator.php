<?php

declare(strict_types=1);

namespace Greenwood\Provider;

use Greenwood\Compute\Protocol\ProviderCapability;
use Greenwood\Rpc\ApiError;

/**
 * Provider-side adapter for the Pass 5 operator control plane.
 *
 * Reads: local supervisor status.json / job registry / host diagnostics.
 * Mutations: canonical TYPE_PROVIDER_CAPABILITY transactions signed by the
 * supervisor identity and submitted through the existing ChainClient path.
 * Nothing here mutates consensus state directly; the node's transaction
 * admission enforces every protocol guard (binding, nonce, reservation cover).
 */
final class ProviderOperator
{
    /** EROFS fixed-size writable backing bound (matches containerd provider config default_size). */
    private const EROFS_EPHEMERAL_LIMIT = 268435456;

    public function __construct(
        private readonly ProviderConfig $config,
        private readonly ProviderIdentity $identity,
        private readonly ProviderJobRegistry $registry,
        private readonly ChainClient $chain,
    ) {
    }

    private function localStatus(): array
    {
        $status = [];
        $file = $this->config->dataPath . '/status.json';
        if (is_file($file)) {
            $status = json_decode((string) file_get_contents($file), true, 32) ?: [];
        }
        $alive = isset($status['pid']) && posix_kill((int) $status['pid'], 0) && time() - (int) ($status['observed_at'] ?? 0) <= 30;
        return [$status, $alive];
    }

    private function networkStatus(): string
    {
        try {
            $cap = $this->chain->capability($this->identity->providerId);
            return $cap['status'] ?? 'UNREGISTERED';
        } catch (\Throwable) {
            return 'UNKNOWN';
        }
    }

    public function status(): array
    {
        [$status, $alive] = $this->localStatus();
        $jobs = $this->registry->jobs();
        $count = fn (array $states) => count(array_filter($jobs, fn ($j) => in_array($j['state'], $states, true)));
        $localDrain = $this->registry->isDraining();
        $network = $this->networkStatus();
        return [
            'provider_id' => bin2hex($this->identity->providerId),
            'healthy' => $alive && ($status['healthy'] ?? false) === true,
            'ready' => $alive && ($status['ready'] ?? false) === true,
            'pid' => $status['pid'] ?? null,
            'observed_height' => $this->registry->observedHeight(),
            // Local emergency drain is a distinct safety control, never finalized network status.
            'network_status' => $network,
            'local_drain' => $localDrain,
            'effective_accepting_work' => $network === 'ACTIVE' && !$localDrain,
            'active_jobs' => $count(['QUEUED', 'STARTING', 'RUNNING', 'RECEIPT_READY', 'SUBMITTING', 'SUBMITTED']),
            'queued_jobs' => $count(['QUEUED']),
            'running_jobs' => $count(['STARTING', 'RUNNING']),
            'containerd_available' => $this->socketAccess(),
            'snapshotter' => $this->config->snapshotter,
            'snapshotter_ready' => $this->snapshotterReady(),
        ];
    }

    public function jobs(): array
    {
        return ['jobs' => array_map(fn ($j) => [
            'request_id' => bin2hex($j['request_id']), 'generation' => $j['generation'], 'state' => $j['state'],
            'runtime_id' => $j['execution_id'], 'worker_pid' => $j['worker_pid'], 'error' => $j['last_error'],
        ], $this->registry->jobs())];
    }


    private function socketAccess(): bool
    {
        $path = $this->config->containerdSocket;
        $mode = @fileperms($path);
        if ($mode === false || @filetype($path) !== 'socket' || @filegroup($path) !== $this->config->workerGid || ($mode & 0060) !== 0060) {
            return false;
        }
        $socket = @stream_socket_client('unix://' . $path, $errno, $error, 0.2);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }

    private function executableAvailable(array $paths): bool
    {
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return true;
            }
        }
        return false;
    }

    private function erofsToolingAvailable(): bool
    {
        return $this->executableAvailable([
            '/usr/bin/mkfs.erofs',
            '/usr/sbin/mkfs.erofs',
        ]) && $this->executableAvailable([
            '/usr/bin/mkfs.ext4',
            '/usr/sbin/mkfs.ext4',
        ]);
    }

    private function snapshotterReady(): bool
    {
        if ($this->config->snapshotter === 'erofs') {
            // No overlayfs fallback: containerd must be reachable and the
            // required EROFS/ext4 image tooling must be installed.
            return $this->socketAccess() && $this->erofsToolingAvailable();
        }
        return $this->socketAccess();
    }

    /** Explicit local security/readiness diagnostics; never consensus state. */
    public function runtime(): array
    {
        $socketAccess = $this->socketAccess();
        try {
            $this->chain->nonce($this->identity->accountId);
            $chainReachable = true;
        } catch (\Throwable) {
            $chainReachable = false;
        }
        return [
            'provider_id' => bin2hex($this->identity->providerId),
            'containerd_socket_access' => $socketAccess,
            'configured_snapshotter' => $this->config->snapshotter,
            'snapshotter_available' => $this->config->snapshotter !== 'erofs' || $this->erofsToolingAvailable(),
            'snapshotter_ready' => $this->snapshotterReady(),
            'ephemeral_storage_limit_bytes' => $this->config->snapshotter === 'erofs' ? (string) self::EROFS_EPHEMERAL_LIMIT : null,
            'worker_identity_ready' => function_exists('posix_geteuid') && posix_geteuid() === 0 && $this->config->workerUid > 0 && $this->config->workerGid > 0,
            'data_path_writable' => is_writable($this->config->dataPath),
            'chain_reachable' => $chainReachable,
            'signing_key_isolated' => true, // Supervisor holds keys; workers never receive key material.
        ];
    }

    /** Build, sign and submit a canonical TYPE_PROVIDER_CAPABILITY transaction. */
    private function submitCapability(int $status, array $overrides): array
    {
        try {
            $current = $this->chain->capability($this->identity->providerId);
        } catch (\Throwable) {
            throw ApiError::notReady('Chain is unreachable; capability state unknown');
        }
        if ($current === null && $overrides === []) {
            throw ApiError::conflict('PROVIDER_CAPABILITY_NOT_FOUND', 'No finalized capability registered; submit a full capability update first');
        }
        $base = $current ?? [];
        $fields = [
            'architectures' => $overrides['architectures'] ?? $base['architectures'] ?? null,
            'cpu_capacity_millis' => $overrides['cpu_capacity_millis'] ?? $base['cpu_capacity_millis'] ?? null,
            'memory_capacity_bytes' => $overrides['memory_capacity_bytes'] ?? (isset($base['memory_capacity_bytes']) ? (int) $base['memory_capacity_bytes'] : null),
            'pid_capacity' => $overrides['pid_capacity'] ?? $base['pid_capacity'] ?? null,
            'max_concurrent_jobs' => $overrides['max_concurrent_jobs'] ?? $base['max_concurrent_jobs'] ?? null,
        ];
        foreach ($fields as $name => $value) {
            if ($value === null) {
                throw ApiError::invalidRequest($name . ' is required for initial capability registration');
            }
        }
        $capability = new ProviderCapability(
            $this->identity->providerId,
            $this->identity->publicKey,
            $status,
            ProviderCapability::nextNonce($base['capability_nonce'] ?? 0),
            $fields['architectures'],
            $fields['cpu_capacity_millis'],
            $fields['memory_capacity_bytes'],
            $fields['pid_capacity'],
            $fields['max_concurrent_jobs'],
        );
        try {
            $nonce = $this->chain->nonce($this->identity->accountId);
        } catch (\Throwable) {
            throw ApiError::notReady('Chain is unreachable; account nonce unknown');
        }
        $tx = $this->identity->capabilityTransaction($this->config->chainId, $nonce, $capability);
        try {
            $hash = $this->chain->submit($tx);
        } catch (\Throwable) {
            // The node's canonical admission rejected it (binding, nonce or reservation guard).
            throw ApiError::conflict('PROVIDER_CAPABILITY_REJECTED', 'Capability transaction was rejected by chain admission');
        }
        return [
            'tx_hash' => $hash,
            'provider_id' => bin2hex($this->identity->providerId),
            'requested_status' => match ($status) {
                ProviderCapability::STATUS_ACTIVE => 'ACTIVE',
                ProviderCapability::STATUS_DRAINING => 'DRAINING',
                default => 'SUSPENDED',
            },
            'capability_nonce' => $capability->nonce,
            // Submission is transport acceptance, not finality; poll /v1/compute/providers/{id}.
            'finalized' => false,
        ];
    }

    public function drain(): array
    {
        return $this->submitCapability(ProviderCapability::STATUS_DRAINING, []);
    }

    public function resume(): array
    {
        return $this->submitCapability(ProviderCapability::STATUS_ACTIVE, []);
    }

    public function capability(array $input): array
    {
        if (!isset($input['status'])) {
            // Keep current status; required explicitly for initial registration.
            try {
                $current = $this->chain->capability($this->identity->providerId);
            } catch (\Throwable) {
                throw ApiError::notReady('Chain is unreachable; capability state unknown');
            }
            if ($current === null) {
                throw ApiError::invalidRequest('status is required for initial capability registration');
            }
            $input['status'] = $current['status'];
        }
        $status = match ($input['status']) {
            'ACTIVE' => ProviderCapability::STATUS_ACTIVE,
            'DRAINING' => ProviderCapability::STATUS_DRAINING,
            default => ProviderCapability::STATUS_SUSPENDED,
        };
        unset($input['status']);
        return $this->submitCapability($status, $input);
    }
}
