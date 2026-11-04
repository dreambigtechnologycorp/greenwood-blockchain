<?php

declare(strict_types=1);

namespace Greenwood\Tests\Support;

class ValidatorState
{
    public bool $online;
    public int $localRound;
    public ?int $confirmedFloor;
    public array $signedRounds = [];
    public float $effectiveTimeout;
    public float $nextTimeoutAt;
    public int $highestPacemakerRound;
    public array $watermarks = [];
    
    public function __construct(
        public readonly string $id,
        int $initialRound = 0,
        bool $durablePacemakerRound = false
    ) {
        $this->online = true;
        $this->localRound = $initialRound;
        $this->confirmedFloor = null;
        $this->signedRounds = [];
        $this->effectiveTimeout = TetheredPacemakerModel::NORMAL_TIMEOUT;
        $this->nextTimeoutAt = TetheredPacemakerModel::NORMAL_TIMEOUT;
        $this->highestPacemakerRound = $initialRound;
        
        if ($durablePacemakerRound) {
            $this->highestPacemakerRound = max($this->highestPacemakerRound, 1);
        }
    }
    
    public function getEffectiveTimeout(): float
    {
        return $this->effectiveTimeout;
    }
    
    public function recordSignedRound(int $round): void
    {
        $this->signedRounds[$round] = true;
        if (!array_key_exists($this->id, $this->watermarks) || $round > $this->watermarks[$this->id]) {
            $this->watermarks[$this->id] = $round;
        }
    }
}
