<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Persistence\AtomicFile;

/** The only provider component allowed to launch controlled PHP processes. */
final class ProcessManager
{
    private array $processes = [];
    public function __construct(private readonly ProviderConfig $config, private readonly string $providerId, private readonly ProviderJobRegistry $registry, private readonly ?\Closure $checkpoint = null) {}
    public function directory(string $runtimeId): string
    {
        if (!preg_match('/^native-[0-9a-f]{16}$/D', $runtimeId)) throw new \InvalidArgumentException('Invalid local runtime ID');
        return $this->config->dataPath . '/workers/' . $runtimeId;
    }
    public function launch(string $runtimeId, string $requestId, int $generation): int
    {
        $claim = $this->registry->claimed($requestId, $generation, $runtimeId);
        if ($claim['provider_id'] !== $this->providerId) throw new \RuntimeException('Claim belongs to another provider');
        if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0 || !is_executable('/usr/bin/setpriv')) throw new \RuntimeException('Isolated worker launch requires Linux root supervisor and setpriv');
        $directory = $this->directory($runtimeId);
        if (isset($this->processes[$runtimeId]) || $this->busy($runtimeId)) throw new \RuntimeException('Worker is already active');
        $job = ['version' => 1, 'mode' => 'execute',
                'runtime_id' => $runtimeId, 'request_id' => bin2hex($requestId), 'generation' => $generation,
                'provider_id' => bin2hex($this->providerId), 'chain_id' => $this->config->chainId,
                'rpc_endpoint' => $this->config->rpcEndpoint, 'containerd_socket' => $this->config->containerdSocket,
                'snapshotter' => $this->config->snapshotter];
        if (!is_file($directory . '/job.json')) {
            AtomicFile::write($directory . '/job.json', json_encode($job, JSON_THROW_ON_ERROR));
            foreach ([$this->config->dataPath, $this->config->dataPath . '/workers', $directory] as $path) chmod($path, 0711);
            chmod($directory . '/job.json', 0444);
        }
        if (WorkerJob::read($directory . '/job.json') !== $job) throw new \RuntimeException('Immutable job binding mismatch');
        if (!is_dir($directory . '/run')) { mkdir($directory . '/run', 0700); chown($directory . '/run', $this->config->workerUid); chgrp($directory . '/run', $this->config->workerGid); }
        $process = proc_open([PHP_BINARY, '-d', 'ffi.enable=true', __DIR__ . '/../../bin/greenwood-worker-launcher',
            (string)$this->config->workerUid, (string)$this->config->workerGid, $this->config->workerEntrypoint, $directory . '/job.json'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes, null, ['PATH' => '/usr/bin:/bin'], ['bypass_shell' => true]);
        if (!is_resource($process)) throw new \RuntimeException('Worker launch failed');
        $this->processes[$runtimeId] = $process;
        $this->checkpoint?->__invoke('spawned_before_pid');
        $pid = proc_get_status($process)['pid'];
        $this->registry->workerStarted($requestId, $generation, $pid);
        return $pid;
    }
    public function reap(): void
    {
        foreach ($this->processes as $id => $process) if (!proc_get_status($process)['running']) { proc_close($process); unset($this->processes[$id]); }
    }
    public function busy(string $runtimeId): bool
    {
        if (isset($this->processes[$runtimeId]) && proc_get_status($this->processes[$runtimeId])['running']) return true;
        if ($this->findWorkerPid($runtimeId) !== null) return true;
        $path = $this->directory($runtimeId) . '/run/worker.lock';
        if (!is_file($path)) return false;
        $file = fopen($path, 'r');
        if ($file === false) throw new \RuntimeException('Cannot inspect worker ownership');
        try { $available = flock($file, LOCK_EX | LOCK_NB); if ($available) flock($file, LOCK_UN); return !$available; }
        finally { fclose($file); }
    }
    public function facts(string $runtimeId): ?array
    {
        $path = $this->directory($runtimeId) . '/run/facts.json';
        if (!is_file($path)) return null;
        $bytes = file_get_contents($path, false, null, 0, 3000001);
        if ($bytes === false || strlen($bytes) > 3000000) throw new \RuntimeException('Worker facts exceed bound');
        $facts = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($facts)) throw new \RuntimeException('Invalid worker facts');
        return $facts;
    }
    public function stop(string $runtimeId, ?int $knownPid = null): void
    {
        if (isset($this->processes[$runtimeId])) proc_terminate($this->processes[$runtimeId], 9);
        // Adopted workers are identified by their immutable job path in /proc,
        // never by a possibly reused PID alone.
        $marker = $this->directory($runtimeId) . '/run/started.json';
        $data = is_file($marker) ? json_decode(file_get_contents($marker), true) : null;
        $pid = $knownPid ?? $this->findWorkerPid($runtimeId) ?? $data['pid'] ?? null;
        if (is_int($pid) && $pid > 1 && str_contains((string)@file_get_contents('/proc/' . $pid . '/cmdline'), $this->directory($runtimeId) . '/job.json')) posix_kill($pid, 9);
    }
    private function findWorkerPid(string $runtimeId): ?int
    {
        $jobPath = $this->directory($runtimeId) . '/job.json';
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
            $args = @file_get_contents($path);
            if ($args !== false && in_array($jobPath, explode("\0", $args), true)) return (int)basename(dirname($path));
        }
        return null;
    }
    public function quarantine(string $runtimeId): void
    {
        foreach (['facts.json', 'pending-facts.json'] as $name) {
            $file = $this->directory($runtimeId) . '/run/' . $name;
            if (is_file($file) && !rename($file, $this->directory($runtimeId) . '/rejected-' . $name)) throw new \RuntimeException('Cannot quarantine facts');
        }
    }
    public function prerequisitesAvailable(): bool
    {
        if (PHP_OS_FAMILY !== 'Linux' || !extension_loaded('grpc') || !is_executable('/usr/bin/setpriv') || !is_readable($this->config->workerEntrypoint)) return false;
        $path = $this->config->containerdSocket; $mode = @fileperms($path);
        if ($mode === false || @filetype($path) !== 'socket' || @filegroup($path) !== $this->config->workerGid || ($mode & 0060) !== 0060) return false;
        $socket = @stream_socket_client('unix://' . $path, $errno, $error, 0.2);
        if ($socket === false) return false;
        fclose($socket); return true;
    }
    public function recordPrestartFailure(string $runtimeId): bool
    {
        $run = $this->directory($runtimeId) . '/run';
        if ($this->busy($runtimeId) || is_file($run . '/started.json') || is_file($run . '/execution_registry.sqlite')) return false;
        // Trusted worker always persists these intents before any containerd
        // side effect. This is a local failure record, never a signed receipt.
        AtomicFile::write($run . '/facts.json', '{"local_error":"Worker failed before durable runtime intent"}');
        return true;
    }
    public function archive(string $runtimeId): void
    {
        $directory = $this->directory($runtimeId);
        $archive = $this->config->dataPath . '/archive';
        if (!is_dir($directory) && is_dir($archive . '/' . $runtimeId)) return;
        if ($this->busy($runtimeId) || !is_file($directory . '/run/facts.json')) throw new \RuntimeException('Cleanup facts required before archiving');
        if (glob($directory . '/run/*.fifo_gw_*')) throw new \RuntimeException('Cannot archive runtime residue');
        if (!is_dir($archive) && !mkdir($archive, 0700)) throw new \RuntimeException('Cannot create audit archive');
        if (!rename($directory, $archive . '/' . $runtimeId)) throw new \RuntimeException('Cannot archive completed runtime metadata');
        chmod($archive . '/' . $runtimeId, 0700);
        foreach ([$archive, dirname($directory)] as $path) {
            $fd = fopen($path, 'r'); try { if (!fsync($fd)) throw new \RuntimeException('Archive directory sync failed'); } finally { fclose($fd); }
        }
    }
}
