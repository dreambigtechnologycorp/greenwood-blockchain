<?php
declare(strict_types=1);
namespace Greenwood\Compute\Scheduling;

use Greenwood\Compute\Protocol\TrustClass;

/**
 * Finalized provider scheduling record (Pass 4D section 4).
 *
 * Fields are limited to what scheduling needs. `trustClasses` must come from
 * finalized chain state (ProviderEligibility); a provider can advertise
 * capabilities but can never self-upgrade into a trust class. Transient
 * utilization is modeled by the reservation ledger, not by this record.
 */
final readonly class ProviderRecord
{
    /** @param int[] $trustClasses exact allowed classes from finalized eligibility
     *  @param string[] $architectures OCI platforms, e.g. linux/arm64/v8, linux/amd64 */
    public function __construct(
        public string $providerId,
        public string $status,
        public array $trustClasses,
        public array $architectures,
        public int $cpuCapacityMillis,
        public int $memoryCapacityBytes,
        public int $pidCapacity,
        public int $maxConcurrentJobs,
    ) {
        if (strlen($providerId) !== 32) {
            throw new \InvalidArgumentException('Provider ID must be 32 bytes');
        }
        ProviderStatus::check($status);
        if ($trustClasses === []) {
            throw new \InvalidArgumentException('Provider must allow at least one trust class');
        }
        foreach ($trustClasses as $class) {
            TrustClass::check($class);
        }
        if (count($trustClasses) !== count(array_unique($trustClasses))) {
            throw new \InvalidArgumentException('Duplicate trust classes');
        }
        if ($architectures === []) {
            throw new \InvalidArgumentException('Provider must declare at least one architecture');
        }
        foreach ($architectures as $arch) {
            if (!is_string($arch) || $arch === '') {
                throw new \InvalidArgumentException('Invalid architecture entry');
            }
        }
        foreach (['cpu_capacity_millis' => $cpuCapacityMillis, 'memory_capacity_bytes' => $memoryCapacityBytes,
                  'pid_capacity' => $pidCapacity, 'max_concurrent_jobs' => $maxConcurrentJobs] as $name => $v) {
            if ($v < 1) {
                throw new \InvalidArgumentException("$name must be positive");
            }
        }
    }

    public function allowsTrustClass(int $trustClass): bool
    {
        return \in_array($trustClass, $this->trustClasses, true);
    }

    public function supportsArchitecture(string $architecture): bool
    {
        return \in_array($architecture, $this->architectures, true);
    }
}
