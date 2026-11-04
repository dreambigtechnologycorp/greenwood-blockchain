<?php
declare(strict_types=1);
namespace Greenwood\Compute\Scheduling;

use Greenwood\Compute\Protocol\{ComputeState, ProviderCapability, TrustClass};
use Greenwood\Compute\Verification\ProviderEligibility;

/**
 * Assembles scheduler inputs from finalized chain state (Pass 4D section 8).
 *
 * ProviderRecord := ProviderEligibility (trust classes, never self-declared)
 *                 + ProviderCapability (finalized schedulable capacity)
 *                 + reservation view (finalized non-terminal assignments).
 */
final class FinalizedStateAdapter
{
    private const STATUS = [
        ProviderCapability::STATUS_ACTIVE => ProviderStatus::ACTIVE,
        ProviderCapability::STATUS_DRAINING => ProviderStatus::DRAINING,
        ProviderCapability::STATUS_SUSPENDED => ProviderStatus::SUSPENDED,
    ];

    /** @return ProviderRecord[] deterministic ascending provider_id order */
    public static function providerRecords(ComputeState $state, ProviderEligibility $eligibility, int $height): array
    {
        $records = [];
        foreach ($state->capabilities() as $id => $capability) {
            $trust = [];
            foreach ([TrustClass::OPEN, TrustClass::TRUSTED, TrustClass::SOVEREIGN] as $class) {
                if ($eligibility->isEligible($id, $class, $height)) $trust[] = $class;
            }
            if ($trust === []) continue; // A capability alone never grants trust.
            $records[] = new ProviderRecord($id, self::STATUS[$capability->status], $trust, $capability->architectures,
                $capability->cpuCapacityMillis, $capability->memoryCapacityBytes, $capability->pidCapacity,
                $capability->maxConcurrentJobs);
        }
        usort($records, fn (ProviderRecord $a, ProviderRecord $b) => strcmp($a->providerId, $b->providerId));
        return $records;
    }

    /** Ledger hydrated from finalized non-terminal assignments — replayable. */
    public static function ledger(ComputeState $state): ReservationLedger
    {
        $ledger = new ReservationLedger();
        foreach (array_keys($state->capabilities()) as $id) {
            $ledger->injectTotals($id, $state->reserved($id));
        }
        return $ledger;
    }
}
