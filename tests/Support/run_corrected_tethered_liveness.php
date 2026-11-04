<?php

declare(strict_types=1);

namespace Greenwood\Tests\Support;

require_once __DIR__ . '/TetheredPacemakerModel.php';
require_once __DIR__ . '/ValidatorState.php';

use Greenwood\Tests\Support\TetheredPacemakerModel;

function getOnlineValidators(TetheredPacemakerModel $model): array
{
    $online = [];
    foreach (['A', 'B', 'C', 'D'] as $id) {
        if ($model->getValidator($id)->online) {
            $online[] = $id;
        }
    }
    return $online;
}

function signAndDeliverCurrentRound(TetheredPacemakerModel $model, string $validatorId): void
{
    $v = $model->getValidator($validatorId);
    $currentRound = $v->localRound;
    
    if (!isset($v->signedRounds[$currentRound])) {
        $v->recordSignedRound($currentRound);
        
        foreach (getOnlineValidators($model) as $other) {
            if ($other !== $validatorId) {
                $model->deliverSignedRound($validatorId, $other, $currentRound);
            }
        }
    }
}

function signAllOnlineValidators(TetheredPacemakerModel $model): void
{
    foreach (getOnlineValidators($model) as $id) {
        signAndDeliverCurrentRound($model, $id);
    }
}

function calculateGap(TetheredPacemakerModel $model, array $online): int
{
    $rounds = [];
    foreach ($online as $id) {
        $rounds[] = $model->getValidator($id)->localRound;
    }
    if (empty($rounds)) return 0;
    return max($rounds) - min($rounds);
}

function calculateWatermarksAt(TetheredPacemakerModel $model, string $validatorId): array
{
    return $model->getValidator($validatorId)->watermarks;
}

function experimentRunner(): array
{
    $maxEvents = 250000;
    $sustainedWindow = 100;
    
    $results = [];
    
    foreach (['baseline', 'fast_only'] as $scenario) {
        $model = new TetheredPacemakerModel(
            ['A' => 31, 'B' => 3, 'C' => 3, 'D' => 3],
            [],
            30.0,
            0.5
        );
        
        $model->getValidator('D')->online = false;
        $model->withBrake(false);
        
        if ($scenario === 'fast_only') {
            $model->withFastTimeout(true);
        }
        
        $online = getOnlineValidators($model);
        signAllOnlineValidators($model);
        
        $initialGap = calculateGap($model, $online);
        $maximumGap = $initialGap;
        $minimumGap = $initialGap;
        $finalGap = 0;
        
        $firstGapLE2Event = null;
        $firstGapLE2Time = null;
        $firstGapLE1Event = null;
        $firstGapLE1Time = null;
        $firstExactConvergenceEvent = null;
        $firstExactConvergenceTime = null;
        
        $firstSustainedGapLE1Event = null;
        $firstSustainedGapLE1Time = null;
        $sustainedCount = 0;
        
        $events = 0;
        
        while ($events < $maxEvents) {
            $eventOccurred = $model->step();
            if (!$eventOccurred) break;
            
            $events++;
            signAllOnlineValidators($model);
            
            $currentGap = calculateGap($model, $online);
            
            if ($currentGap > $maximumGap) $maximumGap = $currentGap;
            if ($currentGap < $minimumGap) $minimumGap = $currentGap;
            
            if ($firstGapLE2Event === null && $currentGap <= 2) {
                $firstGapLE2Event = $events;
                $firstGapLE2Time = $model->getSimulatedTime();
            }
            
            if ($firstGapLE1Event === null && $currentGap <= 1) {
                $firstGapLE1Event = $events;
                $firstGapLE1Time = $model->getSimulatedTime();
            }
            
            if ($firstExactConvergenceEvent === null) {
                $rounds = [$model->getValidator('A')->localRound, $model->getValidator('B')->localRound, $model->getValidator('C')->localRound];
                if (count(array_unique($rounds)) === 1) {
                    $firstExactConvergenceEvent = $events;
                    $firstExactConvergenceTime = $model->getSimulatedTime();
                }
            }
            
            if ($currentGap <= 1) {
                $sustainedCount++;
                if ($sustainedCount === $sustainedWindow && $firstSustainedGapLE1Event === null) {
                    $firstSustainedGapLE1Event = $events;
                    $firstSustainedGapLE1Time = $model->getSimulatedTime();
                }
            } else {
                $sustainedCount = 0;
            }
            
            $finalGap = $currentGap;
            
            if ($firstSustainedGapLE1Event !== null) break;
        }
        
        $finalRounds = [
            'A' => $model->getValidator('A')->localRound,
            'B' => $model->getValidator('B')->localRound,
            'C' => $model->getValidator('C')->localRound,
            'D' => $model->getValidator('D')->localRound,
        ];
        
        $signedRoundCounts = [
            'A' => count($model->getValidator('A')->signedRounds),
            'B' => count($model->getValidator('B')->signedRounds),
            'C' => count($model->getValidator('C')->signedRounds),
        ];
        
        $highestSignedRounds = [
            'A' => empty($model->getValidator('A')->signedRounds) ? 0 : max(array_keys($model->getValidator('A')->signedRounds)),
            'B' => empty($model->getValidator('B')->signedRounds) ? 0 : max(array_keys($model->getValidator('B')->signedRounds)),
            'C' => empty($model->getValidator('C')->signedRounds) ? 0 : max(array_keys($model->getValidator('C')->signedRounds)),
        ];
        
        $watermarks = [
            'A' => calculateWatermarksAt($model, 'A'),
            'B' => calculateWatermarksAt($model, 'B'),
            'C' => calculateWatermarksAt($model, 'C'),
        ];
        
        $metrics = $model->getMetrics();
        $recoveryAchieved = $firstSustainedGapLE1Event !== null;
        
        $results[$scenario] = [
            'initialGap' => $initialGap,
            'maximumGap' => $maximumGap,
            'minimumGap' => $minimumGap,
            'finalGap' => $finalGap,
            'firstGapLE2Event' => $firstGapLE2Event,
            'firstGapLE2Time' => $firstGapLE2Time,
            'firstGapLE1Event' => $firstGapLE1Event,
            'firstGapLE1Time' => $firstGapLE1Time,
            'firstExactConvergenceEvent' => $firstExactConvergenceEvent,
            'firstExactConvergenceTime' => $firstExactConvergenceTime,
            'firstSustainedGapLE1Event' => $firstSustainedGapLE1Event,
            'firstSustainedGapLE1Time' => $firstSustainedGapLE1Time,
            'fastTimeoutCount' => $metrics['fastTimeoutCount'],
            'actualFloorJumpCount' => $metrics['actualFloorJumpCount'],
            'confirmedFloorAdvanceCount' => $metrics['confirmedFloorAdvanceCount'],
            'finalRounds' => $finalRounds,
            'signedRoundCounts' => $signedRoundCounts,
            'highestSignedRounds' => $highestSignedRounds,
            'watermarks' => $watermarks,
            'events' => $events,
            'simulatedTime' => $model->getSimulatedTime(),
            'recoveryAchieved' => $recoveryAchieved,
        ];
    }
    
    return $results;
}

function main(): void
{
    $results = experimentRunner();
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

main();
