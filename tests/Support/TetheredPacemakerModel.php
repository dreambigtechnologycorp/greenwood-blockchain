<?php
declare(strict_types=1);
namespace Greenwood\Tests\Support;

final class TetheredPacemakerModel
{
    public const NORMAL_TIMEOUT = 30.0;
    public const FAST_TIMEOUT = 0.5;
    public const EVENT_LATENCY = 0.1;
    
    private bool $fastTimeoutEnabled = false;
    private bool $brakeEnabled = false;
    private float $brakeMax;
    private bool $byzantineMode = false;
    private ?string $byzantineValidator = null;
    private array $byzantineDeliveries = [];
    private bool $durablePacemakerRound = false;
    
    private float $simulatedTime;
    private array $validators;
    private array $eventQueue;
    private int $eventCount;
    
    private int $fastTimeoutCount = 0;
    private int $confirmedFloorAdvanceCount = 0;
    private int $actualFloorJumpCount = 0;
    private int $brakeActivationCount = 0;
    private int $eventsToRecoveryWindow = -1;
    private int $eventsToFullConvergence = -1;
    private float $timeToRecoveryWindow = -1.0;
    private float $timeToFullConvergence = -1.0;
    private int $maximumGap = 0;
    private int $minimumGap = 999999;
    private int $gapAtFirstFullConvergence = -1;
    private float $timeOfFirstFullConvergence = -1.0;
    private int $finalGap = 0;
    private bool $recoveryWindowEntered = false;
    private bool $fullRoundConvergence = false;
    
    public function __construct(
        private readonly array $initialRounds,
        private readonly array $initialWatermarks = [],
        private readonly float $normalTimeout = self::NORMAL_TIMEOUT,
        private readonly float $fastTimeout = self::FAST_TIMEOUT,
        private readonly float $eventLatency = self::EVENT_LATENCY
    ) {
        $this->simulatedTime = 0.0;
        $this->eventQueue = [];
        $this->eventCount = 0;
        $this->validators = [];
        $this->initializeValidators();
    }
    
    public function withFastTimeout(bool $enabled): self
    {
        $this->fastTimeoutEnabled = $enabled;
        return $this;
    }
    
    public function withBrake(bool $enabled, float $max = 120.0): self
    {
        $this->brakeEnabled = $enabled;
        $this->brakeMax = $max;
        return $this;
    }
    
    public function withByzantine(string $validator, array $deliveries): self
    {
        $this->byzantineMode = true;
        $this->byzantineValidator = $validator;
        $this->byzantineDeliveries = $deliveries;
        return $this;
    }
    
    public function withDurablePacemakerRound(bool $enabled): self
    {
        $this->durablePacemakerRound = $enabled;
        return $this;
    }
    
    private function initializeValidators(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $id) {
            $round = array_key_exists($id, $this->initialRounds) ? (int) $this->initialRounds[$id] : 0;
            $validator = new ValidatorState($id, $round, $this->durablePacemakerRound);
            $validator->online = array_key_exists($id, $this->initialRounds);
            $this->validators[$id] = $validator;
        }
        foreach ($this->initialWatermarks as $validator => $watermarks) {
            foreach ($watermarks as $other => $round) {
                $this->validators[$validator]->watermarks[$other] = (int) $round;
            }
        }
        $this->recalculateAllConfirmedFloors();
        foreach ($this->validators as $validator) {
            $validator->nextTimeoutAt = $validator->effectiveTimeout;
        }
        foreach ($this->validators as $id => $validator) {
            if ($validator->online) {
                $this->scheduleTimeout($id, $validator->nextTimeoutAt);
            }
        }
    }
    
    private function recalculateAllConfirmedFloors(): void
    {
        foreach ($this->validators as $validator) {
            if (!$validator->online) continue;
            $validator->confirmedFloor = $this->calculateConfirmedFloor($validator->id);
        }
    }
    
    private function calculateConfirmedFloor(string $validatorId): ?int
    {
        $v = $this->validators[$validatorId];
        
        // Canonical membership is always A, B, C, D
        $canonicalMembers = ['A', 'B', 'C', 'D'];
        
        // Collect all watermark values from canonical members (only for this validator's watermarks)
        $floorValues = [];
        foreach ($canonicalMembers as $member) {
            // Only count if the key exists (not null/absent)
            if (array_key_exists($member, $v->watermarks)) {
                $floorValues[] = $v->watermarks[$member];
            }
        }
        
        // If fewer than 2 canonical signer keys present, floor is null
        if (count($floorValues) < 2) {
            return null;
        }
        
        // Sort descending and return the second value (floor is second highest)
        rsort($floorValues);
        return $floorValues[1];
    }
    
    private function scheduleTimeout(string $validatorId, float $time): void
    {
        $v = $this->validators[$validatorId];
        if (!$v->online) return;  // Offline validators do not schedule timeouts
        $this->eventQueue[] = ['time' => $time, 'type' => 'timeout', 'validator' => $validatorId];
        usort($this->eventQueue, fn($a, $b) => $a['time'] <=> $b['time']);
    }
    
    private function scheduleDelivery(string $from, string $to, int $round, float $delay): void
    {
        $time = $this->simulatedTime + $delay;
        $this->eventQueue[] = ['time' => $time, 'type' => 'delivery', 'from' => $from, 'to' => $to, 'round' => $round];
        usort($this->eventQueue, fn($a, $b) => $a['time'] <=> $b['time']);
    }
    
    public function run(int $maxEvents = 10000, float $maxTime = 600.0): void
    {
        while ($this->eventCount < $maxEvents && $this->simulatedTime < $maxTime) {
            if (empty($this->eventQueue)) break;
            $event = array_shift($this->eventQueue);
            $this->simulatedTime = $event['time'];
            $this->eventCount++;
            switch ($event['type']) {
                case 'timeout': $this->handleTimeout($event['validator']); break;
                case 'delivery': $this->handleDelivery($event['from'], $event['to'], $event['round']); break;
            }
            if (!$this->recoveryWindowEntered && $this->checkRecoveryWindow()) {
                $this->recoveryWindowEntered = true;
                $this->eventsToRecoveryWindow = $this->eventCount;
                $this->timeToRecoveryWindow = $this->simulatedTime;
            }
            if (!$this->fullRoundConvergence && $this->checkFullConvergence()) {
                $this->fullRoundConvergence = true;
                $this->eventsToFullConvergence = $this->eventCount;
                $this->timeToFullConvergence = $this->simulatedTime;
                $this->gapAtFirstFullConvergence = $this->finalGap;
            }
            $this->updateMetrics();
        }
    }
    
    public function step(): bool
    {
        if (empty($this->eventQueue)) {
            return false;
        }
        $event = array_shift($this->eventQueue);
        $this->simulatedTime = $event['time'];
        $this->eventCount++;
        switch ($event['type']) {
            case 'timeout': $this->handleTimeout($event['validator']); break;
            case 'delivery': $this->handleDelivery($event['from'], $event['to'], $event['round']); break;
        }
        if (!$this->recoveryWindowEntered && $this->checkRecoveryWindow()) {
            $this->recoveryWindowEntered = true;
            $this->eventsToRecoveryWindow = $this->eventCount;
            $this->timeToRecoveryWindow = $this->simulatedTime;
        }
        if (!$this->fullRoundConvergence && $this->checkFullConvergence()) {
            $this->fullRoundConvergence = true;
            $this->eventsToFullConvergence = $this->eventCount;
            $this->timeToFullConvergence = $this->simulatedTime;
            $this->gapAtFirstFullConvergence = $this->finalGap;
        }
        $this->updateMetrics();
        return true;
    }
    
    private function handleTimeout(string $validatorId): void
    {
        $v = $this->validators[$validatorId];
        if (!$v->online) return;  // Offline validators do not process timeout events
        if (!$v->online) return;  // Offline validators do not process timeout events
        $proposer = $this->getProposer(1, $v->localRound);
        $proposerAhead = false;
        if ($proposer !== $validatorId && isset($v->watermarks[$proposer])) {
            if ($v->watermarks[$proposer] > $v->localRound) $proposerAhead = true;
        }
        
        $decision = $this->timeoutDecisionForValidator($validatorId);
        $timeout = $decision['effective'];
        
        if ($this->fastTimeoutEnabled && $proposerAhead) {
            $timeout = $this->fastTimeout;
            $this->fastTimeoutCount++;
        }
        
        if ($decision['brakeActivated']) {
            $this->brakeActivationCount++;
        }
        
        $v->effectiveTimeout = $timeout;
        $v->localRound++;
        $v->highestPacemakerRound = max($v->highestPacemakerRound, $v->localRound);
        $v->nextTimeoutAt = $this->simulatedTime + $timeout;
        $this->scheduleTimeout($validatorId, $v->nextTimeoutAt);
        // Pacemaker movement alone does NOT create authenticated evidence
        // Watermarks are only updated via explicit deliverSignedRound()
    }
    
    private function handleDelivery(string $from, string $to, int $round): void
    {
        $fromValidator = $this->validators[$from];
        $toValidator = $this->validators[$to];
        
        // Only accept evidence that has been explicitly signed by the sender
        if (!isset($fromValidator->signedRounds[$round])) {
            return; // Unsigned evidence is rejected
        }
        
        if ($this->byzantineMode && $from === $this->byzantineValidator) {
            if (isset($this->byzantineDeliveries[$to][$from])) $round = $this->byzantineDeliveries[$to][$from];
        }
        
        // Monotonic watermark update
        if (
            !array_key_exists($from, $toValidator->watermarks)
            || $round > $toValidator->watermarks[$from]
        ) {
            $toValidator->watermarks[$from] = $round;
        }
        
        $oldFloor = $toValidator->confirmedFloor;
        $newFloor = $this->calculateConfirmedFloor($to);
        $toValidator->confirmedFloor = $newFloor;
        
        $floorAdvanced = $newFloor !== null && (
            $oldFloor === null || $newFloor > $oldFloor
        );
        if ($floorAdvanced) {
            $this->confirmedFloorAdvanceCount++;
            if ($newFloor !== null && $newFloor > $toValidator->localRound) {
                $toValidator->localRound = $newFloor;
                $toValidator->highestPacemakerRound = max(
                    $toValidator->highestPacemakerRound,
                    $toValidator->localRound
                );
                $this->actualFloorJumpCount++;
            }
        }
    }
    
    private function checkRecoveryWindow(): bool
    {
        $rounds = [];
        foreach ($this->validators as $v) {
            if ($v->online) $rounds[] = $v->localRound;
        }
        if (count($rounds) < 3) return false;
        return (max($rounds) - min($rounds)) <= 1;
    }
    
    private function checkFullConvergence(): bool
    {
        $rounds = [];
        foreach ($this->validators as $v) {
            if ($v->online) $rounds[$v->id] = $v->localRound;
        }
        if (count($rounds) < 3) return false;
        $first = reset($rounds);
        foreach ($rounds as $r) if ($r !== $first) return false;
        return true;
    }
    
    private function updateMetrics(): void
    {
        $rounds = [];
        foreach ($this->validators as $v) {
            if ($v->online) $rounds[] = $v->localRound;
        }
        if (count($rounds) >= 2) {
            $gap = max($rounds) - min($rounds);
            if ($gap > $this->maximumGap) $this->maximumGap = $gap;
            if ($gap < $this->minimumGap) $this->minimumGap = $gap;
            $this->finalGap = $gap;
        }
    }
    
    private function getProposer(int $height, int $round): string
    {
        $ids = array_keys($this->validators);
        sort($ids);
        return $ids[($height + $round) % count($ids)];
    }
    
    public function getStateSnapshot(): array
    {
        $rounds = $watermarks = $floors = [];
        foreach ($this->validators as $id => $v) {
            $rounds[$id] = $v->localRound;
            $watermarks[$id] = $v->watermarks;
            $floors[$id] = $v->confirmedFloor;
        }
        return ['simulatedTime' => $this->simulatedTime, 'eventCount' => $this->eventCount, 'rounds' => $rounds, 'watermarks' => $watermarks, 'confirmedFloors' => $floors];
    }
    
    public function getMetrics(): array
    {
        return [
            'initialGap' => $this->calculateInitialGap(),
            'maximumGap' => $this->maximumGap,
            'minimumGap' => $this->minimumGap === 999999 ? 0 : $this->minimumGap,
            'finalGap' => $this->finalGap,
            'eventsToRecoveryWindow' => $this->eventsToRecoveryWindow,
            'simulatedSecondsToRecoveryWindow' => $this->timeToRecoveryWindow,
            'eventsToFullConvergence' => $this->eventsToFullConvergence,
            'simulatedSecondsToFullConvergence' => $this->timeToFullConvergence,
            'gapAtFirstFullConvergence' => $this->gapAtFirstFullConvergence,
            'timeOfFirstFullConvergence' => $this->timeOfFirstFullConvergence,
            'fastTimeoutCount' => $this->fastTimeoutCount,
            'confirmedFloorAdvanceCount' => $this->confirmedFloorAdvanceCount,
            'actualFloorJumpCount' => $this->actualFloorJumpCount,
            'brakeActivationCount' => $this->brakeActivationCount,
            'recoveryWindowEntered' => $this->recoveryWindowEntered,
            'fullRoundConvergence' => $this->fullRoundConvergence,
            'normal3Of4ConsensusReachable' => $this->fullRoundConvergence,
        ];
    }
    
    public function getValidator(string $id): ValidatorState
    {
        return $this->validators[$id];
    }
    public function getSimulatedTime(): float
    {
        return $this->simulatedTime;
    }

    public function timeoutDecisionForValidator(string $validatorId): array
    {
        $v = $this->validators[$validatorId];
        
        $normal = $this->normalTimeoutForRound($v->localRound);
        $effective = $normal;
        $brakeActivated = false;
        $gap = null;
        $candidate = null;
        
        if ($this->brakeEnabled) {
            if ($v->confirmedFloor !== null && $v->localRound > $v->confirmedFloor) {
                $gap = $v->localRound - $v->confirmedFloor;
                $candidate = $normal * (1.0 + $gap * 0.5);
                
                if ($this->brakeMax < $normal) {
                    throw new \InvalidArgumentException('brakeMax cannot be below normal timeout');
                }
                
                $effective = $candidate < $this->brakeMax ? $candidate : $this->brakeMax;
                $brakeActivated = true;
            }
        }
        
        return [
            'normal' => $normal,
            'gap' => $gap,
            'candidate' => $candidate,
            'effective' => $effective,
            'brakeActivated' => $brakeActivated,
        ];
    }
    
    private function calculateInitialGap(): int
    {
        $rounds = array_values($this->initialRounds);
        return max($rounds) - min($rounds);
    }

    public function normalTimeoutForRound(int $round): float
    {
        $unit = min(10.0, 1.0 + 0.5 * $round);
        return 3.0 * $unit;
    }

    public function deliverSignedRound(string $from, string $to, int $round): void
    {
        $fromValidator = $this->validators[$from];
        $toValidator = $this->validators[$to];
        
        // Only accept evidence that has been explicitly signed by the sender
        if (!isset($fromValidator->signedRounds[$round])) {
            return; // Unsigned evidence is rejected
        }
        
        // Monotonic watermark update
        if (
            !array_key_exists($from, $toValidator->watermarks)
            || $round > $toValidator->watermarks[$from]
        ) {
            $toValidator->watermarks[$from] = $round;
        }
        
        $oldFloor = $toValidator->confirmedFloor;
        $newFloor = $this->calculateConfirmedFloor($to);
        $toValidator->confirmedFloor = $newFloor;
        
        $floorAdvanced = $newFloor !== null && (
            $oldFloor === null || $newFloor > $oldFloor
        );
        if ($floorAdvanced) {
            $this->confirmedFloorAdvanceCount++;
            if ($newFloor !== null && $newFloor > $toValidator->localRound) {
                $toValidator->localRound = $newFloor;
                $toValidator->highestPacemakerRound = max(
                    $toValidator->highestPacemakerRound,
                    $toValidator->localRound
                );
                $this->actualFloorJumpCount++;
            }
        }
    }
}
