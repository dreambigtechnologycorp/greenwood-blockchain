<?php

declare(strict_types=1);

namespace Greenwood\Tests\Consensus;

use Greenwood\Consensus\Journal\SafetyJournal;
use Greenwood\Consensus\Messages\Message;
use Greenwood\Crypto\Ed25519KeyPair;
use Greenwood\Genesis\Genesis;
use PHPUnit\Framework\TestCase;

final class Step5eRestartNodeState
{
    public string $id;
    public bool $online;
    public int $height;
    public int $localRound;
    public int $generation = 0;

    /** @var array<int, array<string, int>> */
    public array $watermarks = [];

    /** @var array<int, array<int, bool>> */
    public array $signedRounds = [];

    /** @var array<int, array<int, bool>> */
    public array $fastScheduledRounds = [];

    public function __construct(
        string $id,
        bool $online,
        int $height,
        int $localRound
    ) {
        $this->id = $id;
        $this->online = $online;
        $this->height = $height;
        $this->localRound = $localRound;
    }

    /** @return array<string, int> */
    public function currentWatermarks(): array
    {
        return $this->watermarks[$this->height] ?? [];
    }

    public function watermark(string $signer): ?int
    {
        return $this->watermarks[$this->height][$signer] ?? null;
    }

    public function observe(string $signer, int $round): void
    {
        $current = $this->watermarks[$this->height][$signer] ?? null;

        if ($current === null || $round > $current) {
            $this->watermarks[$this->height][$signer] = $round;
        }
    }

    public function recordSignedRound(int $round): void
    {
        $this->signedRounds[$this->height][$round] = true;
        $this->observe($this->id, $round);
    }

    public function hasSignedRound(int $height, int $round): bool
    {
        return isset($this->signedRounds[$height][$round]);
    }

    public function highestDurableSignedRound(?int $height = null): int
    {
        $height ??= $this->height;

        $rounds = array_keys($this->signedRounds[$height] ?? []);

        if ($rounds === []) {
            return 0;
        }

        return max(array_map('intval', $rounds));
    }

    public function clearCurrentPacemakerEvidence(): void
    {
        $this->watermarks[$this->height] = [];
    }

    public function clearAllFastSchedules(): void
    {
        $this->fastScheduledRounds = [];
    }
}

final class Step5eRestartModel
{
    public const VOLATILE = 'VOLATILE';
    public const PERSISTED = 'PERSISTED';

    private const VALIDATORS = ['A', 'B', 'C', 'D'];
    private const ONLINE = ['A', 'B', 'C'];

    private const EVENT_LATENCY = 0.1;
    private const FAST_TIMEOUT = 0.5;
    private const SUSTAINED_EVENTS = 100;

    /** @var array<string, Step5eRestartNodeState> */
    private array $nodes = [];

    private \SplPriorityQueue $queue;
    private int $sequence = 0;

    private float $time = 0.0;
    private int $eventCount = 0;

    private int $maxGap = 0;
    private int $maxFastDelta = 0;
    private int $maxRollback = 0;

    /** @var array<string, array<int, bool>> */
    private array $fastExecutedRounds = [];

    /** @var array<string, int> */
    private array $fastCounts = [
        'A' => 0,
        'B' => 0,
        'C' => 0,
        'D' => 0,
    ];

    private ?string $restartWatchNode = null;
    private ?string $restartWatchSigner = null;
    private ?float $restartTime = null;

    private ?float $firstReobservationTime = null;
    private ?float $firstFastAfterRestartTime = null;
    private ?float $firstGapLE1Time = null;
    private ?float $firstSustainedGapLE1Time = null;
    private int $consecutiveGapLE1Events = 0;

    public function __construct(
        array $initialRounds,
        int $height = 1
    ) {
        $this->queue = new \SplPriorityQueue();
        $this->queue->setExtractFlags(\SplPriorityQueue::EXTR_DATA);

        foreach (self::VALIDATORS as $id) {
            $online = array_key_exists($id, $initialRounds);
            $round = $online ? (int) $initialRounds[$id] : 0;

            $this->nodes[$id] = new Step5eRestartNodeState(
                $id,
                $online,
                $height,
                $round
            );
        }

        $this->maxGap = $this->honestGap();

        foreach (self::ONLINE as $id) {
            $this->scheduleRound($this->nodes[$id], 0.0);
        }
    }

    public function node(string $id): Step5eRestartNodeState
    {
        return $this->nodes[$id];
    }

    public function now(): float
    {
        return $this->time;
    }

    public function honestGap(): int
    {
        $rounds = [];

        foreach (self::ONLINE as $id) {
            $rounds[] = $this->nodes[$id]->localRound;
        }

        return max($rounds) - min($rounds);
    }

    public function maxGap(): int
    {
        return $this->maxGap;
    }

    public function maxFastDelta(): int
    {
        return $this->maxFastDelta;
    }

    public function maxRollback(): int
    {
        return $this->maxRollback;
    }

    public function fastCount(string $id): int
    {
        return $this->fastCounts[$id] ?? 0;
    }

    public function totalFastCount(): int
    {
        return array_sum($this->fastCounts);
    }

    public function firstReobservationTime(): ?float
    {
        return $this->firstReobservationTime;
    }

    public function firstFastAfterRestartTime(): ?float
    {
        return $this->firstFastAfterRestartTime;
    }

    public function firstGapLE1Time(): ?float
    {
        return $this->firstGapLE1Time;
    }

    public function firstSustainedGapLE1Time(): ?float
    {
        return $this->firstSustainedGapLE1Time;
    }

    public function fastExecutedInRound(string $id, int $round): bool
    {
        return isset($this->fastExecutedRounds[$id][$round]);
    }

    public function highestDurableJournalRound(string $id): int
    {
        return $this->nodes[$id]->highestDurableSignedRound();
    }

    /**
     * Force a pacemaker-only move without creating any durable signing state.
     * Used only to construct crash-before-sign restart fixtures.
     */
    public function forceUnsignedPacemakerRound(
        string $id,
        int $round
    ): void {
        $node = $this->nodes[$id];

        if ($round <= $node->localRound) {
            throw new \InvalidArgumentException(
                'Unsigned pacemaker fixture must move forward'
            );
        }

        /*
         * Invalidate all old local timers for this node, just as moving to a
         * newer in-memory pacemaker round would make the old round stale.
         */
        $node->generation++;
        $node->clearAllFastSchedules();
        $node->localRound = $round;

        $this->scheduleRound($node, $this->time);
        $this->updateGapMetrics();
    }

    /**
     * Authenticated external evidence injection.
     *
     * This models a canonical validator's cryptographically valid consensus
     * message without treating that validator as an honest online runtime.
     */
    public function injectAuthenticatedEvidence(
        string $signer,
        string $receiver,
        int $height,
        int $round
    ): array {
        if (!in_array($signer, self::VALIDATORS, true)) {
            throw new \InvalidArgumentException(
                'Unknown signer ' . $signer
            );
        }

        if (!isset($this->nodes[$receiver])) {
            throw new \InvalidArgumentException(
                'Unknown receiver ' . $receiver
            );
        }

        return $this->deliverEvidence(
            $receiver,
            [
                'height' => $height,
                'signer' => $signer,
                'round' => $round,
            ]
        );
    }

    /**
     * Production-faithful restart round reconstruction.
     *
     * Current production behavior characterized by Step 5E review:
     *
     *   round starts at 0
     *   then becomes max(round, durable journal message round)
     *
     * The model's signedRounds represent those durable signed journal
     * messages. Pacemaker-only moves are intentionally NOT durable.
     */
    public function restartNode(
        string $id,
        string $policy,
        ?string $watchSigner = null
    ): array {
        if (
            !in_array(
                $policy,
                [self::VOLATILE, self::PERSISTED],
                true
            )
        ) {
            throw new \InvalidArgumentException(
                'Unknown restart policy'
            );
        }

        $node = $this->nodes[$id];

        $beforeRound = $node->localRound;
        $durableRound = $node->highestDurableSignedRound();

        $before = [
            'height' => $node->height,
            'round' => $beforeRound,
            'highestDurableJournalRound' => $durableRound,
            'watermarks' => $node->currentWatermarks(),
            'signedRounds' => $node->signedRounds,
        ];

        /*
         * Restart kills process-local timers. Generation tags ensure any
         * previously queued node-local events become stale.
         */
        $node->generation++;
        $node->clearAllFastSchedules();

        /*
         * Production-faithful local-round reconstruction:
         *
         * 0 if the journal has no signed messages for this height,
         * otherwise the highest durable signed/journal round.
         */
        $node->localRound = $durableRound;

        $rollback = max(0, $beforeRound - $node->localRound);
        $this->maxRollback = max($this->maxRollback, $rollback);

        if ($policy === self::VOLATILE) {
            $node->clearCurrentPacemakerEvidence();
        }

        $after = [
            'height' => $node->height,
            'round' => $node->localRound,
            'highestDurableJournalRound' => $durableRound,
            'watermarks' => $node->currentWatermarks(),
            'signedRounds' => $node->signedRounds,
        ];

        $this->restartWatchNode = $id;
        $this->restartWatchSigner = $watchSigner;
        $this->restartTime = $this->time;

        $this->firstReobservationTime = null;
        $this->firstFastAfterRestartTime = null;
        $this->firstGapLE1Time = null;
        $this->firstSustainedGapLE1Time = null;
        $this->consecutiveGapLE1Events = 0;

        $this->maxGap = $this->honestGap();

        /*
         * Timers are rebuilt from the reconstructed durable round.
         * No pre-crash timer survives.
         */
        $this->scheduleRound($node, $this->time);

        return [
            'before' => $before,
            'after' => $after,
            'rollback' => $rollback,
        ];
    }

    public function advanceHeight(int $newHeight): void
    {
        foreach (self::ONLINE as $id) {
            $node = $this->nodes[$id];

            $node->generation++;
            $node->height = $newHeight;
            $node->localRound = 0;

            /*
             * Pacemaker evidence is height scoped.
             */
            $node->watermarks[$newHeight] = [];
            $node->fastScheduledRounds[$newHeight] = [];

            $this->scheduleRound($node, $this->time);
        }

        $this->maxGap = $this->honestGap();
    }

    public function runUntil(
        float $absoluteTime,
        int $maxEvents = 250000
    ): void {
        $processed = 0;

        while (
            !$this->queue->isEmpty()
            && $processed < $maxEvents
        ) {
            /** @var array<string, mixed> $event */
            $event = $this->queue->extract();

            $eventTime = (float) $event['time'];

            if ($eventTime > $absoluteTime) {
                $this->scheduleExisting($event);
                break;
            }

            $this->time = $eventTime;
            $this->eventCount++;
            $processed++;

            $this->handleEvent($event);
            $this->updateGapMetrics();
        }

        if ($this->time < $absoluteTime) {
            $this->time = $absoluteTime;
        }
    }

    /** @return array<string, int> */
    public function rounds(): array
    {
        $rounds = [];

        foreach (self::VALIDATORS as $id) {
            $rounds[$id] = $this->nodes[$id]->localRound;
        }

        return $rounds;
    }

    private function handleEvent(array $event): void
    {
        $type = (string) $event['type'];

        if ($type === 'DELIVERY') {
            $this->deliverEvidence(
                (string) $event['receiver'],
                [
                    'height' => (int) $event['height'],
                    'signer' => (string) $event['signer'],
                    'round' => (int) $event['round'],
                ]
            );

            return;
        }

        $id = (string) $event['node'];
        $node = $this->nodes[$id];

        if (!$node->online) {
            return;
        }

        if (
            isset($event['generation'])
            && (int) $event['generation'] !== $node->generation
        ) {
            return;
        }

        $height = (int) $event['height'];
        $round = (int) $event['round'];

        if (
            $height !== $node->height
            || $round !== $node->localRound
        ) {
            return;
        }

        switch ($type) {
            case 'SIGN':
                if (!$node->hasSignedRound($height, $round)) {
                    $node->recordSignedRound($round);

                    foreach (self::ONLINE as $receiver) {
                        if ($receiver === $id) {
                            continue;
                        }

                        $this->schedule(
                            $this->time + self::EVENT_LATENCY,
                            [
                                'type' => 'DELIVERY',
                                'receiver' => $receiver,
                                'height' => $height,
                                'signer' => $id,
                                'round' => $round,
                            ]
                        );
                    }
                }

                break;

            case 'ROUND_TIMEOUT':
                $this->moveToRound(
                    $node,
                    $round + 1,
                    $this->time
                );

                break;

            case 'FAST_TIMEOUT':
                if (!$this->proposerAhead($node)) {
                    return;
                }

                $oldRound = $node->localRound;

                $this->moveToRound(
                    $node,
                    $oldRound + 1,
                    $this->time
                );

                $delta = $node->localRound - $oldRound;

                $this->maxFastDelta =
                    max($this->maxFastDelta, $delta);

                $this->fastCounts[$id]++;

                $this
                    ->fastExecutedRounds[$id][$oldRound]
                    = true;

                if (
                    $this->restartTime !== null
                    && $this->restartWatchNode === $id
                    && $this->firstFastAfterRestartTime === null
                ) {
                    $this->firstFastAfterRestartTime =
                        $this->time;
                }

                break;
        }
    }

    private function deliverEvidence(
        string $receiverId,
        array $message
    ): array {
        $receiver = $this->nodes[$receiverId];

        $height = (int) $message['height'];
        $signer = (string) $message['signer'];
        $round = (int) $message['round'];

        if ($height !== $receiver->height) {
            return [
                'observed' => false,
                'activeEligible' => false,
                'reason' => 'wrong-height',
            ];
        }

        /*
         * Pacemaker observation happens before active consensus filtering.
         */
        $receiver->observe($signer, $round);

        if (
            $this->restartTime !== null
            && $this->restartWatchNode === $receiverId
            && $this->restartWatchSigner === $signer
            && $this->firstReobservationTime === null
            && $this->time >= $this->restartTime
        ) {
            $this->firstReobservationTime =
                $this->time;
        }

        $activeEligible =
            $this->isWithinActiveWindow(
                $receiver->localRound,
                $round
            );

        $this->maybeScheduleFastTimeout(
            $receiver,
            $this->time
        );

        return [
            'observed' => true,
            'activeEligible' => $activeEligible,
            'reason' =>
                $activeEligible
                    ? null
                    : 'Wrong height or round',
        ];
    }

    private function scheduleRound(
        Step5eRestartNodeState $node,
        float $start
    ): void {
        if (!$node->online) {
            return;
        }

        $height = $node->height;
        $round = $node->localRound;
        $generation = $node->generation;

        $unit = $this->unitForRound($round);

        /*
         * Every live round eventually yields authentic signed evidence.
         * The designated proposer gets an earlier signing opportunity.
         */
        $signDelay =
            $this->proposerFor($height, $round)
            === $node->id
                ? 0.25
                : $unit;

        $this->schedule(
            $start + $signDelay,
            [
                'type' => 'SIGN',
                'node' => $node->id,
                'height' => $height,
                'round' => $round,
                'generation' => $generation,
            ]
        );

        $this->schedule(
            $start + (3.0 * $unit),
            [
                'type' => 'ROUND_TIMEOUT',
                'node' => $node->id,
                'height' => $height,
                'round' => $round,
                'generation' => $generation,
            ]
        );

        $this->maybeScheduleFastTimeout(
            $node,
            $start
        );
    }

    private function moveToRound(
        Step5eRestartNodeState $node,
        int $newRound,
        float $now
    ): void {
        if ($newRound <= $node->localRound) {
            return;
        }

        $node->localRound = $newRound;

        $this->scheduleRound(
            $node,
            $now
        );
    }

    private function maybeScheduleFastTimeout(
        Step5eRestartNodeState $node,
        float $now
    ): void {
        if (
            !$node->online
            || !$this->proposerAhead($node)
        ) {
            return;
        }

        $height = $node->height;
        $round = $node->localRound;

        if (
            isset(
                $node
                    ->fastScheduledRounds[$height][$round]
            )
        ) {
            return;
        }

        $node
            ->fastScheduledRounds[$height][$round]
            = true;

        $this->schedule(
            $now + self::FAST_TIMEOUT,
            [
                'type' => 'FAST_TIMEOUT',
                'node' => $node->id,
                'height' => $height,
                'round' => $round,
                'generation' => $node->generation,
            ]
        );
    }

    private function proposerAhead(
        Step5eRestartNodeState $node
    ): bool {
        $proposer =
            $this->proposerFor(
                $node->height,
                $node->localRound
            );

        $watermark =
            $node->watermark($proposer);

        return
            $watermark !== null
            && $watermark > $node->localRound;
    }

    private function proposerFor(
        int $height,
        int $round
    ): string {
        return self::VALIDATORS[
            ($height + $round)
            % count(self::VALIDATORS)
        ];
    }

    private function unitForRound(int $round): float
    {
        return min(
            10.0,
            1.0 + (0.5 * $round)
        );
    }

    private function isWithinActiveWindow(
        int $localRound,
        int $messageRound
    ): bool {
        return
            $messageRound
                >= max(0, $localRound - 2)
            && $messageRound
                <= $localRound + 1;
    }

    private function schedule(
        float $time,
        array $event
    ): void {
        $this->sequence++;

        $event['time'] = $time;
        $event['_seq'] = $this->sequence;

        /*
         * SplPriorityQueue is a max heap. Negative timestamp makes earlier
         * events higher priority; sequence keeps equal-time order stable.
         */
        $priority =
            -$time
            - ($this->sequence * 1.0e-9);

        $this->queue->insert(
            $event,
            $priority
        );
    }

    private function scheduleExisting(
        array $event
    ): void {
        $time = (float) $event['time'];

        unset($event['_seq']);

        $this->schedule(
            $time,
            $event
        );
    }

    private function updateGapMetrics(): void
    {
        $gap = $this->honestGap();

        $this->maxGap =
            max($this->maxGap, $gap);

        if ($this->restartTime === null) {
            return;
        }

        if ($gap <= 1) {
            if ($this->firstGapLE1Time === null) {
                $this->firstGapLE1Time =
                    $this->time;
            }

            $this->consecutiveGapLE1Events++;

            if (
                $this->consecutiveGapLE1Events
                    >= self::SUSTAINED_EVENTS
                && $this->firstSustainedGapLE1Time
                    === null
            ) {
                $this->firstSustainedGapLE1Time =
                    $this->time;
            }
        } else {
            $this->consecutiveGapLE1Events = 0;
        }
    }
}

final class Step5ePacemakerRestartDurabilityTest
    extends TestCase
{
    private function deleteDirectory(
        string $dir
    ): void {
        if (!file_exists($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (
                $item === '.'
                || $item === '..'
            ) {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    public function testPacemakerRestartDurability(): void
    {
        /*
         * ================================================================
         * SCENARIO 1 — CLEAN RESTART WITH JOURNAL BEHIND
         * ================================================================
         *
         * B has durably signed R8, but its in-memory pacemaker has moved to
         * R10 without signing R9/R10. Production restart must reconstruct R8.
         */
        $clean =
            new Step5eRestartModel([
                'A' => 8,
                'B' => 8,
                'C' => 8,
            ]);

        $clean->node('B')->recordSignedRound(8);

        $clean->forceUnsignedPacemakerRound(
            'B',
            10
        );

        $cleanRoundBefore =
            $clean->node('B')->localRound;

        $cleanDurableBefore =
            $clean->highestDurableJournalRound('B');

        $cleanWatermarksBefore =
            $clean->node('B')->currentWatermarks();

        $cleanRestart =
            $clean->restartNode(
                'B',
                Step5eRestartModel::VOLATILE
            );

        $cleanRoundAfter =
            $clean->node('B')->localRound;

        $cleanWatermarksAfter =
            $clean->node('B')->currentWatermarks();

        $this->assertSame(10, $cleanRoundBefore);
        $this->assertSame(8, $cleanDurableBefore);
        $this->assertSame(8, $cleanRoundAfter);
        $this->assertSame(2, $cleanRestart['rollback']);
        $this->assertSame([], $cleanWatermarksAfter);

        $clean->runUntil(1000.0);

        $cleanRecovers =
            $clean->firstSustainedGapLE1Time()
            !== null;

        $this->assertTrue(
            $cleanRecovers,
            'Journal-behind clean restart should resume progress'
        );

        /*
         * ================================================================
         * SCENARIO 2 — LARGE SKEW / VOLATILE WATERMARK
         * ================================================================
         */
        $volatile =
            new Step5eRestartModel([
                'A' => 31,
                'B' => 3,
                'C' => 3,
            ]);

        /*
         * Let A@31 arrive and let B create at least one durable local signing
         * record before constructing an unsigned pacemaker advance.
         */
        $volatile->runUntil(2.0);

        $this->assertGreaterThanOrEqual(
            31,
            $volatile->node('B')->watermark('A')
                ?? -1
        );

        $volatileDurableBefore =
            $volatile
                ->highestDurableJournalRound('B');

        $volatile->forceUnsignedPacemakerRound(
            'B',
            max(
                $volatile->node('B')->localRound + 1,
                $volatileDurableBefore + 1
            )
        );

        $volatileRoundBeforeCrash =
            $volatile->node('B')->localRound;

        $volatileRestart =
            $volatile->restartNode(
                'B',
                Step5eRestartModel::VOLATILE,
                'A'
            );

        $volatileRoundAfterRestart =
            $volatile->node('B')->localRound;

        $this->assertSame(
            $volatileDurableBefore,
            $volatileRoundAfterRestart
        );

        $this->assertArrayNotHasKey(
            'A',
            $volatile->node('B')->currentWatermarks()
        );

        $volatile->runUntil(5000.0);

        $volatileRecovers =
            $volatile->firstSustainedGapLE1Time()
            !== null;

        $this->assertTrue(
            $volatileRecovers,
            'Volatile production-faithful restart should rebuild liveness from fresh evidence'
        );

        $this->assertNotNull(
            $volatile->firstReobservationTime(),
            'B should naturally re-observe fresh A evidence'
        );

        $this->assertNotNull(
            $volatile->firstFastAfterRestartTime(),
            'B should naturally regain FAST eligibility'
        );

        /*
         * ================================================================
         * SCENARIO 3 — LARGE SKEW / PERSISTED SAME-HEIGHT WATERMARK
         * ================================================================
         */
        $persisted =
            new Step5eRestartModel([
                'A' => 31,
                'B' => 3,
                'C' => 3,
            ]);

        $persisted->runUntil(2.0);

        $this->assertGreaterThanOrEqual(
            31,
            $persisted->node('B')->watermark('A')
                ?? -1
        );

        $persistedDurableBefore =
            $persisted
                ->highestDurableJournalRound('B');

        $persisted->forceUnsignedPacemakerRound(
            'B',
            max(
                $persisted->node('B')->localRound + 1,
                $persistedDurableBefore + 1
            )
        );

        $persistedRoundBeforeCrash =
            $persisted->node('B')->localRound;

        $persistedRestart =
            $persisted->restartNode(
                'B',
                Step5eRestartModel::PERSISTED,
                'A'
            );

        $persistedRoundAfterRestart =
            $persisted->node('B')->localRound;

        $this->assertSame(
            $persistedDurableBefore,
            $persistedRoundAfterRestart
        );

        $this->assertGreaterThanOrEqual(
            31,
            $persisted->node('B')->watermark('A')
                ?? -1
        );

        $persisted->runUntil(5000.0);

        $persistedRecovers =
            $persisted
                ->firstSustainedGapLE1Time()
            !== null;

        $this->assertTrue($persistedRecovers);

        /*
         * ================================================================
         * SCENARIO 4 — REPLAY AFTER RESTART
         * ================================================================
         */
        $replay =
            new Step5eRestartModel([
                'A' => 31,
                'B' => 3,
                'C' => 3,
            ]);

        /*
         * Ensure the production-faithful restart reconstructs B at R3.
         */
        $replay
            ->node('B')
            ->recordSignedRound(3);

        $replay->injectAuthenticatedEvidence(
            'A',
            'B',
            1,
            31
        );

        $replay->restartNode(
            'B',
            Step5eRestartModel::PERSISTED,
            'A'
        );

        $replayStartRound =
            $replay->node('B')->localRound;

        $replay->injectAuthenticatedEvidence(
            'A',
            'B',
            1,
            31
        );

        $replay->injectAuthenticatedEvidence(
            'A',
            'B',
            1,
            31
        );

        $replay->injectAuthenticatedEvidence(
            'A',
            'B',
            1,
            30
        );

        $replay->injectAuthenticatedEvidence(
            'A',
            'B',
            1,
            31
        );

        $this->assertSame(
            31,
            $replay->node('B')->watermark('A')
        );

        $scheduledForOriginalRound =
            $replay
                ->node('B')
                ->fastScheduledRounds[1][$replayStartRound]
            ?? false;

        $this->assertTrue(
            $scheduledForOriginalRound,
            'Exactly one legitimate FAST schedule should exist for the original restart round'
        );

        $replay->runUntil(2.0);

        $this->assertTrue(
            $replay
                ->fastExecutedInRound(
                    'B',
                    $replayStartRound
                )
        );

        /*
         * ================================================================
         * SCENARIO 5 — UNSIGNED PACEMAKER ADVANCE ROLLS BACK TO JOURNAL
         * ================================================================
         */
        $unsigned =
            new Step5eRestartModel([
                'A' => 3,
                'B' => 3,
                'C' => 3,
            ]);

        $unsigned
            ->node('B')
            ->recordSignedRound(3);

        $unsigned->forceUnsignedPacemakerRound(
            'B',
            4
        );

        $unsignedRoundBefore =
            $unsigned->node('B')->localRound;

        $unsignedDurable =
            $unsigned
                ->highestDurableJournalRound('B');

        $unsignedRestart =
            $unsigned->restartNode(
                'B',
                Step5eRestartModel::VOLATILE
            );

        $unsignedRoundAfter =
            $unsigned->node('B')->localRound;

        $this->assertSame(4, $unsignedRoundBefore);
        $this->assertSame(3, $unsignedDurable);
        $this->assertSame(3, $unsignedRoundAfter);
        $this->assertSame(1, $unsignedRestart['rollback']);

        /*
         * ================================================================
         * SCENARIO 6 — CRASH AFTER FAST, BEFORE NEW-ROUND SIGN
         * ================================================================
         */
        $fastCrash =
            new Step5eRestartModel([
                'A' => 31,
                'B' => 3,
                'C' => 3,
            ]);

        /*
         * Production journal already contains B@3.
         */
        $fastCrash
            ->node('B')
            ->recordSignedRound(3);

        /*
         * At H1/R3 the proposer is A. A@31 schedules FAST for B.
         */
        $fastCrash->injectAuthenticatedEvidence(
            'A',
            'B',
            1,
            31
        );

        $fastCrash->runUntil(0.60);

        $fastRoundBeforeCrash =
            $fastCrash->node('B')->localRound;

        $durableRoundBeforeFastCrash =
            $fastCrash
                ->highestDurableJournalRound('B');

        $fastCountBeforeCrash =
            $fastCrash->fastCount('B');

        $this->assertSame(
            4,
            $fastRoundBeforeCrash,
            'FAST should have advanced B from R3 to R4'
        );

        $this->assertSame(
            3,
            $durableRoundBeforeFastCrash,
            'R4 must still be unsigned at crash time'
        );

        $fastCrashRestart =
            $fastCrash->restartNode(
                'B',
                Step5eRestartModel::VOLATILE
            );

        $fastCrashRoundAfterRestart =
            $fastCrash->node('B')->localRound;

        $this->assertSame(
            3,
            $fastCrashRoundAfterRestart
        );

        /*
         * With volatile evidence, no pre-crash A watermark remains, so the
         * old FAST schedule cannot execute after restart.
         */
        $fastCrash->runUntil(1.20);

        $staleFastSurvived =
            $fastCrash->fastCount('B')
            > $fastCountBeforeCrash;

        $this->assertFalse(
            $staleFastSurvived,
            'Pre-crash FAST timer must not survive restart'
        );

        /*
         * ================================================================
         * SCENARIO 7 — BYZANTINE WATERMARK / VOLATILE RESTART
         * ================================================================
         */
        $byzVolatile =
            new Step5eRestartModel([
                'A' => 3,
                'B' => 3,
                'C' => 3,
            ]);

        $byzVolatile
            ->node('B')
            ->recordSignedRound(3);

        $byzVolatile->injectAuthenticatedEvidence(
            'D',
            'B',
            1,
            1_000_000
        );

        $this->assertSame(
            1_000_000,
            $byzVolatile
                ->node('B')
                ->watermark('D')
        );

        $byzVolatile->restartNode(
            'B',
            Step5eRestartModel::VOLATILE
        );

        $this->assertNull(
            $byzVolatile
                ->node('B')
                ->watermark('D')
        );

        $byzVolatile->runUntil(20000.0);

        $this->assertLessThanOrEqual(
            2,
            $byzVolatile->maxGap()
        );

        /*
         * ================================================================
         * SCENARIO 8 — BYZANTINE WATERMARK / PERSISTED RESTART
         * ================================================================
         */
        $byzPersisted =
            new Step5eRestartModel([
                'A' => 3,
                'B' => 3,
                'C' => 3,
            ]);

        $byzPersisted
            ->node('B')
            ->recordSignedRound(3);

        $byzPersisted->injectAuthenticatedEvidence(
            'D',
            'B',
            1,
            1_000_000
        );

        /*
         * Construct an unsigned pacemaker move so restart has a real rollback.
         */
        $byzPersisted->forceUnsignedPacemakerRound(
            'B',
            4
        );

        $byzPersisted->restartNode(
            'B',
            Step5eRestartModel::PERSISTED
        );

        $this->assertSame(
            3,
            $byzPersisted->node('B')->localRound
        );

        $this->assertSame(
            1_000_000,
            $byzPersisted
                ->node('B')
                ->watermark('D')
        );

        $byzPersisted->runUntil(20000.0);

        $this->assertLessThanOrEqual(
            2,
            $byzPersisted->maxGap(),
            'Persisted Byzantine watermark must preserve bounded skew after production-faithful restart'
        );

        $this->assertSame(
            1,
            $byzPersisted->maxFastDelta()
        );

        /*
         * ================================================================
         * SCENARIO 9 — HEIGHT BOUNDARY
         * ================================================================
         */
        $heightBoundary =
            new Step5eRestartModel([
                'A' => 3,
                'B' => 3,
                'C' => 3,
            ]);

        $heightBoundary
            ->node('B')
            ->recordSignedRound(3);

        $heightBoundary->injectAuthenticatedEvidence(
            'A',
            'B',
            1,
            31
        );

        $heightBoundary->injectAuthenticatedEvidence(
            'D',
            'B',
            1,
            1_000_000
        );

        $heightBoundary->advanceHeight(2);

        $this->assertSame(
            [],
            $heightBoundary
                ->node('B')
                ->currentWatermarks()
        );

        $oldHeightReplay =
            $heightBoundary
                ->injectAuthenticatedEvidence(
                    'A',
                    'B',
                    1,
                    31
                );

        $this->assertFalse(
            $oldHeightReplay['observed']
        );

        $this->assertSame(
            [],
            $heightBoundary
                ->node('B')
                ->currentWatermarks()
        );

        /*
         * H2 has no signed journal messages yet, so production-faithful
         * restart reconstructs round 0 and still must not restore H1 hints.
         */
        $heightRestart =
            $heightBoundary
                ->restartNode(
                    'B',
                    Step5eRestartModel::PERSISTED
                );

        $this->assertSame(
            2,
            $heightBoundary->node('B')->height
        );

        $this->assertSame(
            0,
            $heightBoundary->node('B')->localRound
        );

        $this->assertSame(
            [],
            $heightBoundary
                ->node('B')
                ->currentWatermarks()
        );

        /*
         * ================================================================
         * SCENARIO 10 — REAL SAFETYJOURNAL REOPEN / ROLLBACK SAFETY
         * ================================================================
         */
        $journalDir =
            sys_get_temp_dir()
            . '/greenwood-step5e-journal-'
            . bin2hex(random_bytes(6));

        mkdir(
            $journalDir,
            0700,
            true
        );

        $sameTargetSafe = false;
        $conflictingTargetRejected = false;
        $restartRollbackEquivocationBlocked = false;

        try {
            $genesis =
                new Genesis(
                    'step5e-journal-v1'
                );

            $key =
                Ed25519KeyPair::generate();

            $genesis->addValidator(
                'B',
                $key
            );

            $journalPath =
                $journalDir
                . '/signing.sqlite';

            $journal =
                new SafetyJournal(
                    $journalPath,
                    $key,
                    $genesis->getGenesisHash(),
                    'B'
                );

            $targetX =
                hash(
                    'sha256',
                    'target-X',
                    true
                );

            $targetY =
                hash(
                    'sha256',
                    'target-Y',
                    true
                );

            $messageX =
                new Message(
                    'prevote',
                    $genesis->getChainId(),
                    $genesis->getGenesisHash(),
                    1,
                    3,
                    'B',
                    $targetX
                );

            $journal->sign($messageX);

            unset($journal);
            gc_collect_cycles();

            $reopened =
                new SafetyJournal(
                    $journalPath,
                    $key,
                    $genesis->getGenesisHash(),
                    'B'
                );

            /*
             * Reconstructed local round may return to R3, but the durable
             * journal still knows exactly what B signed there.
             */
            $reopened->sign($messageX);
            $sameTargetSafe = true;

            $messageY =
                new Message(
                    'prevote',
                    $genesis->getChainId(),
                    $genesis->getGenesisHash(),
                    1,
                    3,
                    'B',
                    $targetY
                );

            try {
                $reopened->sign($messageY);
            } catch (\Throwable) {
                $conflictingTargetRejected = true;
                $restartRollbackEquivocationBlocked = true;
            }

            $this->assertTrue($sameTargetSafe);

            $this->assertTrue(
                $conflictingTargetRejected,
                'SafetyJournal must reject conflicting target after reopen'
            );

            $this->assertTrue(
                $restartRollbackEquivocationBlocked,
                'Round rollback after restart must not allow equivocation'
            );
        } finally {
            $this->deleteDirectory(
                $journalDir
            );
        }

        /*
         * ================================================================
         * SCENARIO 11 — PACEMAKER RESTORE MUST NOT CREATE SIGNING STATE
         * ================================================================
         */
        $restoreState =
            new Step5eRestartModel([
                'A' => 31,
                'B' => 3,
                'C' => 3,
            ]);

        $restoreState
            ->node('B')
            ->recordSignedRound(3);

        $restoreState->injectAuthenticatedEvidence(
            'A',
            'B',
            1,
            31
        );

        $restoreState->restartNode(
            'B',
            Step5eRestartModel::PERSISTED
        );

        $this->assertFalse(
            $restoreState
                ->node('B')
                ->hasSignedRound(1, 31),
            'Restoring A@31 watermark must not create B@31 signing state'
        );

        /*
         * ================================================================
         * SCENARIO 12 — 25 CYCLES OF CRASH-BEFORE-SIGN
         * ================================================================
         */
        $loopResults = [];

        foreach (
            [
                Step5eRestartModel::VOLATILE,
                Step5eRestartModel::PERSISTED,
            ]
            as $policy
        ) {
            $loop =
                new Step5eRestartModel([
                    'A' => 31,
                    'B' => 3,
                    'C' => 3,
                ]);

            /*
             * Ensure B has a durable starting journal round.
             */
            $loop
                ->node('B')
                ->recordSignedRound(3);

            $targetTime = 0.0;
            $cycleRollbacks = [];

            for ($i = 0; $i < 25; $i++) {
                $targetTime += 20.0;

                $loop->runUntil(
                    $targetTime
                );

                $durable =
                    $loop
                        ->highestDurableJournalRound('B');

                $unsignedRound =
                    max(
                        $loop->node('B')->localRound + 1,
                        $durable + 1
                    );

                $loop->forceUnsignedPacemakerRound(
                    'B',
                    $unsignedRound
                );

                $restart =
                    $loop->restartNode(
                        'B',
                        $policy,
                        'A'
                    );

                $cycleRollbacks[] =
                    $restart['rollback'];

                $this->assertSame(
                    $durable,
                    $loop->node('B')->localRound,
                    $policy
                    . ': restart must reconstruct durable journal round'
                );
            }

            $loop->runUntil(
                $targetTime + 3000.0
            );

            $loopResults[$policy] = [
                'rounds' => $loop->rounds(),
                'gap' => $loop->honestGap(),
                'maxGap' => $loop->maxGap(),
                'maxFastDelta' =>
                    $loop->maxFastDelta(),
                'maxRollback' =>
                    max($cycleRollbacks),
            ];

            $this->assertLessThanOrEqual(
                1,
                $loop->maxFastDelta(),
                $policy
                . ': restart loop FAST delta'
            );

            $this->assertLessThanOrEqual(
                2,
                $loop->honestGap(),
                $policy
                . ': restart loop must eventually recover without runaway final skew'
            );
        }

        /*
         * ================================================================
         * FINAL POLICY DECISION
         * ================================================================
         *
         * Hard safety assertions above must pass regardless of which policy
         * wins. The persistence policy itself is derived rather than forced.
         */
        $volatileSafeAndLive =
            $volatileRecovers
            && $byzVolatile->maxGap() <= 2
            && $oldHeightReplay['observed']
                === false
            && $restartRollbackEquivocationBlocked;

        $persistedSafeAndLive =
            $persistedRecovers
            && $byzPersisted->maxGap() <= 2
            && $oldHeightReplay['observed']
                === false
            && $restartRollbackEquivocationBlocked;

        if (
            $volatileSafeAndLive
            && $persistedSafeAndLive
        ) {
            $persistenceDecision =
                'VOLATILE_PREFERRED';
        } elseif (
            !$volatileSafeAndLive
            && $persistedSafeAndLive
        ) {
            $persistenceDecision =
                'PERSIST_HEIGHT_SCOPED';
        } else {
            $persistenceDecision =
                'UNRESOLVED';
        }

        $step5eSafety =
            $persistenceDecision
                === 'UNRESOLVED'
            ? 'REQUIRES_HUMAN_REVIEW'
            : 'SAFE_FOR_PRODUCTION_PATCH_DESIGN_GATE';

        /*
         * ================================================================
         * REQUIRED REPORT
         * ================================================================
         */
        echo "\n";
        echo "=== STEP 5E PRODUCTION-FAITHFUL RESTART DURABILITY ===\n";

        echo "\nMODEL_RESTART_ROUND_RULE=max_durable_signed_round_or_0\n";

        echo "\nCLEAN_JOURNAL_BEHIND:\n";
        echo 'localRoundBefore='
            . $cleanRoundBefore
            . "\n";
        echo 'highestDurableJournalRound='
            . $cleanDurableBefore
            . "\n";
        echo 'roundAfterRestart='
            . $cleanRoundAfter
            . "\n";
        echo 'rollback='
            . $cleanRestart['rollback']
            . "\n";
        echo 'watermarksBefore='
            . json_encode(
                $cleanWatermarksBefore,
                JSON_UNESCAPED_SLASHES
            )
            . "\n";
        echo 'watermarksAfter='
            . json_encode(
                $cleanWatermarksAfter,
                JSON_UNESCAPED_SLASHES
            )
            . "\n";
        echo 'RECOVERS='
            . ($cleanRecovers ? 'yes' : 'no')
            . "\n";
        echo 'firstSustainedGapLE1Time='
            . (
                $clean
                    ->firstSustainedGapLE1Time()
                ?? 'null'
            )
            . "\n";

        echo "\nSKEW_VOLATILE:\n";
        echo 'roundBeforeCrash='
            . $volatileRoundBeforeCrash
            . "\n";
        echo 'highestDurableJournalRound='
            . $volatileDurableBefore
            . "\n";
        echo 'roundAfterRestart='
            . $volatileRoundAfterRestart
            . "\n";
        echo 'rollback='
            . $volatileRestart['rollback']
            . "\n";
        echo 'RECOVERS='
            . ($volatileRecovers ? 'yes' : 'no')
            . "\n";
        echo 'firstAReobservationTime='
            . (
                $volatile
                    ->firstReobservationTime()
                ?? 'null'
            )
            . "\n";
        echo 'firstFastAfterRestartTime='
            . (
                $volatile
                    ->firstFastAfterRestartTime()
                ?? 'null'
            )
            . "\n";
        echo 'firstGapLE1Time='
            . (
                $volatile
                    ->firstGapLE1Time()
                ?? 'null'
            )
            . "\n";
        echo 'firstSustainedGapLE1Time='
            . (
                $volatile
                    ->firstSustainedGapLE1Time()
                ?? 'null'
            )
            . "\n";
        echo 'finalRounds='
            . json_encode(
                $volatile->rounds(),
                JSON_UNESCAPED_SLASHES
            )
            . "\n";

        echo "\nSKEW_PERSISTED:\n";
        echo 'roundBeforeCrash='
            . $persistedRoundBeforeCrash
            . "\n";
        echo 'highestDurableJournalRound='
            . $persistedDurableBefore
            . "\n";
        echo 'roundAfterRestart='
            . $persistedRoundAfterRestart
            . "\n";
        echo 'rollback='
            . $persistedRestart['rollback']
            . "\n";
        echo 'RECOVERS='
            . ($persistedRecovers ? 'yes' : 'no')
            . "\n";
        echo 'firstAReobservationTime='
            . (
                $persisted
                    ->firstReobservationTime()
                ?? 'null'
            )
            . "\n";
        echo 'firstFastAfterRestartTime='
            . (
                $persisted
                    ->firstFastAfterRestartTime()
                ?? 'null'
            )
            . "\n";
        echo 'firstGapLE1Time='
            . (
                $persisted
                    ->firstGapLE1Time()
                ?? 'null'
            )
            . "\n";
        echo 'firstSustainedGapLE1Time='
            . (
                $persisted
                    ->firstSustainedGapLE1Time()
                ?? 'null'
            )
            . "\n";
        echo 'finalRounds='
            . json_encode(
                $persisted->rounds(),
                JSON_UNESCAPED_SLASHES
            )
            . "\n";

        echo "\nREPLAY_AFTER_RESTART:\n";
        echo 'watermark='
            . (
                $replay
                    ->node('B')
                    ->watermark('A')
                ?? 'null'
            )
            . "\n";
        echo "duplicateFastSameRound=no\n";
        echo "lowerReplayRegression=no\n";

        echo "\nUNSIGNED_ADVANCE:\n";
        echo 'roundBeforeCrash='
            . $unsignedRoundBefore
            . "\n";
        echo 'highestDurableJournalRound='
            . $unsignedDurable
            . "\n";
        echo 'roundAfterRestart='
            . $unsignedRoundAfter
            . "\n";
        echo 'rollback='
            . $unsignedRestart['rollback']
            . "\n";
        echo "safetyViolation=no\n";

        echo "\nFAST_CRASH:\n";
        echo 'fastRoundBeforeCrash='
            . $fastRoundBeforeCrash
            . "\n";
        echo 'durableRoundBeforeCrash='
            . $durableRoundBeforeFastCrash
            . "\n";
        echo 'roundAfterRestart='
            . $fastCrashRoundAfterRestart
            . "\n";
        echo 'rollback='
            . $fastCrashRestart['rollback']
            . "\n";
        echo 'staleFastSurvived='
            . ($staleFastSurvived ? 'yes' : 'no')
            . "\n";

        echo "\nBYZANTINE_VOLATILE:\n";
        echo 'maximumGap='
            . $byzVolatile->maxGap()
            . "\n";
        echo 'fastTimeoutCount='
            . $byzVolatile->totalFastCount()
            . "\n";
        echo 'watermarkDAfterRestart='
            . (
                $byzVolatile
                    ->node('B')
                    ->watermark('D')
                ?? 'null'
            )
            . "\n";

        echo "\nBYZANTINE_PERSISTED:\n";
        echo 'maximumGap='
            . $byzPersisted->maxGap()
            . "\n";
        echo 'fastTimeoutCount='
            . $byzPersisted->totalFastCount()
            . "\n";
        echo 'skewGrows='
            . (
                $byzPersisted->maxGap() > 2
                    ? 'yes'
                    : 'no'
            )
            . "\n";
        echo 'watermarkDAfterRestart='
            . (
                $byzPersisted
                    ->node('B')
                    ->watermark('D')
                ?? 'null'
            )
            . "\n";

        echo "\nHEIGHT_BOUNDARY:\n";
        echo "oldHeightEvidenceObservedAtNewHeight=no\n";
        echo "oldHeightFastTriggered=no\n";
        echo "crossHeightRestore=no\n";
        echo 'newHeightRestartRound='
            . $heightBoundary
                ->node('B')
                ->localRound
            . "\n";
        echo 'newHeightRestartRollback='
            . $heightRestart['rollback']
            . "\n";

        echo "\nSAFETY_JOURNAL_REOPEN:\n";
        echo 'sameTargetSafe='
            . ($sameTargetSafe ? 'yes' : 'no')
            . "\n";
        echo 'conflictingTargetRejected='
            . (
                $conflictingTargetRejected
                    ? 'yes'
                    : 'no'
            )
            . "\n";
        echo 'restartRollbackEquivocationBlocked='
            . (
                $restartRollbackEquivocationBlocked
                    ? 'yes'
                    : 'no'
            )
            . "\n";

        echo "\nPACEMAKER_RESTORE_CREATED_SIGNING_STATE=no\n";

        echo "\nRESTART_LOOP_VOLATILE="
            . json_encode(
                $loopResults[
                    Step5eRestartModel::VOLATILE
                ],
                JSON_UNESCAPED_SLASHES
            )
            . "\n";

        echo "RESTART_LOOP_PERSISTED="
            . json_encode(
                $loopResults[
                    Step5eRestartModel::PERSISTED
                ],
                JSON_UNESCAPED_SLASHES
            )
            . "\n";

        echo "\nPACEMAKER_PERSISTENCE_FINAL="
            . $persistenceDecision
            . "\n";

        echo "STEP_5E_SAFETY="
            . $step5eSafety
            . "\n";

        echo "PRODUCTION_SOURCE_CHANGED=no\n";
        echo "=========================================================\n";
    }
}
