<?php

declare(strict_types=1);

namespace Greenwood\Tests\Consensus;

use Greenwood\Consensus\Journal\SafetyJournal;
use Greenwood\Consensus\Messages\Message;
use Greenwood\Consensus\Runtime\ConsensusDriver;
use Greenwood\Crypto\Ed25519KeyPair;
use Greenwood\Genesis\Genesis;
use Greenwood\Node\Chain;
use Greenwood\Persistence\BlockFilesystem;
use Greenwood\Persistence\StateSqlite;
use PHPUnit\Framework\TestCase;

/**
 * Production ConsensusDriver phase-convergence regression tests.
 *
 * These tests exercise the real ConsensusDriver implementation. Reflection is
 * used only to establish deterministic round/timer state and to inspect the
 * volatile pacemaker state that is intentionally private.
 */
final class PhaseConvergenceHoldTest extends TestCase
{
    private string $baseDir;
    /** @var array<string,Ed25519KeyPair> */
    private array $keys = [];
    private Genesis $genesis;
    /** @var list<ConsensusDriver> */
    private array $drivers = [];

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir()
            . '/greenwood-phase-convergence-'
            . bin2hex(random_bytes(6));
        mkdir($this->baseDir, 0700, true);

        $this->genesis = new Genesis('phase-convergence-v1');

        foreach (['A', 'B', 'C', 'D'] as $id) {
            $key = Ed25519KeyPair::generate();
            $this->keys[$id] = $key;
            $this->genesis->addValidator($id, $key);
        }
    }

    protected function tearDown(): void
    {
        $this->drivers = [];
        $this->deleteDirectory($this->baseDir);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    private function reserveEndpoint(): string
    {
        $socket = stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr
        );

        if ($socket === false) {
            throw new \RuntimeException(
                'Failed to reserve endpoint: ' . $errstr,
                $errno
            );
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($address === false) {
            throw new \RuntimeException('Failed to resolve reserved endpoint');
        }

        $separator = strrpos($address, ':');
        if ($separator === false) {
            throw new \RuntimeException('Unexpected endpoint: ' . $address);
        }

        return 'http://127.0.0.1:' . (int) substr($address, $separator + 1);
    }

    /**
     * @return array{driver:ConsensusDriver,journal:SafetyJournal,chain:Chain}
     */
    private function createNode(string $id, int $round, string $suffix): array
    {
        $dir = $this->baseDir . '/node-' . $id . '-' . $suffix;
        mkdir($dir, 0700, true);

        $files = new BlockFilesystem($dir . '/blocks');
        $db = new StateSqlite($dir . '/state.sqlite');
        $chain = new Chain($this->genesis, $files, $db);

        $journal = new SafetyJournal(
            $dir . '/signing.sqlite',
            $this->keys[$id],
            $this->genesis->getGenesisHash(),
            $id
        );

        $driver = new ConsensusDriver(
            $chain,
            $id,
            [],
            $journal,
            $this->reserveEndpoint(),
            new \Greenwood\Node\ChainConfig(['block_time_seconds' => 1])
        );

        $this->setPrivate($driver, 'round', $round);
        $this->setPrivate($driver, 'started', microtime(true));
        $this->drivers[] = $driver;

        return [
            'driver' => $driver,
            'journal' => $journal,
            'chain' => $chain,
        ];
    }

    private function getPrivate(object $object, string $property): mixed
    {
        $ref = new \ReflectionClass($object);
        return $ref->getProperty($property)->getValue($object);
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        $ref = new \ReflectionClass($object);
        $ref->getProperty($property)->setValue($object, $value);
    }

    private function callPrivate(
        object $object,
        string $method,
        mixed ...$arguments
    ): mixed {
        $ref = new \ReflectionClass($object);
        return $ref->getMethod($method)->invoke($object, ...$arguments);
    }

    private function unitForRound(int $round): float
    {
        return min(10.0, 1.0 + ($round * 0.5));
    }

    private function signedVote(
        string $kind,
        string $validator,
        int $round,
        string $target = ''
    ): Message {
        $message = new Message(
            $kind,
            $this->genesis->getChainId(),
            $this->genesis->getGenesisHash(),
            1,
            $round,
            $validator,
            $target,
            '',
            ''
        );

        return $message->signed(
            $this->keys[$validator]->sign($message->digest())
        );
    }

    private function setWatermarks(
        ConsensusDriver $driver,
        array $watermarks
    ): void {
        $this->setPrivate($driver, 'roundWatermarks', $watermarks);
    }

    private function armPhaseHold(ConsensusDriver $driver): void
    {
        $this->callPrivate(
            $driver,
            'armPhaseHoldIfEligible',
            microtime(true)
        );
    }

    private function hasLocalJournalMessage(
        SafetyJournal $journal,
        string $validator,
        int $round,
        string $kind,
        ?string $target = null
    ): bool {
        foreach ($journal->messages(1) ?? [] as $message) {
            if ($message->validator !== $validator) {
                continue;
            }
            if ($message->round !== $round || $message->kind !== $kind) {
                continue;
            }
            if ($target !== null && $message->target !== $target) {
                continue;
            }
            return true;
        }

        return false;
    }

    public function testOneLaggardDoesNotArm(): void
    {
        $node = $this->createNode('A', 10, 'one-laggard');
        $driver = $node['driver'];

        $this->setWatermarks($driver, ['B' => 9]);
        $this->armPhaseHold($driver);

        $this->assertNull($this->getPrivate($driver, 'phaseHoldRound'));
        $this->assertNull($this->getPrivate($driver, 'phaseHoldUsedRound'));
    }

    public function testTwoValidatorsExactlyOneBehindArmHold(): void
    {
        $node = $this->createNode('A', 10, 'two-laggards');
        $driver = $node['driver'];

        $this->setWatermarks($driver, ['B' => 9, 'C' => 9]);
        $this->armPhaseHold($driver);

        $this->assertSame(10, $this->getPrivate($driver, 'phaseHoldRound'));
        $this->assertSame(10, $this->getPrivate($driver, 'phaseHoldUsedRound'));
        $this->assertSame(
            ['B' => true, 'C' => true],
            $this->getPrivate($driver, 'phaseHoldValidators')
        );
        $this->assertIsFloat($this->getPrivate($driver, 'phaseHoldStarted'));
    }

    public function testTwoRoundsBehindDoesNotArm(): void
    {
        $node = $this->createNode('A', 10, 'two-rounds-behind');
        $driver = $node['driver'];

        $this->setWatermarks($driver, ['B' => 8, 'C' => 8]);
        $this->armPhaseHold($driver);

        $this->assertNull($this->getPrivate($driver, 'phaseHoldRound'));
    }

    public function testFutureWatermarksDoNotArmPhaseHold(): void
    {
        $node = $this->createNode('A', 10, 'future-watermarks');
        $driver = $node['driver'];

        $this->setWatermarks($driver, ['B' => 11, 'C' => 11]);
        $this->armPhaseHold($driver);

        $this->assertNull($this->getPrivate($driver, 'phaseHoldRound'));
    }

    public function testActiveHoldSuppressesTimeoutNilPrevote(): void
    {
        $node = $this->createNode('A', 10, 'suppress-prevote');
        $driver = $node['driver'];
        $journal = $node['journal'];
        $unit = $this->unitForRound(10);

        $this->setWatermarks($driver, ['B' => 9, 'C' => 9]);
        $this->setPrivate(
            $driver,
            'started',
            microtime(true) - ($unit + 0.25)
        );

        $driver->tick();

        $this->assertSame(10, $this->getPrivate($driver, 'phaseHoldRound'));
        $this->assertFalse(
            $this->hasLocalJournalMessage($journal, 'A', 10, 'prevote', ''),
            'An active phase hold must suppress timeout-generated NIL prevote'
        );
    }

    public function testActiveHoldSuppressesTimeoutNilPrecommit(): void
    {
        $node = $this->createNode('A', 10, 'suppress-precommit');
        $driver = $node['driver'];
        $journal = $node['journal'];
        $unit = $this->unitForRound(10);

        $this->setWatermarks($driver, ['B' => 9, 'C' => 9]);
        $this->setPrivate(
            $driver,
            'started',
            microtime(true) - ((2 * $unit) + 0.25)
        );

        $driver->tick();

        $this->assertSame(10, $this->getPrivate($driver, 'phaseHoldRound'));
        $this->assertFalse(
            $this->hasLocalJournalMessage($journal, 'A', 10, 'precommit', ''),
            'An active phase hold must suppress timeout-generated NIL precommit'
        );
    }

    public function testActiveHoldSuppressesOrdinaryRoundTimeout(): void
    {
        $node = $this->createNode('A', 10, 'suppress-round-timeout');
        $driver = $node['driver'];
        $unit = $this->unitForRound(10);

        $this->setWatermarks($driver, ['B' => 9, 'C' => 9]);
        $this->setPrivate(
            $driver,
            'started',
            microtime(true) - ((3 * $unit) + 0.25)
        );

        $driver->tick();

        $this->assertSame(10, $this->getPrivate($driver, 'round'));
        $this->assertSame(10, $this->getPrivate($driver, 'phaseHoldRound'));
    }

    public function testFastStillOverridesPhaseHoldAndAdvancesExactlyOne(): void
    {
        // Height 1 / round 3 proposer is A. C and D are the two laggards at r-1.
        $node = $this->createNode('B', 3, 'fast-overrides-hold');
        $driver = $node['driver'];

        $this->setWatermarks(
            $driver,
            ['A' => 31, 'C' => 2, 'D' => 2]
        );

        // First tick arms both FAST and the phase hold.
        $driver->tick();
        $this->assertSame(3, $this->getPrivate($driver, 'phaseHoldRound'));
        $this->assertSame(3, $this->getPrivate($driver, 'fastRound'));

        $this->setPrivate($driver, 'fastStarted', microtime(true) - 0.60);
        $driver->tick();

        $this->assertSame(4, $this->getPrivate($driver, 'round'));
        $this->assertNull($this->getPrivate($driver, 'phaseHoldRound'));
        $this->assertNull($this->getPrivate($driver, 'phaseHoldStarted'));
        $this->assertSame([], $this->getPrivate($driver, 'phaseHoldValidators'));
        $this->assertSame([], $this->getPrivate($driver, 'phaseHoldCaughtUp'));
        $this->assertNull($this->getPrivate($driver, 'phaseHoldUsedRound'));
    }

    public function testCatchupCompletionRequiresTheTrackedValidators(): void
    {
        $node = $this->createNode('A', 10, 'tracked-catchup');
        $driver = $node['driver'];

        $this->setWatermarks($driver, ['B' => 9, 'C' => 9]);
        $this->armPhaseHold($driver);

        $driver->accept($this->signedVote('prevote', 'B', 10));
        $driver->accept($this->signedVote('prevote', 'D', 10));

        $this->assertSame(10, $this->getPrivate($driver, 'phaseHoldRound'));
        $this->assertSame(
            ['B' => true],
            $this->getPrivate($driver, 'phaseHoldCaughtUp')
        );

        $oldStarted = (float) $this->getPrivate($driver, 'started');
        $driver->accept($this->signedVote('prevote', 'C', 10));

        $this->assertNull($this->getPrivate($driver, 'phaseHoldRound'));
        $this->assertGreaterThanOrEqual(
            $oldStarted,
            (float) $this->getPrivate($driver, 'started')
        );
        $this->assertSame(10, $this->getPrivate($driver, 'phaseHoldUsedRound'));
    }

    public function testCatchupRefreshesPhaseTimerExactlyOnce(): void
    {
        $node = $this->createNode('A', 10, 'single-refresh');
        $driver = $node['driver'];

        $this->setWatermarks($driver, ['B' => 9, 'C' => 9]);
        $this->armPhaseHold($driver);
        $this->setPrivate($driver, 'started', 100.0);

        $driver->accept($this->signedVote('prevote', 'B', 10));
        $driver->accept($this->signedVote('prevote', 'C', 10));

        $refreshed = (float) $this->getPrivate($driver, 'started');
        $this->assertGreaterThan(100.0, $refreshed);
        $this->assertNull($this->getPrivate($driver, 'phaseHoldRound'));

        // After completion the hold is gone, so later current-round evidence cannot
        // refresh the timer again through the phase-convergence path.
        $this->setPrivate($driver, 'started', 1234.5);
        $driver->accept($this->signedVote('precommit', 'B', 10));

        $this->assertSame(1234.5, $this->getPrivate($driver, 'started'));
    }

    public function testExpiredHoldCannotRearmInSameRound(): void
    {
        $node = $this->createNode('A', 10, 'no-rearm');
        $driver = $node['driver'];
        $unit = $this->unitForRound(10);

        $this->setWatermarks($driver, ['B' => 9, 'C' => 9]);
        $this->armPhaseHold($driver);
        $this->assertSame(10, $this->getPrivate($driver, 'phaseHoldUsedRound'));

        $this->setPrivate(
            $driver,
            'phaseHoldStarted',
            microtime(true) - ((3 * $unit) + 0.25)
        );

        $active = $this->callPrivate(
            $driver,
            'phaseHoldActive',
            microtime(true),
            $unit
        );

        $this->assertFalse($active);
        $this->assertNull($this->getPrivate($driver, 'phaseHoldRound'));
        $this->assertSame(10, $this->getPrivate($driver, 'phaseHoldUsedRound'));

        $this->armPhaseHold($driver);

        $this->assertNull($this->getPrivate($driver, 'phaseHoldRound'));
        $this->assertSame(10, $this->getPrivate($driver, 'phaseHoldUsedRound'));
    }

    public function testNormalRoundAdvancementClearsPhaseState(): void
    {
        $node = $this->createNode('A', 10, 'normal-round-clear');
        $driver = $node['driver'];
        $unit = $this->unitForRound(10);

        $this->setWatermarks($driver, ['B' => 9, 'C' => 9]);
        $this->armPhaseHold($driver);

        // Expire the hold and independently make the round timer old enough to advance.
        $this->setPrivate(
            $driver,
            'phaseHoldStarted',
            microtime(true) - ((3 * $unit) + 0.25)
        );
        $this->setPrivate(
            $driver,
            'started',
            microtime(true) - ((3 * $unit) + 0.50)
        );

        $driver->tick();

        $this->assertSame(11, $this->getPrivate($driver, 'round'));
        $this->assertNull($this->getPrivate($driver, 'phaseHoldRound'));
        $this->assertNull($this->getPrivate($driver, 'phaseHoldStarted'));
        $this->assertSame([], $this->getPrivate($driver, 'phaseHoldValidators'));
        $this->assertSame([], $this->getPrivate($driver, 'phaseHoldCaughtUp'));
        $this->assertNull($this->getPrivate($driver, 'phaseHoldUsedRound'));
    }

    public function testTwoSignerNextRoundCatchupClearsPhaseState(): void
    {
        $node = $this->createNode('A', 10, 'next-round-clear');
        $driver = $node['driver'];

        $this->setWatermarks($driver, ['B' => 9, 'C' => 9]);
        $this->armPhaseHold($driver);
        $this->assertSame(10, $this->getPrivate($driver, 'phaseHoldRound'));

        $driver->accept($this->signedVote('prevote', 'B', 11));
        $this->assertSame(10, $this->getPrivate($driver, 'round'));

        $driver->accept($this->signedVote('prevote', 'C', 11));

        $this->assertSame(11, $this->getPrivate($driver, 'round'));
        $this->assertNull($this->getPrivate($driver, 'phaseHoldRound'));
        $this->assertNull($this->getPrivate($driver, 'phaseHoldStarted'));
        $this->assertSame([], $this->getPrivate($driver, 'phaseHoldValidators'));
        $this->assertSame([], $this->getPrivate($driver, 'phaseHoldCaughtUp'));
        $this->assertNull($this->getPrivate($driver, 'phaseHoldUsedRound'));
    }

    public function testNilPrevoteMakesCurrentRoundIneligibleForHold(): void
    {
        $node = $this->createNode('A', 10, 'nil-prevote-ineligible');
        $driver = $node['driver'];
        $journal = $node['journal'];

        $this->callPrivate($driver, 'sign', 'prevote', '');
        $this->assertTrue(
            $this->hasLocalJournalMessage($journal, 'A', 10, 'prevote', '')
        );

        $this->setWatermarks($driver, ['B' => 9, 'C' => 9]);
        $this->armPhaseHold($driver);

        $this->assertNull($this->getPrivate($driver, 'phaseHoldRound'));
    }

    public function testAnyPrecommitMakesCurrentRoundIneligibleForHold(): void
    {
        $node = $this->createNode('A', 10, 'precommit-ineligible');
        $driver = $node['driver'];
        $journal = $node['journal'];

        $this->callPrivate($driver, 'sign', 'precommit', '');
        $this->assertTrue(
            $this->hasLocalJournalMessage($journal, 'A', 10, 'precommit', '')
        );

        $this->setWatermarks($driver, ['B' => 9, 'C' => 9]);
        $this->armPhaseHold($driver);

        $this->assertNull($this->getPrivate($driver, 'phaseHoldRound'));
    }
}
