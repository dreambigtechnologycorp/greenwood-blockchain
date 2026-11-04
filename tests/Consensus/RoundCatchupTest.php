<?php

declare(strict_types=1);

namespace Greenwood\Tests\Consensus;

use PHPUnit\Framework\TestCase;
use Greenwood\Consensus\Runtime\ConsensusDriver;
use Greenwood\Consensus\Journal\SafetyJournal;
use Greenwood\Consensus\Messages\{Message,Certificate};
use Greenwood\Genesis\Genesis;
use Greenwood\Node\Chain;
use Greenwood\Persistence\{BlockFilesystem,StateSqlite};
use Greenwood\Crypto\Ed25519KeyPair;
use Greenwood\Block\Block;
use Greenwood\Block\Header;
use Greenwood\Crypto\ProtocolHash;
use Greenwood\Transaction\Envelope;

/**
 * Regression test for Round Synchronization Failure defect.
 *
 * SCENARIO:
 * - Four validators A, B, C, D with equal voting power
 * - D is offline (not in peers list for any validator)
 * - A/B/C/D share the same chain/genesis/height/state
 * - A starts at round 31 (valid proposer: (1+31) mod 4 = 0 = A)
 * - B and C start at round 3
 * - Round spread is 28, exceeding the 3-round window
 *
 * CURRENT BEHAVIOR (BEFORE FIX):
 * - B/C reject A's messages (round 31 > local.round(3) + 1 = 4)
 * - A rejects B/C's messages (round 3 < local.round(31) - 2 = 29)
 * - Result: Validators diverge and cannot reconverge
 *
 * DESIRED BEHAVIOR (AFTER FIX):
 * - B/C receive future-round evidence from A
 * - B/C catch up to round 31
 * - All three converge and finalize height 1
 */
final class RoundCatchupTest extends TestCase
{
    private string $baseDir;
    private array $keys = [];
    private array $validators = [];
    private ?Chain $chainA = null;
    private ?Chain $chainB = null;
    private ?Chain $chainC = null;
    private ?Chain $chainD = null;
    private ?SafetyJournal $journalA = null;
    private ?SafetyJournal $journalB = null;
    private ?SafetyJournal $journalC = null;
    private ?SafetyJournal $journalD = null;
    private array $drivers = [];
    private array $ports = [];
    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/greenwood-round-catchup-' . bin2hex(random_bytes(6));
        mkdir($this->baseDir, 0700, true);
        // Generate keys for A, B, C, D
        foreach (['A', 'B', 'C', 'D'] as $id) {
            $key = Ed25519KeyPair::generate();
            $this->keys[$id] = $key;
            $this->validators[$id] = [
                'publicKey' => $key->getPublicKey(),
                'votingPower' => 1
            ];
        }
        // Create genesis
        $genesis = new Genesis('round-catchup-v1');
        foreach (['A', 'B', 'C', 'D'] as $id) {
            $genesis->addValidator($id, $this->keys[$id]);
        }
        // Create chains for A, B, C, D (same genesis, different state dirs)
        foreach (['A', 'B', 'C', 'D'] as $id) {
            $dir = $this->baseDir . '/chain-' . $id;
            mkdir($dir, 0700, true);
            $files = new BlockFilesystem($dir . '/blocks');
            $db = new StateSqlite($dir . '/state.sqlite');
            $this->{'chain' . $id} = new Chain($genesis, $files, $db);

            // Create signing journal
            $journalPath = $this->baseDir . '/journal-' . $id . '.sqlite';
            $this->{'journal' . $id} = new SafetyJournal($journalPath, $this->keys[$id], $genesis->getGenesisHash(), $id);
        }
        // Reserve unique ports for each validator
        foreach (['A', 'B', 'C', 'D'] as $id) {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if (!$socket) {
                throw new \RuntimeException('Cannot bind port for ' . $id . ': ' . $errstr);
            }
            $addr = stream_socket_get_name($socket, false);
            $this->ports[$id] = (int)substr(strrchr($addr, ':'), 1);
            // Close socket immediately - Transport will bind its own socket later
            fclose($socket);
        }
    }
    protected function tearDown(): void
    {
        // Clean up drivers
        $this->drivers = [];
        // Cleanup temp dir
        if (file_exists($this->baseDir)) {
            $this->recursiveDelete($this->baseDir);
        }
    }
    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
    private function getProposer(int $height, int $round): string
    {
        $validators = array_keys($this->validators);
        sort($validators);
        return $validators[($height + $round) % 4];
    }
    private function createBlock(int $height, int $round, string $proposer, string $parentHash, string $prevStateRoot, string $txRoot, string $stateRoot): Block
    {
        $header = new Header(
            1,
            'round-catchup-v1',
            $height,
            $round,
            $parentHash,
            $prevStateRoot,
            $txRoot,
            $stateRoot,
            ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $this->validators[$proposer]['publicKey']),
            $this->chainA->genesis->getGenesisHash(),
            0
        );
        return new Block($header, []);
    }
    private function createProposal(string $proposer, int $height, int $round, Block $block): Message
    {
        return new Message(
            'proposal',
            'round-catchup-v1',
            $this->chainA->genesis->getGenesisHash(),
            $height,
            $round,
            $proposer,
            $block->getHash(),
            $block->toBytes(),
            ''
        );
    }
    private function createVote(string $kind, string $validator, int $height, int $round, string $target): Message
    {
        return new Message(
            $kind,
            'round-catchup-v1',
            $this->chainA->genesis->getGenesisHash(),
            $height,
            $round,
            $validator,
            $target,
            '',
            ''
        );
    }
    private function createDriver(string $id, int $initialRound, array $peers): ConsensusDriver
    {
        $chain = $this->{'chain' . $id};
        $journal = $this->{'journal' . $id};
        $endpoint = 'http://127.0.0.1:' . $this->ports[$id];

        $driver = new ConsensusDriver($chain, $id, $peers, $journal, $endpoint, new \Greenwood\Node\ChainConfig(['block_time_seconds' => 1]));

        // Override initial round for testing
        $ref = new \ReflectionClass($driver);
        $prop = $ref->getProperty('round');
        $prop->setAccessible(true);
        $prop->setValue($driver, $initialRound);
        $prop->setAccessible(false);

        $this->drivers[$id] = $driver;
        return $driver;
    }

    private function getRound(ConsensusDriver $driver): int
    {
        $ref = new \ReflectionClass($driver);
        $prop = $ref->getProperty('round');
        $prop->setAccessible(true);
        $round = $prop->getValue($driver);
        $prop->setAccessible(false);
        return $round;
    }

    private function getRoundWatermarks(ConsensusDriver $driver): array
    {
        $ref = new \ReflectionClass($driver);
        $prop = $ref->getProperty('roundWatermarks');
        $prop->setAccessible(true);
        $watermarks = $prop->getValue($driver);
        $prop->setAccessible(false);
        return $watermarks;
    }

    private function agePacemakerTimers(
        ConsensusDriver $driver,
        float $seconds
    ): void {
        $ref = new \ReflectionClass($driver);

        // Advance every clock used by tick(), including the proposal cadence
        // and bounded phase hold. Mixing simulated round time with wall-clock
        // height/hold time can exhaust the loop before a proposal is allowed.
        foreach (['started', 'heightStarted', 'fastStarted', 'phaseHoldStarted'] as $name) {
            $clock = $ref->getProperty($name);
            $armedAt = $clock->getValue($driver);
            if ($armedAt !== null) {
                $clock->setValue($driver, (float)$armedAt - $seconds);
            }
        }
    }

    public function testCurrentRoundWindowRejectsFutureMessages(): void
    {
        // Same-height far-future evidence must update the volatile pacemaker
        // watermark but remain rejected from active consensus processing.
        $driverB = $this->createDriver('B', 3, []);

        $mA = $this->createVote('prevote', 'A', 1, 31, str_repeat('a', 32));
        $signedA = $mA->signed($this->keys['A']->sign($mA->digest()));

        try {
            $driverB->accept($signedA);
            $this->fail('Future message should have been rejected from active consensus');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('round', strtolower($e->getMessage()));
        }

        $watermarks = $this->getRoundWatermarks($driverB);
        $this->assertArrayHasKey('A', $watermarks);
        $this->assertSame(31, $watermarks['A']);
        $this->assertSame(3, $this->getRound($driverB), 'Observation must not directly jump the local round');
    }

    public function testCurrentRoundWindowRejectsStaleMessages(): void
    {
        // Same-height stale evidence is still outside active consensus, but it
        // remains valid authenticated signer-round evidence for the pacemaker.
        $driverA = $this->createDriver('A', 31, []);

        $mB = $this->createVote('prevote', 'B', 1, 3, str_repeat('b', 32));
        $signedB = $mB->signed($this->keys['B']->sign($mB->digest()));

        try {
            $driverA->accept($signedB);
            $this->fail('Stale message should have been rejected from active consensus');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('round', strtolower($e->getMessage()));
        }

        $watermarks = $this->getRoundWatermarks($driverA);
        $this->assertArrayHasKey('B', $watermarks);
        $this->assertSame(3, $watermarks['B']);
        $this->assertSame(31, $this->getRound($driverA));
    }

    public function testWrongHeightDoesNotCreatePacemakerWatermark(): void
    {
        $driverB = $this->createDriver('B', 3, []);

        $mA = $this->createVote('prevote', 'A', 2, 31, str_repeat('h', 32));
        $signedA = $mA->signed($this->keys['A']->sign($mA->digest()));

        try {
            $driverB->accept($signedA);
            $this->fail('Wrong-height evidence must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('round', strtolower($e->getMessage()));
        }

        $this->assertArrayNotHasKey('A', $this->getRoundWatermarks($driverB));
        $this->assertSame(3, $this->getRound($driverB));
    }

    public function testRoundZeroWatermarkIsDistinctFromAbsence(): void
    {
        $driverB = $this->createDriver('B', 3, []);

        $mA = $this->createVote('prevote', 'A', 1, 0, str_repeat('z', 32));
        $signedA = $mA->signed($this->keys['A']->sign($mA->digest()));

        try {
            $driverB->accept($signedA);
            $this->fail('Round 0 is stale for local round 3 and must remain outside active processing');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('round', strtolower($e->getMessage()));
        }

        $watermarks = $this->getRoundWatermarks($driverB);
        $this->assertTrue(array_key_exists('A', $watermarks));
        $this->assertSame(0, $watermarks['A']);
        $this->assertFalse(array_key_exists('D', $watermarks));
    }

    public function testReplayedEvidenceDoesNotRegressWatermark(): void
    {
        $driverB = $this->createDriver('B', 3, []);

        foreach ([31, 31, 30, 31] as $round) {
            $mA = $this->createVote('prevote', 'A', 1, $round, str_repeat('r', 32));
            $signedA = $mA->signed($this->keys['A']->sign($mA->digest()));

            try {
                $driverB->accept($signedA);
            } catch (\InvalidArgumentException) {
                // Expected: these messages are outside B's active round window.
            }
        }

        $this->assertSame(31, $this->getRoundWatermarks($driverB)['A']);
    }

    public function testExistingTwoSignerNextRoundCatchupIsPreserved(): void
    {
        $driverB = $this->createDriver('B', 3, []);

        $mA = $this->createVote('prevote', 'A', 1, 4, str_repeat('a', 32));
        $signedA = $mA->signed($this->keys['A']->sign($mA->digest()));
        $driverB->accept($signedA);

        $this->assertSame(3, $this->getRound($driverB), 'One validator at local+1 is insufficient');

        $mC = $this->createVote('prevote', 'C', 1, 4, str_repeat('c', 32));
        $signedC = $mC->signed($this->keys['C']->sign($mC->digest()));
        $driverB->accept($signedC);

        $this->assertSame(4, $this->getRound($driverB), 'Two authenticated validators at local+1 must preserve existing catch-up');
    }

    public function testFastAdvancesExactlyOneRoundWhenCurrentProposerIsAhead(): void
    {
        // Height 1 / round 3 proposer is A.
        $driverB = $this->createDriver('B', 3, []);

        $mA = $this->createVote('prevote', 'A', 1, 31, str_repeat('f', 32));
        $signedA = $mA->signed($this->keys['A']->sign($mA->digest()));

        try {
            $driverB->accept($signedA);
        } catch (\InvalidArgumentException) {
            // Expected active-window rejection after pacemaker observation.
        }

        $this->assertSame(31, $this->getRoundWatermarks($driverB)['A']);

        $this->agePacemakerTimers($driverB, 0.60);
        $driverB->tick();

        $this->assertSame(4, $this->getRound($driverB), 'FAST must advance exactly R -> R+1');
    }

    public function testHugeWatermarkDoesNotFastForwardUnrelatedProposer(): void
    {
        // Height 1 / round 3 proposer is A, not D.
        $driverB = $this->createDriver('B', 3, []);

        $mD = $this->createVote('prevote', 'D', 1, 1_000_000, str_repeat('d', 32));
        $signedD = $mD->signed($this->keys['D']->sign($mD->digest()));

        try {
            $driverB->accept($signedD);
        } catch (\InvalidArgumentException) {
            // Expected active-window rejection after pacemaker observation.
        }

        $this->assertSame(1_000_000, $this->getRoundWatermarks($driverB)['D']);

        $this->agePacemakerTimers($driverB, 0.60);
        $driverB->tick();

        $this->assertSame(3, $this->getRound($driverB), 'D watermark must not accelerate an A-proposer round');
    }

    public function testRoundSpreadPersistsWithoutCatchup(): void
    {
        // Characterize the initial skew before timer-driven recovery.
        // Observation is a hint; it must not directly jump local round.
        $driverA = $this->createDriver('A', 31, []);
        $driverB = $this->createDriver('B', 3, []);
        $driverC = $this->createDriver('C', 3, []);

        $mB = $this->createVote('prevote', 'B', 1, 3, str_repeat('b', 32));
        $signedB = $mB->signed($this->keys['B']->sign($mB->digest()));

        $exceptionCaught = false;
        try {
            $driverA->accept($signedB);
        } catch (\InvalidArgumentException) {
            $exceptionCaught = true;
        }
        $this->assertTrue($exceptionCaught, 'Stale message from B to A should be actively rejected');

        $mA = $this->createVote('prevote', 'A', 1, 31, str_repeat('a', 32));
        $signedA = $mA->signed($this->keys['A']->sign($mA->digest()));

        $exceptionCaught = false;
        try {
            $driverB->accept($signedA);
        } catch (\InvalidArgumentException) {
            $exceptionCaught = true;
        }
        $this->assertTrue($exceptionCaught, 'Future message from A to B should be actively rejected');

        $roundA = $this->getRound($driverA);
        $roundB = $this->getRound($driverB);
        $roundC = $this->getRound($driverC);

        $this->assertGreaterThan(25, $roundA - $roundB);
        $this->assertGreaterThan(25, $roundA - $roundC);

        $this->assertSame(3, $this->getRound($driverB), 'Observation alone must not jump B');
        $this->assertSame(31, $this->getRoundWatermarks($driverB)['A']);
    }

    public function testThreeHonestValidatorsRecoverFromLargeRoundSkew(): void
    {
        // Regression for the physical ROUND_SYNCHRONIZATION_FAILURE:
        // A starts at R31, B/C at R3, D offline. Recovery must happen through
        // existing +1 catch-up plus proposer-scoped FAST, with the active
        // acceptance window unchanged.

        $peersA = [
            'B' => 'http://127.0.0.1:' . $this->ports['B'],
            'C' => 'http://127.0.0.1:' . $this->ports['C'],
        ];
        $peersB = [
            'A' => 'http://127.0.0.1:' . $this->ports['A'],
            'C' => 'http://127.0.0.1:' . $this->ports['C'],
        ];
        $peersC = [
            'A' => 'http://127.0.0.1:' . $this->ports['A'],
            'B' => 'http://127.0.0.1:' . $this->ports['B'],
        ];

        $driverA = $this->createDriver('A', 31, $peersA);
        $driverB = $this->createDriver('B', 3, $peersB);
        $driverC = $this->createDriver('C', 3, $peersC);

        $this->assertSame(0, $driverA->chain->tip->getHeight());
        $this->assertSame(0, $driverB->chain->tip->getHeight());
        $this->assertSame(0, $driverC->chain->tip->getHeight());

        $this->assertSame(31, $this->getRound($driverA));
        $this->assertSame(3, $this->getRound($driverB));
        $this->assertSame(3, $this->getRound($driverC));

        $validators = $driverA->chain->genesis->getValidators();
        $this->assertCount(4, $validators);
        $this->assertArrayHasKey('A', $validators);
        $this->assertArrayHasKey('B', $validators);
        $this->assertArrayHasKey('C', $validators);
        $this->assertArrayHasKey('D', $validators);

        // Simulate elapsed time without weakening production timeout
        // semantics. Each loop ages round, height, FAST and phase-hold
        // clocks together; tick() still executes the
        // real transport, signing, active-window, catch-up, FAST, and finality
        // code paths.
        $iterations = 0;
        $simulatedSeconds = 0.0;
        $maxIterations = 6000;
        $checkpoints = [];

        while ($iterations < $maxIterations) {
            $this->agePacemakerTimers($driverA, 1.0);
            $this->agePacemakerTimers($driverB, 1.0);
            $this->agePacemakerTimers($driverC, 1.0);

            $driverA->tick();
            $driverB->tick();
            $driverC->tick();

            $iterations++;
            $simulatedSeconds += 1.0;

            if ($iterations % 250 === 0) {
                $checkpoints[] = [
                    'time' => $simulatedSeconds,
                    'roundA' => $this->getRound($driverA),
                    'roundB' => $this->getRound($driverB),
                    'roundC' => $this->getRound($driverC),
                    'heightA' => $driverA->chain->tip->getHeight(),
                    'heightB' => $driverB->chain->tip->getHeight(),
                    'heightC' => $driverC->chain->tip->getHeight(),
                ];
            }

            if (
                $driverA->chain->tip->getHeight() >= 1
                && $driverB->chain->tip->getHeight() >= 1
                && $driverC->chain->tip->getHeight() >= 1
            ) {
                break;
            }
        }

        echo "\n=== ROUND ADVANCEMENT CHECKPOINTS ===\n";
        echo "SimSec\tRoundA\tRoundB\tRoundC\tHeightA\tHeightB\tHeightC\n";
        foreach ($checkpoints as $cp) {
            echo sprintf(
                "%.0f\t%d\t%d\t%d\t%d\t%d\t%d\n",
                $cp['time'],
                $cp['roundA'],
                $cp['roundB'],
                $cp['roundC'],
                $cp['heightA'],
                $cp['heightB'],
                $cp['heightC']
            );
        }
        echo "===================================\n\n";

        $this->assertGreaterThanOrEqual(
            1,
            $driverA->chain->tip->getHeight(),
            'Validator A should finalize height 1'
        );
        $this->assertGreaterThanOrEqual(
            1,
            $driverB->chain->tip->getHeight(),
            'Validator B should finalize height 1'
        );
        $this->assertGreaterThanOrEqual(
            1,
            $driverC->chain->tip->getHeight(),
            'Validator C should finalize height 1'
        );
    }
}
