<?php

declare(strict_types=1);

namespace Greenwood\Tests\Consensus;

use PHPUnit\Framework\TestCase;
use Greenwood\Consensus\Runtime\ConsensusDriver;
use Greenwood\Consensus\Journal\SafetyJournal;
use Greenwood\Consensus\Messages\Message;
use Greenwood\Genesis\Genesis;
use Greenwood\Node\Chain;
use Greenwood\Persistence\BlockFilesystem;
use Greenwood\Persistence\StateSqlite;
use Greenwood\Crypto\Ed25519KeyPair;

/**
 * Real ConsensusDriver signing opportunity characterization.
 * 
 * This test captures ACTUAL signed messages from production ConsensusDriver
 * to verify what authenticated signer-round evidence is naturally generated.
 *
 * Production self-signing flows through sign() -> SafetyJournal::sign()
 * -> ConsensusDriver::accept(), so real signatures must naturally create
 * the signer's volatile pacemaker watermark.
 */
final class ConsensusSigningOpportunityTest extends TestCase
{
    /**
     * Get validator round using reflection (private property)
     */
    private function getRound(ConsensusDriver $driver): int
    {
        $ref = new \ReflectionClass($driver);
        $prop = $ref->getProperty('round');
        $prop->setAccessible(true);
        $round = $prop->getValue($driver);
        $prop->setAccessible(false);
        return $round;
    }

    /**
     * Get validator height using reflection (private property)
     */
    private function getHeight(ConsensusDriver $driver): int
    {
        $ref = new \ReflectionClass($driver);
        $prop = $ref->getProperty('height');
        $prop->setAccessible(true);
        $height = $prop->getValue($driver);
        $prop->setAccessible(false);
        return $height;
    }

    /**
     * Get validator id using reflection (private readonly property)
     */
    private function getId(ConsensusDriver $driver): string
    {
        $ref = new \ReflectionClass($driver);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $id = $prop->getValue($driver);
        $prop->setAccessible(false);
        return $id;
    }

    /**
     * Get started time using reflection (private property)
     */
    private function getStarted(ConsensusDriver $driver): float
    {
        $ref = new \ReflectionClass($driver);
        $prop = $ref->getProperty('started');
        $prop->setAccessible(true);
        $started = $prop->getValue($driver);
        $prop->setAccessible(false);
        return $started;
    }

    /**
     * Get volatile pacemaker signer watermarks using reflection.
     *
     * @return array<string,int>
     */
    private function getRoundWatermarks(ConsensusDriver $driver): array
    {
        $ref = new \ReflectionClass($driver);
        $prop = $ref->getProperty('roundWatermarks');
        $prop->setAccessible(true);

        /** @var array<string,int> $watermarks */
        $watermarks = $prop->getValue($driver);

        $prop->setAccessible(false);
        return $watermarks;
    }

    /**
     * Test A: Non-proposer without proposal signs NIL votes
     * 
     * At height=1, round=0, canonical A/B/C/D ordering:
     * proposer index = (1 + 0) % 4 = 1 = B
     * 
     * So C is definitely NOT proposer. We use C as non-proposer.
     * 
     * No accepted proposal exists.
     * 
     * R0 timing:
     *   unit = min(10, 1 + 0.5*0) = 1.0 second
     *   prevote NIL requires elapsed > unit = 1.0 second
     *   precommit NIL requires elapsed > 2*unit = 2.0 seconds
     * 
     * We tick for >2 seconds to ensure both NIL votes are signed.
     */
    public function testNonProposerWithoutProposalSignsNilVotes(): void
    {
        $baseDir = sys_get_temp_dir() . '/greenwood-np-' . bin2hex(random_bytes(6));
        mkdir($baseDir, 0700, true);
        
        // Create genesis
        $genesis = new Genesis('non-proposer-v1');
        $keys = [];
        foreach (['A', 'B', 'C', 'D'] as $id) {
            $key = Ed25519KeyPair::generate();
            $keys[$id] = $key;
            $genesis->addValidator($id, $key);
        }
        
        // Create chain
        $dir = $baseDir . '/chain';
        mkdir($dir, 0700, true);
        $files = new BlockFilesystem($dir . '/blocks');
        $db = new StateSqlite($dir . '/state.sqlite');
        $chain = new Chain($genesis, $files, $db);
        
        // Create journal for validator C (non-proposer at round 0)
        $journalPath = $baseDir . '/journal.sqlite';
        $journal = new SafetyJournal($journalPath, $keys['C'], $genesis->getGenesisHash(), 'C');
        
        // Create driver for C with empty peers (no proposals will be accepted)
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$socket) throw new \RuntimeException('Failed to create socket');
        $meta = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = explode(':', $meta)[1];
        
        $driver = new ConsensusDriver($chain, 'C', [], $journal, 'http://127.0.0.1:' . $port, new \Greenwood\Node\ChainConfig(['block_time_seconds' => 1]));
        
        // Verify C is not proposer at round 0, height 1
        $validators = array_keys($genesis->getValidators());
        sort($validators);
        $proposerIndex = ($this->getHeight($driver) + $this->getRound($driver)) % 4;
        $proposerId = $validators[$proposerIndex];
        
        $this->assertEquals('B', $proposerId, 'B should be proposer at height=1, round=0');
        $this->assertEquals('C', $this->getId($driver), 'Validator should be C');
        $this->assertEquals(0, $this->getRound($driver), 'C should start at round 0');
        
        // Get initial messages
        $initialMessages = $journal->messages(1) ?? [];
        $initialCount = count($initialMessages);
        
        // Tick for >2 seconds to ensure prevote and precommit NIL are signed
        // R0 unit = 1.0s, so we need >1s for prevote and >2s for precommit
        $startTime = microtime(true);
        $deadline = $startTime + 2.5;
        
        while (microtime(true) < $deadline) {
            $driver->tick();
            usleep(50000); // 50ms between ticks
        }
        
        // Get final messages
        $finalMessages = $journal->messages(1) ?? [];
        
        echo "\n=== NON-PROPOSER C SIGNED MESSAGES ===\n";
        echo "Height: " . $this->getHeight($driver) . "\n";
        echo "Round: " . $this->getRound($driver) . "\n";
        echo "Initial messages: $initialCount\n";
        echo "Final messages: " . count($finalMessages) . "\n";
        
        $signedKinds = [];
        foreach ($finalMessages as $msg) {
            $signedKinds[$msg->round][$msg->kind] = true;
            echo "R{$msg->round} {$msg->kind} target=" . ($msg->target === '' ? 'NIL' : bin2hex($msg->target)) . "\n";
        }
        echo "=====================================\n\n";
        
        // Required assertions - these MUST pass or the test fails
        
        // A) NO proposal signed by this validator at R0
        $this->assertArrayNotHasKey('proposal', $signedKinds[0] ?? [],
            'Non-proposer C should NOT sign proposal at R0');
        
        // B) prevote exists at R0
        $this->assertArrayHasKey('prevote', $signedKinds[0] ?? [],
            'Non-proposer C MUST sign prevote at R0 after timeout');
        
        // C) prevote target === '' (NIL)
        $prevoteMsgs = array_filter($finalMessages, function($m) {
            return $m->kind === 'prevote' && $m->round === 0;
        });
        $this->assertNotEmpty($prevoteMsgs, 'prevote message should exist');
        foreach ($prevoteMsgs as $msg) {
            $this->assertEquals('', $msg->target, 'prevote target should be empty (NIL)');
        }
        
        // D) precommit exists at R0
        $this->assertArrayHasKey('precommit', $signedKinds[0] ?? [],
            'Non-proposer C MUST sign precommit at R0 after timeout');
        
        // E) precommit target === '' (NIL)
        $precommitMsgs = array_filter($finalMessages, function($m) {
            return $m->kind === 'precommit' && $m->round === 0;
        });
        $this->assertNotEmpty($precommitMsgs, 'precommit message should exist');
        foreach ($precommitMsgs as $msg) {
            $this->assertEquals('', $msg->target, 'precommit target should be empty (NIL)');
        }
        
        // F) Real self-signing at R0 must naturally create C's own R0
        // watermark through the production sign() -> accept() path.
        $watermarks = $this->getRoundWatermarks($driver);
        $this->assertArrayHasKey(
            'C',
            $watermarks,
            'Real C signing must create C self watermark'
        );
        $this->assertSame(
            0,
            $watermarks['C'],
            'Round 0 must be represented as a real watermark value, not absence'
        );

        // Cleanup
        $this->deleteDirectory($baseDir);
    }

    /**
     * Test B: Proposer signs proposal
     * 
     * At height=1, round=0, canonical A/B/C/D ordering:
     * proposer index = (1 + 0) % 4 = 1 = B
     * 
     * So B is the proposer. We use B.
     * 
     * Tick for >0.25s but <1s to isolate proposal behavior from prevote timeout.
     * Expected window: 0.3-0.6 seconds
     */
    public function testProposerSignsProposal(): void
    {
        $baseDir = sys_get_temp_dir() . '/greenwood-proposer-' . bin2hex(random_bytes(6));
        mkdir($baseDir, 0700, true);
        
        // Create genesis
        $genesis = new Genesis('proposer-v1');
        $keys = [];
        foreach (['A', 'B', 'C', 'D'] as $id) {
            $key = Ed25519KeyPair::generate();
            $keys[$id] = $key;
            $genesis->addValidator($id, $key);
        }
        
        // Create chain
        $dir = $baseDir . '/chain';
        mkdir($dir, 0700, true);
        $files = new BlockFilesystem($dir . '/blocks');
        $db = new StateSqlite($dir . '/state.sqlite');
        $chain = new Chain($genesis, $files, $db);
        
        // Create journal for validator B (proposer at round 0, height 1)
        $journalPath = $baseDir . '/journal.sqlite';
        $journal = new SafetyJournal($journalPath, $keys['B'], $genesis->getGenesisHash(), 'B');
        
        // Create driver for B
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$socket) throw new \RuntimeException('Failed to create socket');
        $meta = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = explode(':', $meta)[1];
        
        $driver = new ConsensusDriver($chain, 'B', [], $journal, 'http://127.0.0.1:' . $port, new \Greenwood\Node\ChainConfig(['block_time_seconds' => 1]));
        
        // Verify B IS proposer at round 0, height 1
        $validators = array_keys($genesis->getValidators());
        sort($validators);
        $proposerIndex = ($this->getHeight($driver) + $this->getRound($driver)) % 4;
        $proposerId = $validators[$proposerIndex];
        
        $this->assertEquals('B', $proposerId, 'B should be proposer at height=1, round=0');
        $this->assertEquals('B', $this->getId($driver), 'Validator should be B');
        $this->assertEquals(0, $this->getRound($driver), 'B should start at round 0');
        
        // Get initial messages
        $initialMessages = $journal->messages(1) ?? [];
        $initialCount = count($initialMessages);
        
        echo "\n=== PROPOSER B SIGNING ===\n";
        echo "Starting round: " . $this->getRound($driver) . "\n";
        echo "Initial messages: $initialCount\n";
        
        // Tick for ~1.3 seconds (proposal gate for block_time_seconds=1 is 1.0s).
        // The proposal fires at the 1.0s gate, before the round-0 NIL prevote
        // path (unit=1.0s) becomes relevant for this proposer test.
        $startTime = microtime(true);
        $deadline = $startTime + 1.3;
        
        while (microtime(true) < $deadline) {
            $driver->tick();
            usleep(10000); // 10ms between ticks
        }
        
        // Get final messages
        $finalMessages = $journal->messages(1) ?? [];
        $finalCount = count($finalMessages);
        
        echo "Final round: " . $this->getRound($driver) . "\n";
        echo "Final messages: $finalCount\n";
        
        // Find proposal message
        $proposalMsgs = array_filter($finalMessages, function($m) {
            return $m->kind === 'proposal';
        });
        
        echo "Proposal messages found: " . count($proposalMsgs) . "\n";
        
        foreach ($proposalMsgs as $msg) {
            echo "  validator: {$msg->validator}\n";
            echo "  round: {$msg->round}\n";
            echo "  target: " . bin2hex($msg->target) . "\n";
            echo "  block length: " . strlen($msg->block) . " bytes\n";
        }
        echo "========================\n\n";
        
        // Required assertions
        
        // A) proposal message exists
        $this->assertNotEmpty($proposalMsgs, 'B MUST sign proposal as proposer after 0.25s');
        
        // B) proposal.validator === 'B'
        foreach ($proposalMsgs as $msg) {
            $this->assertEquals('B', $msg->validator, 'Proposal validator should be B');
        }
        
        // C) proposal.round === 0
        foreach ($proposalMsgs as $msg) {
            $this->assertEquals(0, $msg->round, 'Proposal round should be 0');
        }
        
        // D) proposal.target !== '' (non-empty block hash)
        foreach ($proposalMsgs as $msg) {
            $this->assertNotEmpty($msg->target, 'Proposal target should be non-empty block hash');
            $this->assertEquals(32, strlen($msg->target), 'Proposal target should be 32 bytes');
        }
        
        // E) proposal block bytes are non-empty
        foreach ($proposalMsgs as $msg) {
            $this->assertNotEmpty($msg->block, 'Proposal block should be non-empty');
            $this->assertGreaterThan(0, strlen($msg->block), 'Proposal block should have content');
        }
        
        // F) A real proposer signature at R0 must naturally create B's own
        // volatile R0 watermark through the production sign/accept path.
        $watermarks = $this->getRoundWatermarks($driver);
        $this->assertArrayHasKey(
            'B',
            $watermarks,
            'Real B proposal signing must create B self watermark'
        );
        $this->assertSame(
            0,
            $watermarks['B'],
            'B self watermark should record signed round 0'
        );

        // Cleanup
        $this->deleteDirectory($baseDir);
    }

    /**
     * Test C: Large round skew signing trace
     * 
     * Create real drivers with:
     *   A = R31 (proposer for R31: (1+31)%4 = 0 = A)
     *   B = R3  (proposer for R1, R5, R9...)
     *   C = R3  (proposer for R1, R5, R9...)
     *   D = offline (canonical validator in genesis, but no D runtime)
     * 
     * Use reflection to set initial rounds (same pattern as RoundCatchupTest).
     * Use real peer connections so A/B/C can exchange messages.
     * 
     * At R31: unit = 10s, prevote after >10s, precommit after >20s
     * At R3:  unit = 2.5s, prevote after >2.5s, precommit after >5s
     * 
     * Run long enough to see timeout signatures from all validators.
     * We don't need full convergence, just observe what gets signed.
     */
    public function testLargeRoundSkewSigningTrace(): void
    {
        $baseDir = sys_get_temp_dir() . '/greenwood-skew-' . bin2hex(random_bytes(6));
        mkdir($baseDir, 0700, true);
        
        // Create genesis with A, B, C, D
        $genesis = new Genesis('large-skew-v1');
        $keys = [];
        foreach (['A', 'B', 'C', 'D'] as $id) {
            $key = Ed25519KeyPair::generate();
            $keys[$id] = $key;
            $genesis->addValidator($id, $key);
        }
        
        // Create chains for A, B, C (D is offline)
        $chains = [];
        $journals = [];
        $drivers = [];
        $ports = [];
        
        foreach (['A', 'B', 'C'] as $id) {
            $dir = $baseDir . '/chain-' . $id;
            mkdir($dir, 0700, true);
            $files = new BlockFilesystem($dir . '/blocks');
            $db = new StateSqlite($dir . '/state.sqlite');
            $chains[$id] = new Chain($genesis, $files, $db);
            
            $journalPath = $baseDir . '/journal-' . $id . '.sqlite';
            $journals[$id] = new SafetyJournal($journalPath, $keys[$id], $genesis->getGenesisHash(), $id);
            
            // Reserve port
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if (!$socket) {
                throw new \RuntimeException('Failed to create socket for ' . $id);
            }
            $meta = stream_socket_get_name($socket, false);
            fclose($socket);
            $port = explode(':', $meta)[1];
            $ports[$id] = $port;
        }
        
        // Create drivers with peer lists so they can exchange messages
        // D is not in any peer list (offline)
        $peersA = [
            'B' => 'http://127.0.0.1:' . $ports['B'],
            'C' => 'http://127.0.0.1:' . $ports['C']
        ];
        $peersB = [
            'A' => 'http://127.0.0.1:' . $ports['A'],
            'C' => 'http://127.0.0.1:' . $ports['C']
        ];
        $peersC = [
            'A' => 'http://127.0.0.1:' . $ports['A'],
            'B' => 'http://127.0.0.1:' . $ports['B']
        ];
        
        // Create drivers
        $drivers['A'] = new ConsensusDriver(
            $chains['A'],
            'A',
            $peersA,
            $journals['A'],
            'http://127.0.0.1:' . $ports['A'],
            new \Greenwood\Node\ChainConfig(['block_time_seconds' => 1])
        );
        $drivers['B'] = new ConsensusDriver(
            $chains['B'],
            'B',
            $peersB,
            $journals['B'],
            'http://127.0.0.1:' . $ports['B'],
            new \Greenwood\Node\ChainConfig(['block_time_seconds' => 1])
        );
        $drivers['C'] = new ConsensusDriver(
            $chains['C'],
            'C',
            $peersC,
            $journals['C'],
            'http://127.0.0.1:' . $ports['C'],
            new \Greenwood\Node\ChainConfig(['block_time_seconds' => 1])
        );
        
        // Use reflection to set initial rounds (A=31, B=3, C=3)
        // This is the same pattern used in RoundCatchupTest
        $refA = new \ReflectionClass($drivers['A']);
        $propA = $refA->getProperty('round');
        $propA->setAccessible(true);
        $propA->setValue($drivers['A'], 31);
        $propA->setAccessible(false);
        
        $refB = new \ReflectionClass($drivers['B']);
        $propB = $refB->getProperty('round');
        $propB->setAccessible(true);
        $propB->setValue($drivers['B'], 3);
        $propB->setAccessible(false);
        
        $refC = new \ReflectionClass($drivers['C']);
        $propC = $refC->getProperty('round');
        $propC->setAccessible(true);
        $propC->setValue($drivers['C'], 3);
        $propC->setAccessible(false);
        
        // Verify initial rounds before running
        echo "\n=== LARGE SKEW SIGNING TRACE ===\n";
        echo "BEFORE RUN:\n";
        echo "  A.round = " . $this->getRound($drivers['A']) . " (expected: 31)\n";
        echo "  B.round = " . $this->getRound($drivers['B']) . " (expected: 3)\n";
        echo "  C.round = " . $this->getRound($drivers['C']) . " (expected: 3)\n";
        
        // Verify initial state
        $this->assertEquals(31, $this->getRound($drivers['A']), 'A should start at round 31');
        $this->assertEquals(3, $this->getRound($drivers['B']), 'B should start at round 3');
        $this->assertEquals(3, $this->getRound($drivers['C']), 'C should start at round 3');
        
        // Record signed messages with deduplication
        $seenMessages = [];
        $allMessages = [];
        
        $startTime = microtime(true);
        $deadline = $startTime + 25.0; // 25 seconds to cover R31 timeout
        
        // Run until deadline (no iteration limit - just wait for real time)
        $iterations = 0;
        while (microtime(true) < $deadline) {
            $drivers['A']->tick();
            $drivers['B']->tick();
            $drivers['C']->tick();
            $iterations++;
            
            // Capture messages from each validator
            foreach (['A', 'B', 'C'] as $id) {
                $driver = $drivers[$id];
                $journal = $journals[$id];
                $height = $this->getHeight($driver);
                
                $messages = $journal->messages($height) ?? [];
                
                foreach ($messages as $msg) {
                    // Deduplicate: validator:round:kind:target
                    $key = $msg->validator . ':' . $msg->round . ':' . $msg->kind . ':' . bin2hex($msg->target);
                    
                    if (!isset($seenMessages[$key])) {
                        $seenMessages[$key] = true;
                        $allMessages[] = [
                            'validator' => $msg->validator,
                            'round' => $msg->round,
                            'kind' => $msg->kind,
                            'target' => bin2hex($msg->target),
                        ];
                    }
                }
            }
            
            // Small sleep to avoid spinning too fast
            usleep(10000); // 10ms between ticks
        }
        
        $elapsed = round(microtime(true) - $startTime, 2);
        
        // Record final state
        $finalA = $this->getRound($drivers['A']);
        $finalB = $this->getRound($drivers['B']);
        $finalC = $this->getRound($drivers['C']);
        
        echo "\nAFTER RUN:\n";
        echo "  A.round = $finalA\n";
        echo "  B.round = $finalB\n";
        echo "  C.round = $finalC\n";
        echo "Iterations: $iterations\n";
        echo "Time elapsed: {$elapsed}s\n";
        echo "Total unique messages: " . count($allMessages) . "\n\n";
        
        // Analyze by validator
        $byValidator = [];
        foreach ($allMessages as $msg) {
            $v = $msg['validator'];
            $r = $msg['round'];
            $k = $msg['kind'];
            
            if (!isset($byValidator[$v])) {
                $byValidator[$v] = [];
            }
            if (!isset($byValidator[$v][$r])) {
                $byValidator[$v][$r] = [];
            }
            $byValidator[$v][$r][$k] = true;
        }
        
        echo "Signed rounds by validator:\n";
        foreach (['A', 'B', 'C'] as $id) {
            if (isset($byValidator[$id])) {
                $rounds = array_keys($byValidator[$id]);
                sort($rounds);
                echo "  $id: R" . implode(', R', $rounds) . "\n";
                foreach ($rounds as $r) {
                    $kinds = array_keys($byValidator[$id][$r]);
                    echo "    R$r: " . implode(', ', $kinds) . "\n";
                }
            } else {
                echo "  $id: no signatures\n";
            }
        }
        echo "\n================================\n\n";
        
        // Required assertions for large skew trace
        
        // A should have at least one signed message at R31
        $aHasR31 = isset($byValidator['A'][31]);
        $this->assertTrue($aHasR31, 'Validator A MUST have signed at least one message at R31');
        
        // B should have at least one signed message at R3 or higher
        $bHasR3 = isset($byValidator['B'][3]);
        $bHasHigher = false;
        foreach (array_keys($byValidator['B'] ?? []) as $r) {
            if ($r >= 3) {
                $bHasHigher = true;
                break;
            }
        }
        $this->assertTrue($bHasR3 || $bHasHigher, 'Validator B MUST have signed at least one message');
        
        // C should have at least one signed message at R3 or higher
        $cHasR3 = isset($byValidator['C'][3]);
        $cHasHigher = false;
        foreach (array_keys($byValidator['C'] ?? []) as $r) {
            if ($r >= 3) {
                $cHasHigher = true;
                break;
            }
        }
        $this->assertTrue($cHasR3 || $cHasHigher, 'Validator C MUST have signed at least one message');
        
        // Cleanup
        $this->deleteDirectory($baseDir);
    }
    
    private function deleteDirectory(string $dir): void
    {
        if (!file_exists($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
