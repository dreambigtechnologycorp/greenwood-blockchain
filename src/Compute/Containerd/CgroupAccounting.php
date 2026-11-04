<?php
declare(strict_types=1);
namespace Greenwood\Compute\Containerd;

use Greenwood\Compute\Runtime\ResourceUsage;

/** Capture the actual task cgroup while its PID exists; read counters before deletion. */
final readonly class CgroupAccounting
{
    private function __construct(private string $path) {}
    public static function forPid(int $pid): self
    {
        $raw = @file_get_contents('/proc/' . $pid . '/cgroup');
        if ($pid < 1 || $raw === false || !preg_match('~^0::(/[^\r\n]*)$~m', $raw, $m) || str_contains($m[1], '..')) throw new \RuntimeException('Task cgroup unavailable for accounting');
        return new self('/sys/fs/cgroup' . $m[1]);
    }
    /** Deterministic containerd cgroupfs path; survives instant task exit (unlike /proc/<pid>). */
    public static function forTask(string $namespace, string $containerId): self
    {
        if (!preg_match('~^[A-Za-z0-9_.-]+$~D', $namespace) || !preg_match('~^[A-Za-z0-9_.-]+$~D', $containerId)) throw new \RuntimeException('Task cgroup unavailable for accounting');
        $path = '/sys/fs/cgroup/' . $namespace . '/' . $containerId;
        if (!is_dir($path)) throw new \RuntimeException('Task cgroup unavailable for accounting');
        return new self($path);
    }
    public function usage(): ResourceUsage
    {
        $cpu = @file_get_contents($this->path . '/cpu.stat');
        $peak = @file_get_contents($this->path . '/memory.peak');
        if ($cpu === false || $peak === false || !preg_match('/^usage_usec ([0-9]+)$/m', $cpu, $m)) throw new \RuntimeException('Task resource counters unavailable');
        $micros = self::counter($m[1]); $memory = self::counter(trim($peak));
        return new ResourceUsage(memoryPeakBytes: $memory, cpuTimeMs: self::cpuMillis((string)$micros));
    }
    private static function counter(string $value): int
    {
        $max = (string)PHP_INT_MAX;
        if (!ctype_digit($value) || strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) throw new \OverflowException('Resource counter exceeds integer range');
        return (int)$value;
    }
    public static function cpuMillis(string $microseconds): int { return intdiv(self::counter($microseconds), 1000); }
}
