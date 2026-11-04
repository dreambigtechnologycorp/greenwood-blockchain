<?php
declare(strict_types=1);
namespace Greenwood\Tests\Support;

require_once __DIR__ . '/TetheredPacemakerModel.php';
require_once __DIR__ . '/ValidatorState.php';

use Greenwood\Tests\Support\TetheredPacemakerModel;

function scenarioUntethered(): array {
    $model = new TetheredPacemakerModel(['A' => 31, 'B' => 3, 'C' => 3], [], 30.0, 0.5);
    $model->run(500000, 100000.0);
    return $model->getMetrics();
}

function scenarioFastOnly(): array {
    $model = new TetheredPacemakerModel(['A' => 31, 'B' => 3, 'C' => 3], [], 30.0, 0.5);
    $model->withFastTimeout(true);
    $model->run(500000, 100000.0);
    return $model->getMetrics();
}

function scenarioFastPlusBrake120(): array {
    $model = new TetheredPacemakerModel(['A' => 31, 'B' => 3, 'C' => 3], [], 30.0, 0.5);
    $model->withFastTimeout(true);
    $model->withBrake(true, 120.0);
    $model->run(500000, 100000.0);
    return $model->getMetrics();
}

function scenarioFastPlusBrake180(): array {
    $model = new TetheredPacemakerModel(['A' => 31, 'B' => 3, 'C' => 3], [], 30.0, 0.5);
    $model->withFastTimeout(true);
    $model->withBrake(true, 180.0);
    $model->run(500000, 100000.0);
    return $model->getMetrics();
}

function scenarioFastPlusBrake300(): array {
    $model = new TetheredPacemakerModel(['A' => 31, 'B' => 3, 'C' => 3], [], 30.0, 0.5);
    $model->withFastTimeout(true);
    $model->withBrake(true, 300.0);
    $model->run(500000, 100000.0);
    return $model->getMetrics();
}

function scenarioNormalBaseline(): array {
    $model = new TetheredPacemakerModel(['A' => 3, 'B' => 3, 'C' => 3], [], 30.0, 0.5);
    $model->run(500000, 100000.0);
    return $model->getMetrics();
}

function scenarioByzantineHugeWatermark(): array {
    $model = new TetheredPacemakerModel(['A' => 3, 'B' => 3, 'C' => 3], [
        'A' => ['D' => 1000000], 'B' => ['D' => 1000000], 'C' => ['D' => 1000000]
    ], 30.0, 0.5);
    $model->run(500000, 100000.0);
    return $model->getMetrics();
}

function scenarioSelectiveByzantineDelivery(): array {
    $model = new TetheredPacemakerModel(['A' => 3, 'B' => 3, 'C' => 3], [
        'A' => ['D' => 1000000], 'B' => [], 'C' => []
    ], 30.0, 0.5);
    $model->run(500000, 100000.0);
    $snapshot = $model->getStateSnapshot();
    return [
        'metrics' => $model->getMetrics(),
        'A_round' => $snapshot['rounds']['A'],
        'B_round' => $snapshot['rounds']['B'],
        'C_round' => $snapshot['rounds']['C'],
        'max_honest_separation' => max($snapshot['rounds']['A'], $snapshot['rounds']['B'], $snapshot['rounds']['C']) - min($snapshot['rounds']['A'], $snapshot['rounds']['B'], $snapshot['rounds']['C']),
    ];
}

function scenarioEvidenceEquivocation(): array {
    $model = new TetheredPacemakerModel(['A' => 3, 'B' => 3, 'C' => 3], [
        'B' => ['D' => 1000], 'C' => ['D' => 2000]
    ], 30.0, 0.5);
    $model->run(500000, 100000.0);
    $snapshot = $model->getStateSnapshot();
    return [
        'metrics' => $model->getMetrics(),
        'watermark_history' => [
            'B_watermarks_D' => $snapshot['watermarks']['B']['D'] ?? 0,
            'C_watermarks_D' => $snapshot['watermarks']['C']['D'] ?? 0,
        ],
    ];
}

function scenarioStaleEvidence(): array {
    $model = new TetheredPacemakerModel(['A' => 31, 'B' => 3, 'C' => 3], [
        'A' => ['B' => 3]
    ], 30.0, 0.5);
    $model->run(500000, 100000.0);
    $snapshot = $model->getStateSnapshot();
    return [
        'metrics' => $model->getMetrics(),
        'A_watermark_B' => $snapshot['watermarks']['A']['B'] ?? 0,
        'A_local_round' => $snapshot['rounds']['A'],
    ];
}

function scenarioFutureEvidence(): array {
    $model = new TetheredPacemakerModel(['A' => 3, 'B' => 3, 'C' => 3], [
        'B' => ['A' => 31]
    ], 30.0, 0.5);
    $model->run(500000, 100000.0);
    $snapshot = $model->getStateSnapshot();
    return [
        'metrics' => $model->getMetrics(),
        'B_watermark_A' => $snapshot['watermarks']['B']['A'] ?? 0,
        'B_local_round' => $snapshot['rounds']['B'],
    ];
}

$results = [
    'UNTETHERED' => scenarioUntethered(),
    'FAST_ONLY' => scenarioFastOnly(),
    'FAST_BRAKE_120' => scenarioFastPlusBrake120(),
    'FAST_BRAKE_180' => scenarioFastPlusBrake180(),
    'FAST_BRAKE_300' => scenarioFastPlusBrake300(),
    'NORMAL_BASELINE' => scenarioNormalBaseline(),
    'BYZANTINE_HUGE_WATERMARK' => scenarioByzantineHugeWatermark(),
    'SELECTIVE_BYZANTINE' => scenarioSelectiveByzantineDelivery(),
    'EVIDENCE_EQUIVOCATION' => scenarioEvidenceEquivocation(),
    'STALE_EVIDENCE' => scenarioStaleEvidence(),
    'FUTURE_EVIDENCE' => scenarioFutureEvidence(),
];

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
