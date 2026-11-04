<?php
declare(strict_types=1);

namespace Greenwood\Compute\Containerd;

/**
 * Provider-local execution resource policy.
 *
 * Coherent resource configuration for a single workload execution:
 *
 *   memoryBytes      hard memory ceiling (cgroup memory.max)
 *   pidsLimit        hard process count ceiling (cgroup pids.max)
 *   cpuQuotaMicros   CFS quota per period (cgroup cpu.max), -1 = unlimited
 *   cpuPeriodMicros  CFS period (cgroup cpu.max)
 *
 * cpu.shares is a relative scheduling weight and is NOT a hard ceiling;
 * hard CPU bounding requires quota/period.
 */
final class ResourceLimits
{
    public const DEFAULT_MEMORY_BYTES = 256 * 1024 * 1024; // 256 MiB
    public const DEFAULT_PIDS_LIMIT = 100;
    public const DEFAULT_CPU_PERIOD_MICROS = 100_000; // 100ms CFS period

    private function __construct(
        public readonly int $memoryBytes,
        public readonly int $pidsLimit,
        public readonly ?int $cpuQuotaMicros,
        public readonly ?int $cpuPeriodMicros,
    ) {
    }

    /**
     * @throws \InvalidArgumentException on invalid resource policy
     */
    public static function create(
        int $memoryBytes = self::DEFAULT_MEMORY_BYTES,
        int $pidsLimit = self::DEFAULT_PIDS_LIMIT,
        ?int $cpuQuotaMicros = null,
        ?int $cpuPeriodMicros = null
    ): self {
        if ($memoryBytes <= 0) {
            throw new \InvalidArgumentException("memoryBytes must be positive, got {$memoryBytes}");
        }
        if ($pidsLimit <= 0) {
            throw new \InvalidArgumentException("pidsLimit must be positive, got {$pidsLimit}");
        }

        // CPU quota/period must be configured together (or neither).
        if (($cpuQuotaMicros === null) !== ($cpuPeriodMicros === null)) {
            throw new \InvalidArgumentException(
                'cpuQuotaMicros and cpuPeriodMicros must be set together'
            );
        }
        if ($cpuPeriodMicros !== null && $cpuPeriodMicros <= 0) {
            throw new \InvalidArgumentException("cpuPeriodMicros must be positive, got {$cpuPeriodMicros}");
        }
        if ($cpuQuotaMicros !== null && ($cpuQuotaMicros === 0 || $cpuQuotaMicros < -1)) {
            throw new \InvalidArgumentException(
                "cpuQuotaMicros must be positive or -1 (unlimited), got {$cpuQuotaMicros}"
            );
        }

        return new self($memoryBytes, $pidsLimit, $cpuQuotaMicros, $cpuPeriodMicros);
    }

    /**
     * Greenwood defaults: 256MiB memory, 100 PIDs, no hard CPU quota.
     */
    public static function defaults(): self
    {
        return self::create();
    }

    public function hasCpuQuota(): bool
    {
        return $this->cpuQuotaMicros !== null && $this->cpuQuotaMicros > 0;
    }
}
