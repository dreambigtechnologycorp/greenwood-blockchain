<?php
declare(strict_types=1);
namespace Greenwood\Compute\Runtime;

/**
 * Immutable resource usage statistics.
 * 
 * Contains CPU and memory usage information for a completed execution.
 */
final readonly class ResourceUsage
{
    /**
     * @throws \InvalidArgumentException if validation fails
     */
    public function __construct(
        private ?float $cpuNanoseconds = null,
        private ?int $memoryBytes = null,
        private ?int $memoryPeakBytes = null,
        private ?int $diskBytes = null,
        private ?int $ioReadBytes = null,
        private ?int $ioWriteBytes = null,
        private ?int $cpuTimeMs = null
    ) {
        if ($cpuTimeMs !== null && $cpuTimeMs < 0) throw new \InvalidArgumentException('Negative CPU time');
        if ($this->cpuNanoseconds !== null && $this->cpuNanoseconds < 0) {
            throw new \InvalidArgumentException('CPU nanoseconds cannot be negative');
        }
        if ($this->memoryBytes !== null && $this->memoryBytes < 0) {
            throw new \InvalidArgumentException('Memory bytes cannot be negative');
        }
        if ($this->memoryPeakBytes !== null && $this->memoryPeakBytes < 0) {
            throw new \InvalidArgumentException('Memory peak bytes cannot be negative');
        }
        if ($this->diskBytes !== null && $this->diskBytes < 0) {
            throw new \InvalidArgumentException('Disk bytes cannot be negative');
        }
        if ($this->ioReadBytes !== null && $this->ioReadBytes < 0) {
            throw new \InvalidArgumentException('IO read bytes cannot be negative');
        }
        if ($this->ioWriteBytes !== null && $this->ioWriteBytes < 0) {
            throw new \InvalidArgumentException('IO write bytes cannot be negative');
        }
    }

    public function getCpuNanoseconds(): ?float
    {
        return $this->cpuNanoseconds;
    }
    public function getCpuTimeMs(): ?int { return $this->cpuTimeMs; }

    public function getMemoryBytes(): ?int
    {
        return $this->memoryBytes;
    }

    public function getMemoryPeakBytes(): ?int
    {
        return $this->memoryPeakBytes;
    }

    public function getDiskBytes(): ?int
    {
        return $this->diskBytes;
    }

    public function getIoReadBytes(): ?int
    {
        return $this->ioReadBytes;
    }

    public function getIoWriteBytes(): ?int
    {
        return $this->ioWriteBytes;
    }

    /**
     * Get CPU usage in seconds.
     */
    public function getCpuSeconds(): ?float
    {
        return $this->cpuNanoseconds !== null ? $this->cpuNanoseconds / 1_000_000_000.0 : null;
    }

    /**
     * Get memory usage in megabytes.
     */
    public function getMemoryMegabytes(): ?float
    {
        return $this->memoryBytes !== null ? $this->memoryBytes / (1024 * 1024) : null;
    }

    /**
     * Get memory peak in megabytes.
     */
    public function getMemoryPeakMegabytes(): ?float
    {
        return $this->memoryPeakBytes !== null ? $this->memoryPeakBytes / (1024 * 1024) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cpuNanoseconds' => $this->cpuNanoseconds,
            'memoryBytes' => $this->memoryBytes,
            'memoryPeakBytes' => $this->memoryPeakBytes,
            'diskBytes' => $this->diskBytes,
            'ioReadBytes' => $this->ioReadBytes,
            'ioWriteBytes' => $this->ioWriteBytes,
        ];
    }

    /**
     * Create a ResourceUsage with only memory info.
     */
    public static function withMemory(int $memoryBytes, ?int $peakBytes = null): self
    {
        return new self(memoryBytes: $memoryBytes, memoryPeakBytes: $peakBytes);
    }

    /**
     * Create a ResourceUsage with only CPU info.
     */
    public static function withCpu(float $cpuNanoseconds): self
    {
        return new self(cpuNanoseconds: $cpuNanoseconds);
    }

    /**
     * Create a ResourceUsage with full stats.
     */
    public static function full(
        ?float $cpuNanoseconds = null,
        ?int $memoryBytes = null,
        ?int $memoryPeakBytes = null,
        ?int $diskBytes = null,
        ?int $ioReadBytes = null,
        ?int $ioWriteBytes = null
    ): self {
        return new self($cpuNanoseconds, $memoryBytes, $memoryPeakBytes, $diskBytes, $ioReadBytes, $ioWriteBytes);
    }
}
