<?php
declare(strict_types=1);
namespace Greenwood\Provider;

/** Strict local IPC; immutable root-owned job file, separate writable run directory. */
final class WorkerJob
{
    public static function read(string $path): array
    {
        if (PHP_OS_FAMILY === 'Linux' && (is_link($path) || fileowner($path) !== 0 || (fileperms($path) & 0022) !== 0
            || fileowner(dirname($path)) !== 0 || (fileperms(dirname($path)) & 0022) !== 0)) throw new \RuntimeException('Worker job must be owned and protected by supervisor');
        $raw = file_get_contents($path, false, null, 0, 65537);
        if ($raw === false || strlen($raw) > 65536) throw new \RuntimeException('Worker job exceeds bounds');
        $job = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        $keys = ['version', 'mode', 'runtime_id', 'request_id', 'generation', 'provider_id', 'chain_id', 'rpc_endpoint', 'containerd_socket', 'snapshotter'];
        if (!is_array($job) || array_diff($keys, array_keys($job)) || array_diff(array_keys($job), $keys)
            || $job['version'] !== 1 || $job['mode'] !== 'execute') throw new \RuntimeException('Invalid worker job schema');
        foreach (['request_id', 'provider_id'] as $key) if (!is_string($job[$key]) || !preg_match('/^[0-9a-f]{64}$/D', $job[$key])) throw new \RuntimeException('Invalid worker identity');
        if (!is_int($job['generation']) || $job['generation'] < 1 || $job['generation'] > 0xffffffff
            || !is_string($job['runtime_id']) || !preg_match('/^native-[0-9a-f]{16}$/D', $job['runtime_id'])
            || basename(dirname($path)) !== $job['runtime_id']) throw new \RuntimeException('Invalid worker job binding');
        foreach (['chain_id', 'rpc_endpoint', 'containerd_socket', 'snapshotter'] as $key) if (!is_string($job[$key])) throw new \RuntimeException('Invalid worker setting');
        // Reuse the strict endpoint/path validation; no signing file is read.
        new ProviderConfig($job['chain_id'], $job['rpc_endpoint'], '/unused', '/unused', containerdSocket: $job['containerd_socket'], snapshotter: $job['snapshotter']);
        return $job;
    }
}
