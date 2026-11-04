<?php
declare(strict_types=1);
namespace Greenwood\Compute\Scheduling;

use Greenwood\Compute\Protocol\ComputeRequest;

/**
 * Explicit reservation accounting per provider (Pass 4D sections 10–11, 31).
 *
 * free = configured scheduling capacity - reserved by active assigned jobs.
 * Reservations release only when the protocol lifecycle says the attempt is
 * terminal/released; a local worker disappearing never clears a reservation.
 * All arithmetic is checked: overflow/underflow rejects the record instead
 * of wrapping into fake capacity.
 */
final class ReservationLedger
{
    /** @var array<string, array{cpu: int, memory: int, pids: int, jobs: int}> providerId (binary) => totals */
    private array $reserved = [];

    private static function totals(): array
    {
        return ['cpu' => 0, 'memory' => 0, 'pids' => 0, 'jobs' => 0];
    }

    public function reserved(string $providerId): array
    {
        return $this->reserved[$providerId] ?? self::totals();
    }

    public function reserve(ProviderRecord $provider, ComputeRequest $request): void
    {
        $id = $provider->providerId;
        $r = $this->reserved($id);
        foreach (['cpu' => $request->getCpuLimitMillis(), 'memory' => $request->getMemoryLimitBytes(),
                  'pids' => $request->getPidLimit(), 'jobs' => 1] as $k => $add) {
            $v = $r[$k] + $add;
            if ($v < $r[$k]) throw new \OverflowException('Reservation overflow');
            $r[$k] = $v;
        }
        $capacity = ['cpu' => $provider->cpuCapacityMillis, 'memory' => $provider->memoryCapacityBytes,
            'pids' => $provider->pidCapacity, 'jobs' => $provider->maxConcurrentJobs];
        foreach ($r as $k => $v) {
            if ($v > $capacity[$k]) throw new \InvalidArgumentException('Reservation exceeds provider capacity');
        }
        $this->reserved[$id] = $r;
    }

    /**
     * Bulk hydration from finalized reservation totals (replay). Totals must
     * be exactly the keys [cpu, memory, pids, jobs] with non-negative values.
     */
    public function injectTotals(string $providerId, array $totals): void
    {
        if (strlen($providerId) !== 32 || array_keys($totals) !== ['cpu', 'memory', 'pids', 'jobs']) {
            throw new \InvalidArgumentException('Invalid reservation totals');
        }
        foreach ($totals as $v) {
            if (!is_int($v) || $v < 0) throw new \InvalidArgumentException('Invalid reservation total');
        }
        $this->reserved[$providerId] = $totals;
    }

    /** Release only on finalized terminal/released lifecycle — never on local liveness. */
    public function release(ProviderRecord $provider, ComputeRequest $request): void
    {
        $id = $provider->providerId;
        $r = $this->reserved($id);
        foreach (['cpu' => $request->getCpuLimitMillis(), 'memory' => $request->getMemoryLimitBytes(),
                  'pids' => $request->getPidLimit(), 'jobs' => 1] as $k => $sub) {
            if ($r[$k] < $sub) throw new \UnderflowException('Reservation underflow: release without reservation');
            $r[$k] -= $sub;
        }
        $this->reserved[$id] = $r;
    }

    /**
     * Capacity update guard (section 31): a provider may reduce capacity, but
     * new schedulable capacity must cover existing reservations; retroactive
     * invalidation of assigned jobs is rejected.
     */
    public static function checkCapacityUpdate(ProviderRecord $provider, int $cpu, int $memory, int $pids, int $jobs): ProviderRecord
    {
        $updated = new ProviderRecord($provider->providerId, $provider->status, $provider->trustClasses,
            $provider->architectures, $cpu, $memory, $pids, $jobs);
        return $updated;
    }

    public function assertCapacityUpdateAllowed(ProviderRecord $updated): void
    {
        $r = $this->reserved($updated->providerId);
        $capacity = ['cpu' => $updated->cpuCapacityMillis, 'memory' => $updated->memoryCapacityBytes,
            'pids' => $updated->pidCapacity, 'jobs' => $updated->maxConcurrentJobs];
        foreach ($r as $k => $v) {
            if ($v > $capacity[$k]) throw new \InvalidArgumentException('Capacity update below existing reservations');
        }
    }
}
