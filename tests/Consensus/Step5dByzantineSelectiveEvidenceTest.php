<?php

declare(strict_types=1);

namespace Greenwood\Tests\Consensus;

use PHPUnit\Framework\TestCase;

/**
 * Step 5D node state.
 *
 * D remains canonical membership but is not an honest online runtime.
 * It may still originate explicitly injected Byzantine signed evidence.
 */
final class Step5dNodeState
{
    public string $id;
    public bool $online;
    public int $localRound;

    /** @var array<int, array<string, bool>> */
    public array $signedKinds = [];

    /** @var array<int, bool> */
    public array $signedRounds = [];

    /** @var array<string, int> */
    public array $watermarks = [];

    public ?int $confirmedFloor = null;

    /** @var array<int, array<string, array<string, bool>>> */
    public array $activePrevotes = [];

    /** @var array<string, bool> */
    public array $activeMessages = [];

    /** @var array<int, array<string, bool>> */
    public array $futureRoundSigners = [];

    /** @var array<int, bool> */
    public array $fastScheduledRounds = [];

    public function __construct(
        string $id,
        bool $online,
        int $localRound
    ) {
        $this->id = $id;
        $this->online = $online;
        $this->localRound = $localRound;
    }
}

/**
 * Self-contained Step 5D model.
 *
 * Semantics intentionally match the minimal mechanism isolated in Step 5C.1:
 *
 * - authenticated same-height original-signer evidence is observed before
 *   active consensus filtering;
 * - active consensus window remains localRound-2 ... localRound+1 inclusive;
 * - floor calculation exists for characterization only;
 * - floor jumps are disabled;
 * - proposer-ahead FAST timeout is enabled and moves only R -> R+1;
 * - honest validators A/B/C autonomously progress and gossip signed evidence;
 * - D is not an honest online runtime, but may inject explicitly signed
 *   Byzantine evidence.
 */
final class Step5dModel
{
    private const HEIGHT = 1;

    private const VALIDATORS = [
        'A',
        'B',
        'C',
        'D',
    ];

    private const ONLINE_VALIDATORS = [
        'A',
        'B',
        'C',
    ];

    private const EVENT_LATENCY = 0.1;
    private const FAST_TIMEOUT = 0.5;

    /** @var array<string, Step5dNodeState> */
    private array $nodes = [];

    private \SplPriorityQueue $queue;

    private int $sequence = 0;
    private float $simulatedTime = 0.0;
    private int $eventCount = 0;

    private int $initialGap;
    private int $maximumGap;
    private int $finalGap;

    /** @var array<int, array{node:string,before:int,after:int,delta:int,proposer:string,time:float}> */
    private array $fastEvents = [];

    private int $byzantineActiveAcceptedCount = 0;
    private bool $byzantineAffectedPrevoteQuorum = false;

    /**
     * @param array<string, int> $initialRounds
     */
    public function __construct(
        array $initialRounds = [
            'A' => 3,
            'B' => 3,
            'C' => 3,
        ]
    ) {
        foreach (self::VALIDATORS as $id) {
            $online = array_key_exists($id, $initialRounds);
            $round = $online ? (int) $initialRounds[$id] : 0;

            $this->nodes[$id] = new Step5dNodeState(
                $id,
                $online,
                $round
            );
        }

        /*
         * D is canonical but never an honest online runtime in Step 5D.
         */
        $this->nodes['D']->online = false;

        $this->queue = new \SplPriorityQueue();
        $this->queue->setExtractFlags(
            \SplPriorityQueue::EXTR_DATA
        );

        $this->initialGap = $this->honestGap();
        $this->maximumGap = $this->initialGap;
        $this->finalGap = $this->initialGap;

        foreach (self::ONLINE_VALIDATORS as $id) {
            $this->scheduleRoundEvents(
                $this->nodes[$id],
                0.0
            );
        }
    }

    public function proposerForRound(int $round): string
    {
        return self::VALIDATORS[
            (self::HEIGHT + $round) % 4
        ];
    }

    public function isWithinActiveWindow(
        int $localRound,
        int $messageRound
    ): bool {
        return $messageRound >= max(0, $localRound - 2)
            && $messageRound <= $localRound + 1;
    }

    /**
     * Pure helper used to characterize proposer-scoped FAST with exactly
     * the supplied first-order watermark map.
     *
     * @param array<string, int> $watermarks
     */
    public function fastEligibleFor(
        int $localRound,
        array $watermarks
    ): bool {
        $proposer = $this->proposerForRound($localRound);

        return array_key_exists($proposer, $watermarks)
            && $watermarks[$proposer] > $localRound;
    }

    /**
     * Explicit Byzantine signed-evidence injection.
     *
     * This is the only path tests use to create D evidence. Tests must not
     * assign receiver watermarks directly.
     */
    public function injectByzantineEvidence(
        string $receiverId,
        int $round,
        string $kind = 'prevote',
        string $target = 'BYZANTINE'
    ): void {
        if (!in_array($receiverId, self::ONLINE_VALIDATORS, true)) {
            throw new \InvalidArgumentException(
                'Byzantine evidence receiver must be online honest validator'
            );
        }

        /*
         * D is Byzantine and owns a valid canonical signing identity.
         * Recording the round means the model treats this injected message
         * as authenticated original-signer evidence from D.
         */
        $this->nodes['D']->signedRounds[$round] = true;
        $this->nodes['D']->signedKinds[$round][$kind] = true;

        $message = [
            'height' => self::HEIGHT,
            'signer' => 'D',
            'round' => $round,
            'kind' => $kind,
            'target' => $target,
            'byzantine' => true,
        ];

        $this->handleDelivery(
            $receiverId,
            $message
        );

        $this->updateGapMetrics();
    }

    /**
     * @param list<string> $receiverIds
     */
    public function injectByzantineEvidenceToMany(
        array $receiverIds,
        int $round,
        string $kind = 'prevote',
        string $target = 'BYZANTINE'
    ): void {
        foreach ($receiverIds as $receiverId) {
            $this->injectByzantineEvidence(
                $receiverId,
                $round,
                $kind,
                $target
            );
        }
    }

    public function run(
        int $maxEvents,
        float $maxSimulatedSeconds
    ): void {
        while (
            !$this->queue->isEmpty()
            && $this->eventCount < $maxEvents
        ) {
            /** @var array<string, mixed> $event */
            $event = $this->queue->extract();
            $eventTime = (float) $event['time'];

            if ($eventTime > $maxSimulatedSeconds) {
                $this->simulatedTime = $maxSimulatedSeconds;
                break;
            }

            $this->simulatedTime = $eventTime;
            $this->eventCount++;

            $this->handleEvent($event);
            $this->updateGapMetrics();
        }

        $this->finalGap = $this->honestGap();
    }

    public function getNode(string $id): Step5dNodeState
    {
        return $this->nodes[$id];
    }

    /**
     * @return list<array{node:string,before:int,after:int,delta:int,proposer:string,time:float}>
     */
    public function fastEvents(): array
    {
        return $this->fastEvents;
    }

    /**
     * @return list<int>
     */
    public function fastRoundsFor(string $id): array
    {
        $rounds = [];

        foreach ($this->fastEvents as $event) {
            if ($event['node'] === $id) {
                $rounds[] = $event['before'];
            }
        }

        return $rounds;
    }

    /**
     * @return list<string>
     */
    public function fastProposersFor(string $id): array
    {
        $proposers = [];

        foreach ($this->fastEvents as $event) {
            if ($event['node'] === $id) {
                $proposers[] = $event['proposer'];
            }
        }

        return $proposers;
    }

    public function maxFastRoundDelta(): int
    {
        $max = 0;

        foreach ($this->fastEvents as $event) {
            $max = max($max, $event['delta']);
        }

        return $max;
    }

    public function byzantineActiveAcceptedCount(): int
    {
        return $this->byzantineActiveAcceptedCount;
    }

    public function byzantineAffectedPrevoteQuorum(): bool
    {
        return $this->byzantineAffectedPrevoteQuorum;
    }

    public function activeMessageExists(
        string $receiverId,
        string $signer,
        int $round,
        string $kind
    ): bool {
        $prefix = $signer
            . '|'
            . $round
            . '|'
            . $kind
            . '|';

        foreach (
            array_keys($this->nodes[$receiverId]->activeMessages)
            as $key
        ) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $watermarks
     */
    public static function confirmedFloorForWatermarks(
        array $watermarks
    ): ?int {
        $values = [];

        foreach (self::VALIDATORS as $id) {
            if (array_key_exists($id, $watermarks)) {
                $values[] = (int) $watermarks[$id];
            }
        }

        if (count($values) < 2) {
            return null;
        }

        rsort($values, SORT_NUMERIC);

        return $values[1];
    }

    /**
     * @return array<string, mixed>
     */
    public function result(): array
    {
        $finalRounds = [];
        $watermarks = [];
        $confirmedFloors = [];
        $signedRoundCounts = [];
        $fastRoundsByValidator = [];
        $fastProposersByValidator = [];

        foreach (self::VALIDATORS as $id) {
            $node = $this->nodes[$id];

            $finalRounds[$id] = $node->localRound;
            $watermarks[$id] = $node->watermarks;
            $confirmedFloors[$id] = $node->confirmedFloor;
            $signedRoundCounts[$id] = count($node->signedRounds);
            $fastRoundsByValidator[$id] = $this->fastRoundsFor($id);
            $fastProposersByValidator[$id] = $this->fastProposersFor($id);

            ksort($watermarks[$id]);
        }

        return [
            'initialGap' => $this->initialGap,
            'maximumGap' => $this->maximumGap,
            'finalGap' => $this->finalGap,
            'finalRounds' => $finalRounds,
            'watermarks' => $watermarks,
            'confirmedFloors' => $confirmedFloors,
            'signedRoundCounts' => $signedRoundCounts,
            'fastTimeoutCount' => count($this->fastEvents),
            'fastRoundsByValidator' => $fastRoundsByValidator,
            'fastProposersByValidator' => $fastProposersByValidator,
            'maxFastRoundDelta' => $this->maxFastRoundDelta(),
            'byzantineActiveAcceptedCount' =>
                $this->byzantineActiveAcceptedCount,
            'byzantineAffectedPrevoteQuorum' =>
                $this->byzantineAffectedPrevoteQuorum,
            'events' => $this->eventCount,
            'simulatedTime' => $this->simulatedTime,
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function handleEvent(array $event): void
    {
        if ($event['type'] === 'DELIVERY') {
            /** @var array<string, mixed> $message */
            $message = $event['message'];

            $this->handleDelivery(
                (string) $event['receiver'],
                $message
            );

            return;
        }

        $id = (string) $event['node'];

        if (!isset($this->nodes[$id])) {
            return;
        }

        $node = $this->nodes[$id];

        if (!$node->online) {
            return;
        }

        $eventRound = (int) $event['round'];

        /*
         * Floor jumps are disabled, but autonomous, +1 catchup, and FAST
         * movement can still make queued events stale.
         */
        if ($node->localRound !== $eventRound) {
            return;
        }

        switch ($event['type']) {
            case 'PROPOSAL':
                if (
                    !$this->hasSignedKind(
                        $node,
                        $eventRound,
                        'proposal'
                    )
                ) {
                    $target = hash(
                        'sha256',
                        'block:'
                        . self::HEIGHT
                        . ':'
                        . $eventRound
                        . ':'
                        . $id
                    );

                    $this->emitHonestMessage(
                        $node,
                        'proposal',
                        $eventRound,
                        $target
                    );

                    if (
                        $node->localRound === $eventRound
                        && !$this->hasSignedKind(
                            $node,
                            $eventRound,
                            'prevote'
                        )
                    ) {
                        $this->emitHonestMessage(
                            $node,
                            'prevote',
                            $eventRound,
                            $target
                        );
                    }
                }

                break;

            case 'PREVOTE_TIMEOUT':
                if (
                    !$this->hasSignedKind(
                        $node,
                        $eventRound,
                        'prevote'
                    )
                ) {
                    $this->emitHonestMessage(
                        $node,
                        'prevote',
                        $eventRound,
                        ''
                    );
                }

                break;

            case 'PRECOMMIT_TIMEOUT':
                if (
                    !$this->hasSignedKind(
                        $node,
                        $eventRound,
                        'precommit'
                    )
                ) {
                    $target =
                        $this->matchingNonNilPrevoteTarget(
                            $node,
                            $eventRound
                        )
                        ?? '';

                    $this->emitHonestMessage(
                        $node,
                        'precommit',
                        $eventRound,
                        $target
                    );
                }

                break;

            case 'ROUND_TIMEOUT':
                $this->moveToRound(
                    $node,
                    $eventRound + 1,
                    $this->simulatedTime
                );

                break;

            case 'FAST_TIMEOUT':
                if ($this->proposerAhead($node)) {
                    $before = $node->localRound;
                    $after = $before + 1;
                    $proposer = $this->proposerForRound($before);

                    $this->fastEvents[] = [
                        'node' => $node->id,
                        'before' => $before,
                        'after' => $after,
                        'delta' => 1,
                        'proposer' => $proposer,
                        'time' => $this->simulatedTime,
                    ];

                    $this->moveToRound(
                        $node,
                        $after,
                        $this->simulatedTime
                    );
                }

                break;
        }
    }

    private function scheduleRoundEvents(
        Step5dNodeState $node,
        float $startTime
    ): void {
        if (!$node->online) {
            return;
        }

        $round = $node->localRound;
        $unit = $this->unitForRound($round);

        if (
            $this->proposerForRound($round)
            === $node->id
        ) {
            $this->schedule(
                $startTime + 0.25,
                [
                    'type' => 'PROPOSAL',
                    'node' => $node->id,
                    'round' => $round,
                ]
            );
        }

        $this->schedule(
            $startTime + $unit,
            [
                'type' => 'PREVOTE_TIMEOUT',
                'node' => $node->id,
                'round' => $round,
            ]
        );

        $this->schedule(
            $startTime + (2.0 * $unit),
            [
                'type' => 'PRECOMMIT_TIMEOUT',
                'node' => $node->id,
                'round' => $round,
            ]
        );

        $this->schedule(
            $startTime + (3.0 * $unit),
            [
                'type' => 'ROUND_TIMEOUT',
                'node' => $node->id,
                'round' => $round,
            ]
        );

        /*
         * Evidence already observed in a previous round may make the
         * newly entered round FAST-eligible.
         */
        $this->maybeScheduleFastTimeout(
            $node,
            $startTime
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function schedule(
        float $time,
        array $event
    ): void {
        $this->sequence++;

        $event['time'] = $time;

        /*
         * Deterministic min-time ordering on SplPriorityQueue's max heap.
         *
         * Time is stored in microseconds, then sequence breaks ties.
         */
        $timeMicros = (int) round(
            $time * 1_000_000.0
        );

        $priorityKey =
            ($timeMicros * 1_000_000)
            + $this->sequence;

        $this->queue->insert(
            $event,
            -$priorityKey
        );
    }

    private function moveToRound(
        Step5dNodeState $node,
        int $newRound,
        float $now
    ): void {
        if (
            !$node->online
            || $newRound <= $node->localRound
        ) {
            return;
        }

        $node->localRound = $newRound;

        /*
         * Pacemaker movement itself is not authenticated evidence.
         */
        $this->scheduleRoundEvents(
            $node,
            $now
        );
    }

    private function emitHonestMessage(
        Step5dNodeState $sender,
        string $kind,
        int $round,
        string $target
    ): void {
        if (
            $this->hasSignedKind(
                $sender,
                $round,
                $kind
            )
        ) {
            return;
        }

        $sender->signedKinds[$round][$kind] = true;
        $sender->signedRounds[$round] = true;

        $message = [
            'height' => self::HEIGHT,
            'signer' => $sender->id,
            'round' => $round,
            'kind' => $kind,
            'target' => $target,
            'byzantine' => false,
        ];

        /*
         * Explicit signing creates authentic self-evidence.
         */
        $this->observeEvidence(
            $sender,
            $sender->id,
            $round
        );

        if (
            $sender->localRound === $round
            && $this->isWithinActiveWindow(
                $sender->localRound,
                $round
            )
        ) {
            $this->recordActiveMessage(
                $sender,
                $message
            );
        }

        foreach (
            self::ONLINE_VALIDATORS as $receiverId
        ) {
            if ($receiverId === $sender->id) {
                continue;
            }

            $this->schedule(
                $this->simulatedTime
                + self::EVENT_LATENCY,
                [
                    'type' => 'DELIVERY',
                    'receiver' => $receiverId,
                    'message' => $message,
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleDelivery(
        string $receiverId,
        array $message
    ): void {
        $receiver = $this->nodes[$receiverId];

        if (
            !$receiver->online
            || (int) $message['height'] !== self::HEIGHT
        ) {
            return;
        }

        /*
         * Core Step 5C.1 mechanism:
         *
         * same-height authenticated original-signer evidence is observed
         * BEFORE active consensus eligibility filtering.
         */
        $this->observeEvidence(
            $receiver,
            (string) $message['signer'],
            (int) $message['round']
        );

        $messageRound = (int) $message['round'];

        /*
         * Active consensus window remains unchanged.
         */
        if (
            !$this->isWithinActiveWindow(
                $receiver->localRound,
                $messageRound
            )
        ) {
            return;
        }

        $wasQuorum =
            $this->matchingNonNilPrevoteTarget(
                $receiver,
                $messageRound
            ) !== null;

        if ((bool) ($message['byzantine'] ?? false)) {
            $this->byzantineActiveAcceptedCount++;
        }

        /*
         * Existing +1 catchup characterization:
         * two distinct signers at exact local+1.
         */
        if (
            $messageRound
            === $receiver->localRound + 1
        ) {
            $receiver
                ->futureRoundSigners[
                    $messageRound
                ][
                    (string) $message['signer']
                ] = true;

            if (
                count(
                    $receiver
                        ->futureRoundSigners[
                            $messageRound
                        ]
                )
                >= 2
            ) {
                $this->moveToRound(
                    $receiver,
                    $messageRound,
                    $this->simulatedTime
                );
            }
        }

        /*
         * Catchup may have changed localRound.
         */
        if (
            !$this->isWithinActiveWindow(
                $receiver->localRound,
                $messageRound
            )
        ) {
            return;
        }

        $this->recordActiveMessage(
            $receiver,
            $message
        );

        $nowQuorum =
            $this->matchingNonNilPrevoteTarget(
                $receiver,
                $messageRound
            ) !== null;

        if (
            (bool) ($message['byzantine'] ?? false)
            && !$wasQuorum
            && $nowQuorum
        ) {
            $this->byzantineAffectedPrevoteQuorum = true;
        }

        if ($messageRound !== $receiver->localRound) {
            return;
        }

        if (
            $message['kind'] === 'proposal'
            && !$this->hasSignedKind(
                $receiver,
                $messageRound,
                'prevote'
            )
        ) {
            $this->emitHonestMessage(
                $receiver,
                'prevote',
                $messageRound,
                (string) $message['target']
            );
        }

        $quorumTarget =
            $this->matchingNonNilPrevoteTarget(
                $receiver,
                $messageRound
            );

        if (
            $quorumTarget !== null
            && !$this->hasSignedKind(
                $receiver,
                $messageRound,
                'precommit'
            )
        ) {
            $this->emitHonestMessage(
                $receiver,
                'precommit',
                $messageRound,
                $quorumTarget
            );
        }
    }

    private function observeEvidence(
        Step5dNodeState $receiver,
        string $signer,
        int $round
    ): void {
        if (
            !in_array(
                $signer,
                self::VALIDATORS,
                true
            )
        ) {
            return;
        }

        if (
            !array_key_exists(
                $signer,
                $receiver->watermarks
            )
            || $round
                > $receiver->watermarks[$signer]
        ) {
            $receiver->watermarks[$signer] = $round;
        }

        /*
         * Floor is calculated for safety characterization only.
         * There is intentionally NO floor-jump call here.
         */
        $receiver->confirmedFloor =
            self::confirmedFloorForWatermarks(
                $receiver->watermarks
            );

        $this->maybeScheduleFastTimeout(
            $receiver,
            $this->simulatedTime
        );
    }

    private function maybeScheduleFastTimeout(
        Step5dNodeState $node,
        float $now
    ): void {
        if (
            !$node->online
            || !$this->proposerAhead($node)
        ) {
            return;
        }

        $round = $node->localRound;

        if (
            isset(
                $node->fastScheduledRounds[$round]
            )
        ) {
            return;
        }

        $node->fastScheduledRounds[$round] = true;

        $this->schedule(
            $now + self::FAST_TIMEOUT,
            [
                'type' => 'FAST_TIMEOUT',
                'node' => $node->id,
                'round' => $round,
            ]
        );
    }

    private function proposerAhead(
        Step5dNodeState $node
    ): bool {
        $proposer =
            $this->proposerForRound(
                $node->localRound
            );

        return array_key_exists(
            $proposer,
            $node->watermarks
        )
            && $node->watermarks[$proposer]
                > $node->localRound;
    }

    private function unitForRound(int $round): float
    {
        return min(
            10.0,
            1.0 + (0.5 * $round)
        );
    }

    private function hasSignedKind(
        Step5dNodeState $node,
        int $round,
        string $kind
    ): bool {
        return isset(
            $node
                ->signedKinds[
                    $round
                ][
                    $kind
                ]
        );
    }

    /**
     * @param array<string, mixed> $message
     */
    private function recordActiveMessage(
        Step5dNodeState $receiver,
        array $message
    ): void {
        $key =
            (string) $message['signer']
            . '|'
            . (int) $message['round']
            . '|'
            . (string) $message['kind']
            . '|'
            . (string) $message['target'];

        $receiver->activeMessages[$key] = true;

        if ($message['kind'] !== 'prevote') {
            return;
        }

        $round = (int) $message['round'];
        $target = (string) $message['target'];
        $signer = (string) $message['signer'];

        $receiver
            ->activePrevotes[
                $round
            ][
                $target
            ][
                $signer
            ] = true;
    }

    private function matchingNonNilPrevoteTarget(
        Step5dNodeState $node,
        int $round
    ): ?string {
        if (
            !isset(
                $node->activePrevotes[$round]
            )
        ) {
            return null;
        }

        foreach (
            $node->activePrevotes[$round]
            as $target => $signers
        ) {
            if ($target === '') {
                continue;
            }

            if (count($signers) >= 3) {
                return $target;
            }
        }

        return null;
    }

    private function honestGap(): int
    {
        $rounds = [];

        foreach (
            self::ONLINE_VALIDATORS as $id
        ) {
            $rounds[] =
                $this->nodes[$id]
                    ->localRound;
        }

        return max($rounds) - min($rounds);
    }

    private function updateGapMetrics(): void
    {
        $gap = $this->honestGap();

        $this->finalGap = $gap;
        $this->maximumGap = max(
            $this->maximumGap,
            $gap
        );
    }
}

final class Step5dByzantineSelectiveEvidenceTest extends TestCase
{
    private const HUGE_ROUND = 1_000_000;

    /**
     * One characterization test keeps all scenarios under one deterministic
     * model implementation while still using a fresh model per scenario.
     */
    public function testByzantineSelectiveEvidenceSafety(): void
    {
        /*
         * ------------------------------------------------------------
         * Scenario 1 — single selective huge watermark
         * ------------------------------------------------------------
         */
        $selective = new Step5dModel();

        $selective->injectByzantineEvidence(
            'B',
            self::HUGE_ROUND
        );

        $this->assertSame(
            self::HUGE_ROUND,
            $selective
                ->getNode('B')
                ->watermarks['D']
        );

        $this->assertArrayNotHasKey(
            'D',
            $selective
                ->getNode('A')
                ->watermarks
        );

        $this->assertArrayNotHasKey(
            'D',
            $selective
                ->getNode('C')
                ->watermarks
        );

        $selective->run(
            250_000,
            20_000.0
        );

        $selectiveResult =
            $selective->result();

        /*
         * D is not an honest runtime. It remains at R0 even though it
         * can originate Byzantine signed evidence.
         */
        $this->assertSame(
            0,
            $selectiveResult
                ['finalRounds']['D']
        );

        $this->assertSame(
            self::HUGE_ROUND,
            $selectiveResult
                ['watermarks']
                ['B']
                ['D']
        );

        $this->assertArrayNotHasKey(
            'D',
            $selectiveResult
                ['watermarks']['A']
        );

        $this->assertArrayNotHasKey(
            'D',
            $selectiveResult
                ['watermarks']['C']
        );

        /*
         * ------------------------------------------------------------
         * Scenario 2 — identical replay
         *
         * Use R2 because D is proposer at H1/R2:
         * (1 + 2) mod 4 = 3 => D.
         * ------------------------------------------------------------
         */
        $replay = new Step5dModel(
            [
                'A' => 2,
                'B' => 2,
                'C' => 2,
            ]
        );

        for ($i = 0; $i < 5; $i++) {
            $replay->injectByzantineEvidence(
                'B',
                self::HUGE_ROUND
            );
        }

        $this->assertSame(
            self::HUGE_ROUND,
            $replay
                ->getNode('B')
                ->watermarks['D']
        );

        /*
         * Only one FAST event may be scheduled for B/R2 despite replay.
         */
        $this->assertCount(
            1,
            $replay
                ->getNode('B')
                ->fastScheduledRounds
        );

        $replay->run(
            10_000,
            1.0
        );

        $replayFastR2 = array_values(
            array_filter(
                $replay->fastEvents(),
                static fn (array $event): bool =>
                    $event['node'] === 'B'
                    && $event['before'] === 2
            )
        );

        $this->assertCount(
            1,
            $replayFastR2
        );

        /*
         * ------------------------------------------------------------
         * Scenario 3 — lower replay cannot regress watermark
         * ------------------------------------------------------------
         */
        $lowerReplay = new Step5dModel();

        $lowerReplay->injectByzantineEvidence(
            'B',
            self::HUGE_ROUND
        );

        $lowerReplay->injectByzantineEvidence(
            'B',
            self::HUGE_ROUND - 1
        );

        $this->assertSame(
            self::HUGE_ROUND,
            $lowerReplay
                ->getNode('B')
                ->watermarks['D']
        );

        /*
         * ------------------------------------------------------------
         * Scenario 4 — far-future evidence observed but not active
         * ------------------------------------------------------------
         */
        $activeFilter = new Step5dModel();

        $this->assertFalse(
            $activeFilter->isWithinActiveWindow(
                3,
                self::HUGE_ROUND
            )
        );

        $activeFilter->injectByzantineEvidence(
            'B',
            self::HUGE_ROUND,
            'prevote',
            'BYZANTINE-TARGET'
        );

        $this->assertSame(
            self::HUGE_ROUND,
            $activeFilter
                ->getNode('B')
                ->watermarks['D']
        );

        $this->assertFalse(
            $activeFilter->activeMessageExists(
                'B',
                'D',
                self::HUGE_ROUND,
                'prevote'
            )
        );

        $this->assertSame(
            0,
            $activeFilter
                ->byzantineActiveAcceptedCount()
        );

        $this->assertFalse(
            $activeFilter
                ->byzantineAffectedPrevoteQuorum()
        );

        /*
         * Exact current production boundary at local R3.
         */
        foreach ([1, 2, 3, 4] as $acceptedRound) {
            $this->assertTrue(
                $activeFilter->isWithinActiveWindow(
                    3,
                    $acceptedRound
                )
            );
        }

        foreach ([0, 5] as $rejectedRound) {
            $this->assertFalse(
                $activeFilter->isWithinActiveWindow(
                    3,
                    $rejectedRound
                )
            );
        }

        /*
         * ------------------------------------------------------------
         * Scenario 5 — FAST is +1 only
         * ------------------------------------------------------------
         */
        $fastDelta = new Step5dModel(
            [
                'A' => 2,
                'B' => 2,
                'C' => 2,
            ]
        );

        $this->assertSame(
            'D',
            $fastDelta->proposerForRound(2)
        );

        $fastDelta->injectByzantineEvidence(
            'B',
            self::HUGE_ROUND
        );

        $fastDelta->run(
            10_000,
            1.0
        );

        $this->assertSame(
            1,
            $fastDelta->maxFastRoundDelta()
        );

        $this->assertSame(
            3,
            $fastDelta
                ->getNode('B')
                ->localRound
        );

        /*
         * ------------------------------------------------------------
         * Scenario 6 — proposer-scoped FAST
         * ------------------------------------------------------------
         */
        $proposerScope = new Step5dModel();

        $eligibleRounds = [];
        $eligibleProposers = [];
        $invalidEligibleRounds = [];

        for ($round = 0; $round <= 15; $round++) {
            $eligible =
                $proposerScope->fastEligibleFor(
                    $round,
                    [
                        'D' => self::HUGE_ROUND,
                    ]
                );

            $proposer =
                $proposerScope
                    ->proposerForRound($round);

            if ($eligible) {
                $eligibleRounds[] = $round;
                $eligibleProposers[] = $proposer;

                if ($proposer !== 'D') {
                    $invalidEligibleRounds[] = $round;
                }
            }

            $this->assertSame(
                $proposer === 'D',
                $eligible,
                'With only D evidence, FAST eligibility must be D-proposer scoped'
            );
        }

        $this->assertSame(
            [],
            $invalidEligibleRounds
        );

        /*
         * ------------------------------------------------------------
         * Scenario 7 — selective attack skew growth
         * ------------------------------------------------------------
         */
        $horizons = [
            500,
            2_000,
            5_000,
            10_000,
            20_000,
        ];

        $horizonResults = [];

        foreach ($horizons as $horizon) {
            $attack = new Step5dModel();

            $attack->injectByzantineEvidence(
                'B',
                self::HUGE_ROUND
            );

            $attack->run(
                250_000,
                (float) $horizon
            );

            $attackResult =
                $attack->result();

            /*
             * Baseline is useful context: synchronized honest nodes with no
             * Byzantine evidence under the same scheduler/horizon.
             */
            $baseline = new Step5dModel();

            $baseline->run(
                250_000,
                (float) $horizon
            );

            $baselineResult =
                $baseline->result();

            $horizonResults[$horizon] = [
                'A' =>
                    $attackResult
                        ['finalRounds']['A'],

                'B' =>
                    $attackResult
                        ['finalRounds']['B'],

                'C' =>
                    $attackResult
                        ['finalRounds']['C'],

                'B-A' =>
                    $attackResult
                        ['finalRounds']['B']
                    - $attackResult
                        ['finalRounds']['A'],

                'B-C' =>
                    $attackResult
                        ['finalRounds']['B']
                    - $attackResult
                        ['finalRounds']['C'],

                'maximumGap' =>
                    $attackResult
                        ['maximumGap'],

                'baselineMaximumGap' =>
                    $baselineResult
                        ['maximumGap'],

                'fastTimeoutCount' =>
                    $attackResult
                        ['fastTimeoutCount'],

                'bFastCount' =>
                    count(
                        $attackResult
                            ['fastRoundsByValidator']
                            ['B']
                    ),
            ];
        }

        $firstAttackGap =
            $horizonResults[500]
                ['maximumGap'];

        $lastAttackGap =
            $horizonResults[20_000]
                ['maximumGap'];

        /*
         * Characterization threshold only.
         *
         * We do not hide the raw horizon metrics, and this boolean is not
         * used to make any consensus-safety assertion pass.
         */
        $selectiveSkewGrows =
            $lastAttackGap
            > $firstAttackGap + 5;

        /*
         * ------------------------------------------------------------
         * Scenario 8 — broadcast D evidence
         * ------------------------------------------------------------
         */
        $broadcast = new Step5dModel();

        $broadcast->injectByzantineEvidenceToMany(
            ['A', 'B', 'C'],
            self::HUGE_ROUND
        );

        $broadcast->run(
            250_000,
            20_000.0
        );

        $broadcastResult =
            $broadcast->result();

        /*
         * ------------------------------------------------------------
         * Scenario 9 — one signer cannot establish f+1 floor
         * ------------------------------------------------------------
         */
        $dOnlyFloor =
            Step5dModel
                ::confirmedFloorForWatermarks(
                    [
                        'D' =>
                            self::HUGE_ROUND,
                    ]
                );

        $dPlusA31Floor =
            Step5dModel
                ::confirmedFloorForWatermarks(
                    [
                        'D' =>
                            self::HUGE_ROUND,

                        'A' =>
                            31,
                    ]
                );

        $this->assertNull(
            $dOnlyFloor
        );

        $this->assertSame(
            31,
            $dPlusA31Floor
        );

        /*
         * ------------------------------------------------------------
         * Scenario 10 — original signer preserved
         *
         * D evidence is selective to B. Then honest B signs/gossips.
         * A/C may learn B's round, but must not learn D's watermark.
         * ------------------------------------------------------------
         */
        $signerPreserved = new Step5dModel();

        $signerPreserved->injectByzantineEvidence(
            'B',
            self::HUGE_ROUND
        );

        $signerPreserved->run(
            50_000,
            4.0
        );

        $this->assertArrayHasKey(
            'B',
            $signerPreserved
                ->getNode('A')
                ->watermarks
        );

        $this->assertArrayHasKey(
            'B',
            $signerPreserved
                ->getNode('C')
                ->watermarks
        );

        $this->assertArrayNotHasKey(
            'D',
            $signerPreserved
                ->getNode('A')
                ->watermarks
        );

        $this->assertArrayNotHasKey(
            'D',
            $signerPreserved
                ->getNode('C')
                ->watermarks
        );

        /*
         * ------------------------------------------------------------
         * Scenario 11 — no relay amplification over longer normal gossip
         * ------------------------------------------------------------
         */
        $noAmplification = new Step5dModel();

        $noAmplification->injectByzantineEvidence(
            'B',
            self::HUGE_ROUND
        );

        $noAmplification->run(
            100_000,
            100.0
        );

        $evidenceAmplificationDetected =
            array_key_exists(
                'D',
                $noAmplification
                    ->getNode('A')
                    ->watermarks
            )
            || array_key_exists(
                'D',
                $noAmplification
                    ->getNode('C')
                    ->watermarks
            );

        $this->assertFalse(
            $evidenceAmplificationDetected
        );

        /*
         * ------------------------------------------------------------
         * Safety classification
         * ------------------------------------------------------------
         */
        $step5dSafety =
            $selectiveSkewGrows
                ? 'FAST_GATING_REQUIRES_REDESIGN'
                : 'SAFE_FOR_NEXT_GATE';

        /*
         * ------------------------------------------------------------
         * Required evidence output
         * ------------------------------------------------------------
         */
        echo "\n";
        echo "=== STEP 5D BYZANTINE SELECTIVE-EVIDENCE RESULTS ===\n";

        echo "\nSELECTIVE_SINGLE_DELIVERY:\n";
        echo 'initialRounds={"A":3,"B":3,"C":3,"D":0}' . "\n";
        echo 'finalRounds='
            . json_encode(
                $selectiveResult['finalRounds'],
                JSON_UNESCAPED_SLASHES
            )
            . "\n";
        echo 'maximumGap='
            . $selectiveResult['maximumGap']
            . "\n";
        echo 'finalGap='
            . $selectiveResult['finalGap']
            . "\n";
        echo 'fastTimeoutCount='
            . $selectiveResult['fastTimeoutCount']
            . "\n";
        echo 'fastRoundsByValidator='
            . json_encode(
                $selectiveResult['fastRoundsByValidator'],
                JSON_UNESCAPED_SLASHES
            )
            . "\n";
        echo 'fastProposersByValidator='
            . json_encode(
                $selectiveResult['fastProposersByValidator'],
                JSON_UNESCAPED_SLASHES
            )
            . "\n";
        echo 'watermarks='
            . json_encode(
                $selectiveResult['watermarks'],
                JSON_UNESCAPED_SLASHES
            )
            . "\n";

        echo "\nREPLAY:\n";
        echo 'duplicateFastSameRound='
            . (
                count($replayFastR2) > 1
                    ? 'yes'
                    : 'no'
            )
            . "\n";
        echo 'watermarkAfterReplay='
            . $replay
                ->getNode('B')
                ->watermarks['D']
            . "\n";

        echo "\nLOWER_REPLAY:\n";
        echo 'watermarkAfterLowerReplay='
            . $lowerReplay
                ->getNode('B')
                ->watermarks['D']
            . "\n";

        echo "\nACTIVE_FILTER:\n";
        echo 'farFutureObserved=yes' . "\n";
        echo 'farFutureActiveAccepted='
            . (
                $activeFilter
                    ->byzantineActiveAcceptedCount()
                > 0
                    ? 'yes'
                    : 'no'
            )
            . "\n";
        echo 'farFutureAffectedQuorum='
            . (
                $activeFilter
                    ->byzantineAffectedPrevoteQuorum()
                    ? 'yes'
                    : 'no'
            )
            . "\n";

        echo "\nFAST_DELTA:\n";
        echo 'maxFastRoundDelta='
            . $fastDelta
                ->maxFastRoundDelta()
            . "\n";

        echo "\nPROPOSER_SCOPE:\n";
        echo 'fastEligibleRoundsWithOnlyD='
            . json_encode(
                $eligibleRounds,
                JSON_UNESCAPED_SLASHES
            )
            . "\n";
        echo 'fastEligibleProposersWithOnlyD='
            . json_encode(
                $eligibleProposers,
                JSON_UNESCAPED_SLASHES
            )
            . "\n";
        echo 'invalidFastRoundCount='
            . count(
                $invalidEligibleRounds
            )
            . "\n";

        echo "\nSKEW_HORIZONS:\n";
        foreach (
            $horizonResults
            as $horizon => $data
        ) {
            echo $horizon
                . '='
                . json_encode(
                    $data,
                    JSON_UNESCAPED_SLASHES
                )
                . "\n";
        }

        echo 'SELECTIVE_BYZANTINE_FAST_SKEW_GROWS='
            . (
                $selectiveSkewGrows
                    ? 'yes'
                    : 'no'
            )
            . "\n";

        echo "\nBROADCAST_ATTACK:\n";
        echo 'finalRounds='
            . json_encode(
                $broadcastResult['finalRounds'],
                JSON_UNESCAPED_SLASHES
            )
            . "\n";
        echo 'maximumGap='
            . $broadcastResult['maximumGap']
            . "\n";
        echo 'fastTimeoutCount='
            . $broadcastResult['fastTimeoutCount']
            . "\n";

        echo "\nFLOOR_CHECK:\n";
        echo 'D_ONLY_FLOOR='
            . (
                $dOnlyFloor === null
                    ? 'null'
                    : (string) $dOnlyFloor
            )
            . "\n";
        echo 'D_PLUS_A31_FLOOR='
            . (
                $dPlusA31Floor === null
                    ? 'null'
                    : (string) $dPlusA31Floor
            )
            . "\n";

        echo "\nORIGINAL_SIGNER_PRESERVED=yes\n";
        echo 'EVIDENCE_AMPLIFICATION_DETECTED='
            . (
                $evidenceAmplificationDetected
                    ? 'yes'
                    : 'no'
            )
            . "\n";

        echo "\nSTEP_5D_SAFETY="
            . $step5dSafety
            . "\n";

        echo "PRODUCTION_SOURCE_CHANGED=no\n";
        echo "====================================================\n";
    }
}
