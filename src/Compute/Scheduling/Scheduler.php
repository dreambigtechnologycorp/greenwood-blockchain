<?php
declare(strict_types=1);
namespace Greenwood\Compute\Scheduling;

use Greenwood\Compute\Protocol\ComputeRequest;

/**
 * Pure protocol-aware scheduler (Pass 4D sections 12–16, 46–47).
 *
 * Pipeline: candidate enumeration -> protocol/status eligibility -> exact
 * trust-class membership -> architecture -> reservation-capacity ->
 * deterministic selection. No HTTP, no containerd, no executor imports, no
 * clock, no randomness. Identical finalized inputs must yield byte-identical
 * provider selection independent of insertion order.
 *
 * Selection (frozen v1, integer arithmetic only):
 *   1. reserved job count ascending
 *   2. reserved CPU ratio ascending (cross-multiplied, gcd-reduced)
 *   3. reserved memory ratio ascending (same)
 *   4. provider_id bytes ascending
 */
final class Scheduler
{
    /**
     * @param string $requiredArchitecture OCI platform required by the workload
     *                                     (e.g. "linux/arm64/v8"); no implicit
     *                                     emulation is assumed for v1.
     * @param ProviderRecord[] $candidates providers from finalized state only
     * @return string winning provider_id (32 bytes)
     */
    public static function schedule(ComputeRequest $request, string $requiredArchitecture,
        array $candidates, ReservationLedger $ledger): string
    {
        if ($candidates === []) throw new SchedulingFailure(SchedulingFailure::NO_PROVIDER);
        $active = [];
        foreach ($candidates as $p) {
            if ($p->status === ProviderStatus::ACTIVE) $active[] = $p;
        }
        if ($active === []) throw new SchedulingFailure(SchedulingFailure::NO_ACTIVE_PROVIDER);
        $trusted = [];
        foreach ($active as $p) {
            // Exact membership only: no OPEN < TRUSTED < SOVEREIGN ranking.
            if ($p->allowsTrustClass($request->getTrustClass())) $trusted[] = $p;
        }
        if ($trusted === []) throw new SchedulingFailure(SchedulingFailure::NO_TRUST_MATCH);
        $arch = [];
        foreach ($trusted as $p) {
            if ($p->supportsArchitecture($requiredArchitecture)) $arch[] = $p;
        }
        if ($arch === []) throw new SchedulingFailure(SchedulingFailure::NO_ARCH_MATCH);
        $fits = [];
        foreach ($arch as $p) {
            if (self::hasCapacity($p, $request, $ledger)) $fits[] = $p;
        }
        if ($fits === []) throw new SchedulingFailure(SchedulingFailure::NO_CAPACITY);

        usort($fits, fn (ProviderRecord $a, ProviderRecord $b) => self::compare($a, $b, $ledger));
        return $fits[0]->providerId;
    }

    /** Admission control: request fits in free (capacity - reserved), checked arithmetic. */
    public static function hasCapacity(ProviderRecord $p, ComputeRequest $request, ReservationLedger $ledger): bool
    {
        $r = $ledger->reserved($p->providerId);
        return $request->getCpuLimitMillis() <= $p->cpuCapacityMillis - $r['cpu']
            && $request->getMemoryLimitBytes() <= $p->memoryCapacityBytes - $r['memory']
            && $request->getPidLimit() <= $p->pidCapacity - $r['pids']
            && $r['jobs'] < $p->maxConcurrentJobs;
    }

    private static function compare(ProviderRecord $a, ProviderRecord $b, ReservationLedger $ledger): int
    {
        $ra = $ledger->reserved($a->providerId);
        $rb = $ledger->reserved($b->providerId);
        if ($ra['jobs'] !== $rb['jobs']) return $ra['jobs'] <=> $rb['jobs'];
        $cpu = self::ratioCmp($ra['cpu'], $a->cpuCapacityMillis, $rb['cpu'], $b->cpuCapacityMillis);
        if ($cpu !== 0) return $cpu;
        $mem = self::ratioCmp($ra['memory'], $a->memoryCapacityBytes, $rb['memory'], $b->memoryCapacityBytes);
        if ($mem !== 0) return $mem;
        return strcmp($a->providerId, $b->providerId);
    }

    /**
     * Compare r1/cap1 vs r2/cap2 with integers only. Ratios are gcd-reduced
     * before cross multiplication; an unresolvable product rejects the record
     * instead of wrapping. 0 <= r <= cap is guaranteed by the ledger.
     */
    private static function ratioCmp(int $r1, int $cap1, int $r2, int $cap2): int
    {
        $g1 = self::gcd($r1, $cap1); $r1 = intdiv($r1, $g1); $cap1 = intdiv($cap1, $g1);
        $g2 = self::gcd($r2, $cap2); $r2 = intdiv($r2, $g2); $cap2 = intdiv($cap2, $g2);
        $left = self::mul($r1, $cap2);
        $right = self::mul($r2, $cap1);
        return $left <=> $right;
    }

    private static function mul(int $a, int $b): int
    {
        if ($a !== 0 && abs($b) > intdiv(PHP_INT_MAX, abs($a))) {
            throw new \OverflowException('Unrepresentable capacity ratio');
        }
        return $a * $b;
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) { [$a, $b] = [$b, $a % $b]; }
        return max($a, 1);
    }
}
