<?php

declare(strict_types=1);

namespace Greenwood\Tests\Consensus;

use PHPUnit\Framework\TestCase;

final class Step5cNodeState
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

final class Step5cDeterministicModel
{
    public const CONTROL = 'CONTROL';
    public const OBSERVE_ONLY = 'OBSERVE_ONLY';
    public const FLOOR_ONLY = 'FLOOR_ONLY';
    public const FLOOR_PLUS_FAST = 'FLOOR_PLUS_FAST';
    public const OBSERVE_PLUS_FAST = 'OBSERVE_PLUS_FAST';

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
    private const SUSTAINED_EVENTS = 100;

    private string $variant;

    private bool $observeEvidence;
    private bool $floorJumpEnabled;
    private bool $fastTimeoutEnabled;

    /** @var array<string, Step5cNodeState> */
    private array $nodes = [];

    private \SplPriorityQueue $queue;

    private int $sequence = 0;

    private float $simulatedTime = 0.0;
    private int $eventCount = 0;

    private int $initialGap;
    private int $maximumGap;
    private int $minimumGap;
    private int $finalGap;

    private ?int $firstGapLE2Event = null;
    private ?float $firstGapLE2Time = null;

    private ?int $firstGapLE1Event = null;
    private ?float $firstGapLE1Time = null;

    private ?int $firstExactConvergenceEvent = null;
    private ?float $firstExactConvergenceTime = null;

    private ?int $firstSustainedGapLE1Event = null;
    private ?float $firstSustainedGapLE1Time = null;

    private int $consecutiveGapLE1Events = 0;

    private int $confirmedFloorAdvanceCount = 0;
    private int $actualFloorJumpCount = 0;
    private int $fastTimeoutCount = 0;

    public function __construct(string $variant)
    {
        if (
            !in_array(
                $variant,
                [
                    self::CONTROL,
                    self::OBSERVE_ONLY,
                    self::FLOOR_ONLY,
                    self::FLOOR_PLUS_FAST,
                    self::OBSERVE_PLUS_FAST,
                ],
                true
            )
        ) {
            throw new \InvalidArgumentException(
                'Unknown Step 5C variant: ' . $variant
            );
        }

        $this->variant = $variant;

        /*
         * CONTROL:
         *     No pacemaker evidence observation.
         *
         * OBSERVE_ONLY:
         *     Observe authenticated signer/round evidence before
         *     active-window filtering, but do not move the pacemaker
         *     from confirmedFloor and do not use FAST timeout.
         *
         * FLOOR_ONLY:
         *     OBSERVE_ONLY + correct f+1 confirmedFloor jump.
         *
         * FLOOR_PLUS_FAST:
         *     FLOOR_ONLY + proposer-ahead FAST timeout.
         *
         * OBSERVE_PLUS_FAST:
         *     OBSERVE_ONLY + proposer-ahead FAST timeout.
         *     confirmedFloor may still be calculated for observation,
         *     but floor-based localRound jumps are disabled.
         */
        $this->observeEvidence =
            $variant !== self::CONTROL;

        $this->floorJumpEnabled =
            in_array(
                $variant,
                [
                    self::FLOOR_ONLY,
                    self::FLOOR_PLUS_FAST,
                ],
                true
            );

        $this->fastTimeoutEnabled =
            in_array(
                $variant,
                [
                    self::FLOOR_PLUS_FAST,
                    self::OBSERVE_PLUS_FAST,
                ],
                true
            );

        /*
         * Canonical Greenwood V1 membership:
         *
         * A/B/C/D always exist.
         *
         * D is offline but remains canonical membership.
         *
         * initialRounds DO NOT create authenticated evidence.
         */
        $this->nodes = [
            'A' => new Step5cNodeState(
                'A',
                true,
                31
            ),
            'B' => new Step5cNodeState(
                'B',
                true,
                3
            ),
            'C' => new Step5cNodeState(
                'C',
                true,
                3
            ),
            'D' => new Step5cNodeState(
                'D',
                false,
                0
            ),
        ];

        $this->queue = new \SplPriorityQueue();
        $this->queue->setExtractFlags(
            \SplPriorityQueue::EXTR_DATA
        );

        $this->initialGap =
            $this->honestGap();

        $this->maximumGap =
            $this->initialGap;

        $this->minimumGap =
            $this->initialGap;

        $this->finalGap =
            $this->initialGap;

        foreach (
            self::ONLINE_VALIDATORS as $id
        ) {
            $this->scheduleRoundEvents(
                $this->nodes[$id],
                0.0
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
            && $this->firstSustainedGapLE1Event === null
        ) {
            /** @var array<string, mixed> $event */
            $event =
                $this->queue->extract();

            $eventTime =
                (float) $event['time'];

            if (
                $eventTime >
                $maxSimulatedSeconds
            ) {
                $this->simulatedTime =
                    $maxSimulatedSeconds;

                break;
            }

            $this->simulatedTime =
                $eventTime;

            $this->eventCount++;

            $this->handleEvent(
                $event
            );

            $this->updateGapMetrics();
        }

        $this->finalGap =
            $this->honestGap();
    }

    /**
     * @return array<string, mixed>
     */
    public function result(): array
    {
        $rounds = [];
        $watermarks = [];
        $floors = [];
        $signedRoundCounts = [];

        foreach (
            self::VALIDATORS as $id
        ) {
            $node =
                $this->nodes[$id];

            $rounds[$id] =
                $node->localRound;

            $watermarks[$id] =
                $node->watermarks;

            $floors[$id] =
                $node->confirmedFloor;

            $signedRoundCounts[$id] =
                count(
                    $node->signedRounds
                );

            ksort(
                $watermarks[$id]
            );
        }

        return [
            'variant' =>
                $this->variant,

            'recovers' =>
                $this->firstSustainedGapLE1Event
                !== null,

            'initialGap' =>
                $this->initialGap,

            'maximumGap' =>
                $this->maximumGap,

            'minimumGap' =>
                $this->minimumGap,

            'finalGap' =>
                $this->finalGap,

            'firstGapLE2Event' =>
                $this->firstGapLE2Event,

            'firstGapLE2Time' =>
                $this->firstGapLE2Time,

            'firstGapLE1Event' =>
                $this->firstGapLE1Event,

            'firstGapLE1Time' =>
                $this->firstGapLE1Time,

            'firstExactConvergenceEvent' =>
                $this->firstExactConvergenceEvent,

            'firstExactConvergenceTime' =>
                $this->firstExactConvergenceTime,

            'firstSustainedGapLE1Event' =>
                $this->firstSustainedGapLE1Event,

            'firstSustainedGapLE1Time' =>
                $this->firstSustainedGapLE1Time,

            'confirmedFloorAdvanceCount' =>
                $this->confirmedFloorAdvanceCount,

            'actualFloorJumpCount' =>
                $this->actualFloorJumpCount,

            'fastTimeoutCount' =>
                $this->fastTimeoutCount,

            'finalRounds' =>
                $rounds,

            'watermarks' =>
                $watermarks,

            'confirmedFloors' =>
                $floors,

            'signedRoundCounts' =>
                $signedRoundCounts,

            'events' =>
                $this->eventCount,

            'simulatedTime' =>
                $this->simulatedTime,
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function handleEvent(
        array $event
    ): void {
        $type =
            (string) $event['type'];

        if ($type === 'DELIVERY') {
            /** @var array<string, mixed> $message */
            $message =
                $event['message'];

            $this->handleDelivery(
                (string) $event['receiver'],
                $message
            );

            return;
        }

        $id =
            (string) $event['node'];

        if (
            !isset(
                $this->nodes[$id]
            )
        ) {
            return;
        }

        $node =
            $this->nodes[$id];

        if (!$node->online) {
            return;
        }

        $eventRound =
            (int) $event['round'];

        /*
         * Any event from an older local pacemaker round is stale.
         *
         * This includes events invalidated by:
         *
         * - autonomous timeout
         * - floor jump
         * - +1 catchup
         * - FAST timeout
         */
        if (
            $node->localRound
            !== $eventRound
        ) {
            return;
        }

        switch ($type) {
            case 'PROPOSAL':
                if (
                    !$this->hasSignedKind(
                        $node,
                        $eventRound,
                        'proposal'
                    )
                ) {
                    $target =
                        hash(
                            'sha256',
                            'block:'
                            . self::HEIGHT
                            . ':'
                            . $eventRound
                            . ':'
                            . $id
                        );

                    $this->emitMessage(
                        $node,
                        'proposal',
                        $eventRound,
                        $target
                    );

                    /*
                     * Real ConsensusDriver can proceed directly from
                     * its own proposal to PREVOTE in the same round.
                     */
                    if (
                        $node->localRound
                        === $eventRound
                        && !$this->hasSignedKind(
                            $node,
                            $eventRound,
                            'prevote'
                        )
                    ) {
                        $this->emitMessage(
                            $node,
                            'prevote',
                            $eventRound,
                            $target
                        );
                    }
                }

                break;

            case 'PREVOTE_TIMEOUT':
                /*
                 * No accepted proposal arrived before unit(R).
                 *
                 * Production signs PREVOTE NIL.
                 */
                if (
                    !$this->hasSignedKind(
                        $node,
                        $eventRound,
                        'prevote'
                    )
                ) {
                    $this->emitMessage(
                        $node,
                        'prevote',
                        $eventRound,
                        ''
                    );
                }

                break;

            case 'PRECOMMIT_TIMEOUT':
                /*
                 * If there are 3 matching NON-NIL prevotes,
                 * precommit that target.
                 *
                 * Otherwise production eventually signs NIL.
                 */
                if (
                    !$this->hasSignedKind(
                        $node,
                        $eventRound,
                        'precommit'
                    )
                ) {
                    $target =
                        $this
                            ->matchingNonNilPrevoteTarget(
                                $node,
                                $eventRound
                            )
                        ?? '';

                    $this->emitMessage(
                        $node,
                        'precommit',
                        $eventRound,
                        $target
                    );
                }

                break;

            case 'ROUND_TIMEOUT':
                /*
                 * Production autonomous pacemaker:
                 *
                 * after 3 * unit(R)
                 * localRound++
                 *
                 * Pacemaker movement itself creates NO signature and
                 * NO watermark.
                 */
                $this->moveToRound(
                    $node,
                    $eventRound + 1,
                    $this->simulatedTime
                );

                break;

            case 'FAST_TIMEOUT':
                /*
                 * FAST timeout is not proof and does not directly jump
                 * to an ahead proposer.
                 *
                 * It merely ends the CURRENT round earlier.
                 */
                if (
                    $this->proposerAhead(
                        $node
                    )
                ) {
                    $this->fastTimeoutCount++;

                    $this->moveToRound(
                        $node,
                        $eventRound + 1,
                        $this->simulatedTime
                    );
                }

                break;
        }
    }

    private function scheduleRoundEvents(
        Step5cNodeState $node,
        float $startTime
    ): void {
        if (!$node->online) {
            return;
        }

        $round =
            $node->localRound;

        $unit =
            $this->unitForRound(
                $round
            );

        /*
         * Proposal opportunity:
         *
         * designated proposer signs after >0.25 sec.
         */
        if (
            $this->proposerForRound(
                $round
            )
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

        /*
         * NIL PREVOTE opportunity.
         */
        $this->schedule(
            $startTime + $unit,
            [
                'type' =>
                    'PREVOTE_TIMEOUT',

                'node' =>
                    $node->id,

                'round' =>
                    $round,
            ]
        );

        /*
         * NIL PRECOMMIT opportunity.
         */
        $this->schedule(
            $startTime
            + (2.0 * $unit),
            [
                'type' =>
                    'PRECOMMIT_TIMEOUT',

                'node' =>
                    $node->id,

                'round' =>
                    $round,
            ]
        );

        /*
         * Autonomous round timeout.
         */
        $this->schedule(
            $startTime
            + (3.0 * $unit),
            [
                'type' =>
                    'ROUND_TIMEOUT',

                'node' =>
                    $node->id,

                'round' =>
                    $round,
            ]
        );

        /*
         * FAST may already be justified when entering this round.
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

        $event['time'] =
            $time;

        /*
         * SplPriorityQueue is a max-heap.
         *
         * Negating the timestamp makes earlier events higher priority.
         *
         * The sequence component makes ordering deterministic for
         * identical timestamps.
         */
        $priority =
            -((
                $time
                * 1_000_000_000.0
            )
            + $this->sequence);

        $this->queue->insert(
            $event,
            $priority
        );
    }

    private function moveToRound(
        Step5cNodeState $node,
        int $newRound,
        float $now
    ): void {
        if (!$node->online) {
            return;
        }

        if (
            $newRound
            <= $node->localRound
        ) {
            return;
        }

        $node->localRound =
            $newRound;

        /*
         * Merely moving the pacemaker does NOT create:
         *
         * signedRounds
         * self watermark
         * peer watermark
         */
        $this->scheduleRoundEvents(
            $node,
            $now
        );
    }

    private function emitMessage(
        Step5cNodeState $sender,
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

        $sender
            ->signedKinds[$round][$kind]
            = true;

        $sender
            ->signedRounds[$round]
            = true;

        $message = [
            'height' =>
                self::HEIGHT,

            'signer' =>
                $sender->id,

            'round' =>
                $round,

            'kind' =>
                $kind,

            'target' =>
                $target,
        ];

        /*
         * In the observation variants, explicit signing creates
         * authenticated local self-evidence.
         */
        if ($this->observeEvidence) {
            $this->observeEvidence(
                $sender,
                $sender->id,
                $round
            );
        }

        /*
         * Locally signed messages are also part of the sender's normal
         * active consensus state if still active for this round.
         */
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
            self::ONLINE_VALIDATORS
            as $receiver
        ) {
            if (
                $receiver
                === $sender->id
            ) {
                continue;
            }

            $this->schedule(
                $this->simulatedTime
                + self::EVENT_LATENCY,
                [
                    'type' =>
                        'DELIVERY',

                    'receiver' =>
                        $receiver,

                    'message' =>
                        $message,
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
        $receiver =
            $this->nodes[$receiverId];

        if (!$receiver->online) {
            return;
        }

        if (
            (int) $message['height']
            !== self::HEIGHT
        ) {
            return;
        }

        /*
         * STEP 5C CORE SEPARATION:
         *
         * OBSERVATION happens BEFORE active-round filtering.
         *
         * This does NOT make the message an active vote/proposal.
         */
        if ($this->observeEvidence) {
            $this->observeEvidence(
                $receiver,
                (string) $message['signer'],
                (int) $message['round']
            );
        }

        $messageRound =
            (int) $message['round'];

        /*
         * Existing production active consensus eligibility remains
         * unchanged:
         *
         * local - 2
         * through
         * local + 1
         *
         * inclusive.
         */
        if (
            !$this->isWithinActiveWindow(
                $receiver->localRound,
                $messageRound
            )
        ) {
            return;
        }

        /*
         * Existing +1 catchup characterization:
         *
         * two distinct canonical signers observed in exact local+1.
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
         * A floor/catchup movement may have changed localRound above.
         *
         * Recheck active eligibility before using the message as active
         * consensus state.
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

        /*
         * Only a current-round proposal can trigger an immediate
         * PREVOTE.
         */
        if (
            $messageRound
            !== $receiver->localRound
        ) {
            return;
        }

        if (
            $message['kind']
            === 'proposal'
            && !$this->hasSignedKind(
                $receiver,
                $messageRound,
                'prevote'
            )
        ) {
            $this->emitMessage(
                $receiver,
                'prevote',
                $messageRound,
                (string) $message['target']
            );
        }

        /*
         * If this receiver now has 3 matching NON-NIL prevotes,
         * production can precommit that target.
         */
        $quorumTarget =
            $this
                ->matchingNonNilPrevoteTarget(
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
            $this->emitMessage(
                $receiver,
                'precommit',
                $messageRound,
                $quorumTarget
            );
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function recordActiveMessage(
        Step5cNodeState $receiver,
        array $message
    ): void {
        if (
            $message['kind']
            !== 'prevote'
        ) {
            return;
        }

        $round =
            (int) $message['round'];

        $target =
            (string) $message['target'];

        $signer =
            (string) $message['signer'];

        $receiver
            ->activePrevotes[
                $round
            ][
                $target
            ][
                $signer
            ] = true;
    }

    private function observeEvidence(
        Step5cNodeState $receiver,
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

        /*
         * First-order signer watermark only.
         *
         * No evidence amplification.
         */
        if (
            !array_key_exists(
                $signer,
                $receiver->watermarks
            )
            || $round
                > $receiver
                    ->watermarks[$signer]
        ) {
            $receiver
                ->watermarks[$signer]
                = $round;
        }

        $oldFloor =
            $receiver->confirmedFloor;

        $newFloor =
            $this->confirmedFloorFor(
                $receiver
            );

        $receiver->confirmedFloor =
            $newFloor;

        if (
            $newFloor !== null
            && (
                $oldFloor === null
                || $newFloor
                    > $oldFloor
            )
        ) {
            $this
                ->confirmedFloorAdvanceCount++;
        }

        /*
         * Floor movement is enabled only in FLOOR_ONLY and
         * FLOOR_PLUS_FAST.
         */
        if (
            $this->floorJumpEnabled
            && $newFloor !== null
            && $newFloor
                > $receiver->localRound
        ) {
            $this
                ->actualFloorJumpCount++;

            $this->moveToRound(
                $receiver,
                $newFloor,
                $this->simulatedTime
            );
        }

        /*
         * New proposer evidence may make FAST applicable to the
         * receiver's CURRENT round.
         */
        $this->maybeScheduleFastTimeout(
            $receiver,
            $this->simulatedTime
        );
    }

    private function confirmedFloorFor(
        Step5cNodeState $node
    ): ?int {
        $values = [];

        foreach (
            self::VALIDATORS as $id
        ) {
            /*
             * Missing key means ABSENT.
             *
             * It is not R0.
             */
            if (
                array_key_exists(
                    $id,
                    $node->watermarks
                )
            ) {
                $values[] =
                    $node->watermarks[$id];
            }
        }

        /*
         * Greenwood V1:
         *
         * n = 4
         * f = 1
         * f+1 = 2
         */
        if (
            count($values) < 2
        ) {
            return null;
        }

        rsort(
            $values,
            SORT_NUMERIC
        );

        /*
         * f+1 floor:
         *
         * second-highest distinct-signer observation.
         */
        return $values[1];
    }

    private function maybeScheduleFastTimeout(
        Step5cNodeState $node,
        float $now
    ): void {
        if (
            !$this->fastTimeoutEnabled
            || !$node->online
            || !$this->proposerAhead(
                $node
            )
        ) {
            return;
        }

        $round =
            $node->localRound;

        if (
            isset(
                $node
                    ->fastScheduledRounds[
                        $round
                    ]
            )
        ) {
            return;
        }

        $node
            ->fastScheduledRounds[
                $round
            ] = true;

        $this->schedule(
            $now
            + self::FAST_TIMEOUT,
            [
                'type' =>
                    'FAST_TIMEOUT',

                'node' =>
                    $node->id,

                'round' =>
                    $round,
            ]
        );
    }

    private function proposerAhead(
        Step5cNodeState $node
    ): bool {
        $proposer =
            $this->proposerForRound(
                $node->localRound
            );

        if (
            !array_key_exists(
                $proposer,
                $node->watermarks
            )
        ) {
            return false;
        }

        return
            $node
                ->watermarks[
                    $proposer
                ]
            > $node->localRound;
    }

    private function proposerForRound(
        int $round
    ): string {
        return self::VALIDATORS[
            (
                self::HEIGHT
                + $round
            )
            % 4
        ];
    }

    private function unitForRound(
        int $round
    ): float {
        return min(
            10.0,
            1.0
            + (0.5 * $round)
        );
    }

    private function isWithinActiveWindow(
        int $localRound,
        int $messageRound
    ): bool {
        return
            $messageRound
            >= max(
                0,
                $localRound - 2
            )
            && $messageRound
                <= $localRound + 1;
    }

    private function hasSignedKind(
        Step5cNodeState $node,
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

    private function matchingNonNilPrevoteTarget(
        Step5cNodeState $node,
        int $round
    ): ?string {
        if (
            !isset(
                $node
                    ->activePrevotes[
                        $round
                    ]
            )
        ) {
            return null;
        }

        foreach (
            $node
                ->activePrevotes[
                    $round
                ]
            as $target => $signers
        ) {
            if ($target === '') {
                continue;
            }

            /*
             * Actual Greenwood V1 non-NIL precommit threshold:
             *
             * 3 matching prevotes.
             *
             * NOT f+1.
             */
            if (
                count($signers) >= 3
            ) {
                return $target;
            }
        }

        return null;
    }

    private function honestGap(): int
    {
        $rounds = [];

        foreach (
            self::ONLINE_VALIDATORS
            as $id
        ) {
            $rounds[] =
                $this->nodes[$id]
                    ->localRound;
        }

        return
            max($rounds)
            - min($rounds);
    }

    private function updateGapMetrics(): void
    {
        $gap =
            $this->honestGap();

        $this->finalGap =
            $gap;

        $this->maximumGap =
            max(
                $this->maximumGap,
                $gap
            );

        $this->minimumGap =
            min(
                $this->minimumGap,
                $gap
            );

        if (
            $gap <= 2
            && $this->firstGapLE2Event
                === null
        ) {
            $this->firstGapLE2Event =
                $this->eventCount;

            $this->firstGapLE2Time =
                $this->simulatedTime;
        }

        if (
            $gap <= 1
            && $this->firstGapLE1Event
                === null
        ) {
            $this->firstGapLE1Event =
                $this->eventCount;

            $this->firstGapLE1Time =
                $this->simulatedTime;
        }

        if (
            $gap === 0
            && $this
                ->firstExactConvergenceEvent
                === null
        ) {
            $this
                ->firstExactConvergenceEvent =
                $this->eventCount;

            $this
                ->firstExactConvergenceTime =
                $this->simulatedTime;
        }

        if ($gap <= 1) {
            $this
                ->consecutiveGapLE1Events++;

            if (
                $this
                    ->consecutiveGapLE1Events
                >= self::SUSTAINED_EVENTS
                && $this
                    ->firstSustainedGapLE1Event
                    === null
            ) {
                $this
                    ->firstSustainedGapLE1Event =
                    $this->eventCount;

                $this
                    ->firstSustainedGapLE1Time =
                    $this->simulatedTime;
            }
        } else {
            $this
                ->consecutiveGapLE1Events =
                0;
        }
    }
}

final class Step5cDeterministicLivenessTest
    extends TestCase
{
    private const MAX_EVENTS = 250000;

    private const MAX_SIMULATED_SECONDS =
        20000.0;

    public function testFiveVariantsCharacterizeLiveness(): void
    {
        $variants = [
            Step5cDeterministicModel::CONTROL,
            Step5cDeterministicModel::OBSERVE_ONLY,
            Step5cDeterministicModel::FLOOR_ONLY,
            Step5cDeterministicModel::OBSERVE_PLUS_FAST,
            Step5cDeterministicModel::FLOOR_PLUS_FAST,
        ];

        $results = [];

        foreach (
            $variants as $variant
        ) {
            $model =
                new Step5cDeterministicModel(
                    $variant
                );

            $model->run(
                self::MAX_EVENTS,
                self::MAX_SIMULATED_SECONDS
            );

            $results[$variant] =
                $model->result();

            $this->assertSame(
                28,
                $results[$variant]['initialGap'],
                $variant
                . ': initial gap'
            );

            /*
             * Offline D remains canonical but inert.
             */
            $this->assertSame(
                0,
                $results[$variant]
                    ['finalRounds']['D'],
                $variant
                . ': offline D must remain R0'
            );

            $this->assertSame(
                [],
                $results[$variant]
                    ['watermarks']['D'],
                $variant
                . ': offline D must have no evidence'
            );

            $this->assertSame(
                0,
                $results[$variant]
                    ['signedRoundCounts']['D'],
                $variant
                . ': offline D must never sign'
            );
        }

        /*
         * CONTROL must reproduce the physical liveness failure.
         *
         * If this fails, STOP:
         *
         * the model is not useful for deciding a production fix.
         */
        $this->assertFalse(
            $results[
                Step5cDeterministicModel::CONTROL
            ]['recovers'],
            'CONTROL recovered: model does not reproduce physical skew failure'
        );

        /*
         * OBSERVE_ONLY is intentionally isolated.
         *
         * If evidence retention by itself recovers, STOP and investigate
         * before claiming floor or FAST is necessary.
         */
        $this->assertFalse(
            $results[
                Step5cDeterministicModel::OBSERVE_ONLY
            ]['recovers'],
            'OBSERVE_ONLY recovered: evidence retention alone changed liveness'
        );

        /*
         * CONTROL has no synchronization watermark mechanism.
         */
        foreach (
            ['A', 'B', 'C'] as $id
        ) {
            $this->assertSame(
                [],
                $results[
                    Step5cDeterministicModel::CONTROL
                ]['watermarks'][$id],
                'CONTROL must not create pacemaker watermarks'
            );
        }

        /*
         * OBSERVE_ONLY must represent the Step 5B discovery:
         *
         * B/C retain authentic A evidence even though A's far-round
         * consensus message would fail the active consensus window.
         *
         * A may have progressed beyond R31 by the end of the run, so
         * >=31 is the correct invariant.
         */
        $observe =
            $results[
                Step5cDeterministicModel::OBSERVE_ONLY
            ];

        $this->assertArrayHasKey(
            'A',
            $observe['watermarks']['B']
        );

        $this->assertArrayHasKey(
            'A',
            $observe['watermarks']['C']
        );

        $this->assertGreaterThanOrEqual(
            31,
            $observe
                ['watermarks']
                ['B']
                ['A']
        );

        $this->assertGreaterThanOrEqual(
            31,
            $observe
                ['watermarks']
                ['C']
                ['A']
        );

        /*
         * Both FAST variants must actually exercise FAST.
         */
        $this->assertGreaterThan(
            0,
            $results[
                Step5cDeterministicModel::OBSERVE_PLUS_FAST
            ]['fastTimeoutCount'],
            'OBSERVE_PLUS_FAST never exercised FAST timeout'
        );

        $this->assertGreaterThan(
            0,
            $results[
                Step5cDeterministicModel::FLOOR_PLUS_FAST
            ]['fastTimeoutCount'],
            'FLOOR_PLUS_FAST never exercised FAST timeout'
        );

        /*
         * OBSERVE_PLUS_FAST is the ablation that isolates FAST from
         * floor-based movement. It must never perform a floor jump.
         */
        $this->assertSame(
            0,
            $results[
                Step5cDeterministicModel::OBSERVE_PLUS_FAST
            ]['actualFloorJumpCount'],
            'OBSERVE_PLUS_FAST must not perform floor-based round jumps'
        );

        /*
         * The observation path must still be exercised in the ablation.
         */
        $observePlusFast =
            $results[
                Step5cDeterministicModel::OBSERVE_PLUS_FAST
            ];

        $this->assertArrayHasKey(
            'A',
            $observePlusFast['watermarks']['B']
        );

        $this->assertArrayHasKey(
            'A',
            $observePlusFast['watermarks']['C']
        );

        $this->assertGreaterThanOrEqual(
            31,
            $observePlusFast['watermarks']['B']['A']
        );

        $this->assertGreaterThanOrEqual(
            31,
            $observePlusFast['watermarks']['C']['A']
        );

        foreach (
            $variants as $variant
        ) {
            $this->printResult(
                $variant,
                $results[$variant]
            );
        }

        $controlRecovers =
            $results[
                Step5cDeterministicModel::CONTROL
            ]['recovers'];

        $observeOnlyRecovers =
            $results[
                Step5cDeterministicModel::OBSERVE_ONLY
            ]['recovers'];

        $floorOnlyRecovers =
            $results[
                Step5cDeterministicModel::FLOOR_ONLY
            ]['recovers'];

        $observePlusFastRecovers =
            $results[
                Step5cDeterministicModel::OBSERVE_PLUS_FAST
            ]['recovers'];

        $floorPlusFastRecovers =
            $results[
                Step5cDeterministicModel::FLOOR_PLUS_FAST
            ]['recovers'];

        $floorOnlyJumps =
            $results[
                Step5cDeterministicModel::FLOOR_ONLY
            ]['actualFloorJumpCount'];

        $floorPlusFastJumps =
            $results[
                Step5cDeterministicModel::FLOOR_PLUS_FAST
            ]['actualFloorJumpCount'];

        /*
         * Compare the two FAST variants directly.
         *
         * If FLOOR_PLUS_FAST records zero floor jumps, then floor movement
         * did not participate in that run. In that case the ablation should
         * have the same recovery outcome; otherwise the model has another
         * hidden semantic difference that must be investigated.
         */
        if ($floorPlusFastJumps === 0) {
            $this->assertSame(
                $observePlusFastRecovers,
                $floorPlusFastRecovers,
                'FAST variants disagree despite FLOOR_PLUS_FAST executing zero floor jumps'
            );
        }

        $comparisonKeys = [
            'recovers',
            'firstGapLE2Time',
            'firstGapLE1Time',
            'firstExactConvergenceTime',
            'firstSustainedGapLE1Time',
            'fastTimeoutCount',
            'finalRounds',
        ];

        $fastVariantsIdentical = true;

        foreach ($comparisonKeys as $key) {
            if (
                $results[
                    Step5cDeterministicModel::OBSERVE_PLUS_FAST
                ][$key]
                !==
                $results[
                    Step5cDeterministicModel::FLOOR_PLUS_FAST
                ][$key]
            ) {
                $fastVariantsIdentical = false;
                break;
            }
        }

        echo "\n";
        echo 'OBSERVE_PLUS_FAST_VS_FLOOR_PLUS_FAST=';
        echo $fastVariantsIdentical
            ? "identical_on_key_recovery_metrics\n"
            : "materially_different\n";

        echo 'FLOOR_ONLY_ACTUAL_JUMPS='
            . $floorOnlyJumps
            . "\n";

        echo 'FLOOR_PLUS_FAST_ACTUAL_JUMPS='
            . $floorPlusFastJumps
            . "\n";

        echo "STEP_5C_INTERPRETATION=";

        if ($controlRecovers) {
            echo "CONTROL_RECOVERS_MODEL_INVALID_FOR_DECISION\n";
        } elseif ($observeOnlyRecovers) {
            echo "OBSERVATION_ONLY_IS_MINIMAL_DEMONSTRATED_RECOVERY\n";
        } elseif ($observePlusFastRecovers) {
            echo "OBSERVATION_PLUS_FAST_IS_MINIMAL_DEMONSTRATED_RECOVERY\n";
        } elseif (
            $floorOnlyRecovers
            && $floorOnlyJumps > 0
        ) {
            echo "FLOOR_ONLY_IS_MINIMAL_DEMONSTRATED_RECOVERY\n";
        } elseif (
            $floorPlusFastRecovers
            && $floorPlusFastJumps > 0
        ) {
            echo "FLOOR_PLUS_FAST_REQUIRED_IN_THIS_MODEL\n";
        } elseif ($floorPlusFastRecovers) {
            echo "FLOOR_PLUS_FAST_RECOVERED_WITHOUT_FLOOR_JUMPS_INCONCLUSIVE\n";
        } else {
            echo "NO_CANDIDATE_RECOVERS_STOP_AND_ANALYZE\n";
        }

        echo 'FLOOR_JUMP_REQUIRED=';

        if ($observePlusFastRecovers) {
            echo "no\n";
        } elseif (
            $floorOnlyRecovers
            && $floorOnlyJumps > 0
        ) {
            echo "yes\n";
        } elseif (
            $floorPlusFastRecovers
            && $floorPlusFastJumps > 0
        ) {
            echo "yes\n";
        } else {
            echo "not_demonstrated\n";
        }

        echo "PRODUCTION_SOURCE_CHANGED=no\n";
    }

    /**
     * @param array<string, mixed> $result
     */
    private function printResult(
        string $variant,
        array $result
    ): void {
        echo "\n";
        echo "=== {$variant} ===\n";

        echo
            'RECOVERS='
            . (
                $result['recovers']
                    ? 'yes'
                    : 'no'
            )
            . "\n";

        foreach (
            [
                'initialGap',
                'maximumGap',
                'minimumGap',
                'finalGap',

                'firstGapLE2Event',
                'firstGapLE2Time',

                'firstGapLE1Event',
                'firstGapLE1Time',

                'firstExactConvergenceEvent',
                'firstExactConvergenceTime',

                'firstSustainedGapLE1Event',
                'firstSustainedGapLE1Time',

                'confirmedFloorAdvanceCount',
                'actualFloorJumpCount',
                'fastTimeoutCount',

                'events',
                'simulatedTime',
            ]
            as $key
        ) {
            $value =
                $result[$key];

            if ($value === null) {
                $value = 'null';
            } elseif (
                is_bool($value)
            ) {
                $value =
                    $value
                        ? 'true'
                        : 'false';
            }

            echo
                $key
                . '='
                . $value
                . "\n";
        }

        echo
            'finalRounds='
            . json_encode(
                $result['finalRounds'],
                JSON_UNESCAPED_SLASHES
            )
            . "\n";

        echo
            'watermarks='
            . json_encode(
                $result['watermarks'],
                JSON_UNESCAPED_SLASHES
            )
            . "\n";

        echo
            'confirmedFloors='
            . json_encode(
                $result['confirmedFloors'],
                JSON_UNESCAPED_SLASHES
            )
            . "\n";

        echo
            'signedRoundCounts='
            . json_encode(
                $result['signedRoundCounts'],
                JSON_UNESCAPED_SLASHES
            )
            . "\n";
    }
}