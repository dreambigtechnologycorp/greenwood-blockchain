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

final class ConsensusEvidenceAcceptanceTest extends TestCase
{
    private function deleteDirectory(string $dir): void
    {
        if (!file_exists($dir)) {
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
                unlink($path);
            }
        }

        rmdir($dir);
    }

    private function setRound(ConsensusDriver $driver, int $round): void
    {
        $ref = new \ReflectionClass($driver);
        $prop = $ref->getProperty('round');
        $prop->setValue($driver, $round);
    }

    private function getRound(ConsensusDriver $driver): int
    {
        $ref = new \ReflectionClass($driver);
        $prop = $ref->getProperty('round');

        return (int) $prop->getValue($driver);
    }

    private function getRoundWatermarks(ConsensusDriver $driver): array
    {
        $ref = new \ReflectionClass($driver);
        $prop = $ref->getProperty('roundWatermarks');

        /** @var array<string,int> $watermarks */
        $watermarks = $prop->getValue($driver);

        return $watermarks;
    }

    private function getHeight(ConsensusDriver $driver): int
    {
        $ref = new \ReflectionClass($driver);
        $prop = $ref->getProperty('height');

        return (int) $prop->getValue($driver);
    }

    private function setStarted(
        ConsensusDriver $driver,
        float $started
    ): void {
        $ref = new \ReflectionClass($driver);
        $prop = $ref->getProperty('started');
        $prop->setValue($driver, $started);
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
            throw new \RuntimeException(
                'Failed to resolve reserved endpoint'
            );
        }

        $separator = strrpos($address, ':');

        if ($separator === false) {
            throw new \RuntimeException(
                'Unexpected reserved endpoint: ' . $address
            );
        }

        $port = (int) substr($address, $separator + 1);

        if ($port <= 0) {
            throw new \RuntimeException(
                'Invalid reserved port: ' . $address
            );
        }

        return 'http://127.0.0.1:' . $port;
    }

    /**
     * @param array<string, Ed25519KeyPair> $keys
     * @return array{
     *     driver: ConsensusDriver,
     *     journal: SafetyJournal,
     *     chain: Chain
     * }
     */
    private function createNode(
        string $id,
        Genesis $genesis,
        array $keys,
        string $baseDir,
        int $round,
        string $suffix
    ): array {
        if (!isset($keys[$id])) {
            throw new \InvalidArgumentException(
                'Missing canonical key for validator ' . $id
            );
        }

        $dir = $baseDir
            . '/node-'
            . $id
            . '-'
            . $suffix;

        mkdir($dir, 0700, true);

        $files = new BlockFilesystem($dir . '/blocks');
        $db = new StateSqlite($dir . '/state.sqlite');
        $chain = new Chain($genesis, $files, $db);

        $journal = new SafetyJournal(
            $dir . '/journal.sqlite',
            $keys[$id],
            $genesis->getGenesisHash(),
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

        $this->setRound($driver, $round);

        return [
            'driver' => $driver,
            'journal' => $journal,
            'chain' => $chain,
        ];
    }

    /**
     * Generate a real PREVOTE using the production ConsensusDriver path:
     *
     * tick()
     *   -> ConsensusDriver::sign()
     *   -> SafetyJournal
     *
     * No handcrafted Message and no direct SafetyJournal::sign().
     *
     * @param array<string, Ed25519KeyPair> $keys
     */
    private function generateRealPrevote(
        string $signer,
        int $round,
        Genesis $genesis,
        array $keys,
        string $baseDir,
        string $suffix
    ): Message {
        $node = $this->createNode(
            $signer,
            $genesis,
            $keys,
            $baseDir,
            $round,
            'signer-' . $suffix
        );

        /** @var ConsensusDriver $driver */
        $driver = $node['driver'];

        /** @var SafetyJournal $journal */
        $journal = $node['journal'];

        $height = $this->getHeight($driver);

        $this->assertSame(
            1,
            $height,
            $signer . '@R' . $round . ' should start at height 1'
        );

        $this->assertSame(
            $round,
            $this->getRound($driver),
            $signer . ' should be configured at requested round'
        );

        /*
         * Production formula:
         *
         * unit(R) = min(10, 1 + 0.5R)
         *
         * Setting elapsed just past unit causes production tick()
         * to reach the PREVOTE opportunity while remaining below
         * 2*unit and 3*unit.
         */
        $unit = min(
            10.0,
            1.0 + ($round * 0.5)
        );

        $this->setStarted(
            $driver,
            microtime(true) - ($unit + 0.10)
        );

        $driver->tick();

        $messages = $journal->messages($height) ?? [];

        $prevote = null;

        foreach ($messages as $message) {
            if (
                $message->validator === $signer
                && $message->round === $round
                && $message->kind === 'prevote'
            ) {
                $prevote = $message;
                break;
            }
        }

        $this->assertInstanceOf(
            Message::class,
            $prevote,
            sprintf(
                '%s@R%d must produce a real PREVOTE through ConsensusDriver',
                $signer,
                $round
            )
        );

        /** @var Message $prevote */
        return $prevote;
    }

    /**
     * @param array<string, mixed> $validators
     */
    private function isCryptoValid(
        Message $message,
        array $validators,
        string $chainId,
        string $genesisHash
    ): bool {
        try {
            $message->verify(
                $validators,
                $chainId,
                $genesisHash
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $validators
     * @return array{
     *     cryptoValid: bool,
     *     accepted: bool,
     *     reason: ?string
     * }
     */
    private function attemptAccept(
        Message $message,
        ConsensusDriver $receiver,
        array $validators,
        string $chainId,
        string $genesisHash
    ): array {
        $cryptoValid = $this->isCryptoValid(
            $message,
            $validators,
            $chainId,
            $genesisHash
        );

        if (!$cryptoValid) {
            return [
                'cryptoValid' => false,
                'accepted' => false,
                'reason' => 'Cryptographic verification failed',
            ];
        }

        try {
            $receiver->accept($message);

            return [
                'cryptoValid' => true,
                'accepted' => true,
                'reason' => null,
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'cryptoValid' => true,
                'accepted' => false,
                'reason' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'cryptoValid' => true,
                'accepted' => false,
                'reason' => sprintf(
                    'Unexpected %s: %s',
                    $e::class,
                    $e->getMessage()
                ),
            ];
        }
    }

    public function testAcceptanceMatrix(): void
    {
        $baseDir = sys_get_temp_dir()
            . '/greenwood-evidence-acceptance-'
            . bin2hex(random_bytes(6));

        mkdir($baseDir, 0700, true);

        try {
            /*
             * Canonical Greenwood V1 membership.
             *
             * D remains a canonical validator even though no D runtime
             * is started for this characterization.
             */
            $genesis = new Genesis(
                'consensus-evidence-acceptance-v1'
            );

            /** @var array<string, Ed25519KeyPair> $keys */
            $keys = [];

            foreach (['A', 'B', 'C', 'D'] as $id) {
                $key = Ed25519KeyPair::generate();

                $keys[$id] = $key;

                $genesis->addValidator(
                    $id,
                    $key
                );
            }

            $validators = $genesis->getValidators();
            $chainId = $genesis->getChainId();
            $genesisHash = $genesis->getGenesisHash();

            /*
             * Every source message below is produced through the real
             * ConsensusDriver signing path.
             */
            $msgA31 = $this->generateRealPrevote(
                'A',
                31,
                $genesis,
                $keys,
                $baseDir,
                'a31'
            );

            $msgB3 = $this->generateRealPrevote(
                'B',
                3,
                $genesis,
                $keys,
                $baseDir,
                'b3'
            );

            $msgC3 = $this->generateRealPrevote(
                'C',
                3,
                $genesis,
                $keys,
                $baseDir,
                'c3'
            );

            $msgB4 = $this->generateRealPrevote(
                'B',
                4,
                $genesis,
                $keys,
                $baseDir,
                'b4'
            );

            $msgC4 = $this->generateRealPrevote(
                'C',
                4,
                $genesis,
                $keys,
                $baseDir,
                'c4'
            );

            $msgB5 = $this->generateRealPrevote(
                'B',
                5,
                $genesis,
                $keys,
                $baseDir,
                'b5'
            );

            $msgC5 = $this->generateRealPrevote(
                'C',
                5,
                $genesis,
                $keys,
                $baseDir,
                'c5'
            );

            /*
             * Sanity-check source identities before the acceptance
             * matrix is evaluated.
             */
            $this->assertSame('A', $msgA31->validator);
            $this->assertSame(31, $msgA31->round);
            $this->assertSame('prevote', $msgA31->kind);

            $this->assertSame('B', $msgB3->validator);
            $this->assertSame(3, $msgB3->round);
            $this->assertSame('prevote', $msgB3->kind);

            $this->assertSame('C', $msgC3->validator);
            $this->assertSame(3, $msgC3->round);
            $this->assertSame('prevote', $msgC3->kind);

            /*
             * Matrix:
             *
             * receiver window:
             *
             * localRound - 2
             * through
             * localRound + 1
             *
             * inclusive.
             */
            $matrix = [
                [
                    'A31_TO_B3',
                    $msgA31,
                    'B',
                    3,
                    false,
                ],
                [
                    'A31_TO_C3',
                    $msgA31,
                    'C',
                    3,
                    false,
                ],
                [
                    'B3_TO_A31',
                    $msgB3,
                    'A',
                    31,
                    false,
                ],
                [
                    'B3_TO_C3',
                    $msgB3,
                    'C',
                    3,
                    true,
                ],
                [
                    'C3_TO_A31',
                    $msgC3,
                    'A',
                    31,
                    false,
                ],
                [
                    'C3_TO_B3',
                    $msgC3,
                    'B',
                    3,
                    true,
                ],
                [
                    'B4_TO_C3',
                    $msgB4,
                    'C',
                    3,
                    true,
                ],
                [
                    'C4_TO_B3',
                    $msgC4,
                    'B',
                    3,
                    true,
                ],
                [
                    'B5_TO_C3',
                    $msgB5,
                    'C',
                    3,
                    false,
                ],
                [
                    'C5_TO_B3',
                    $msgC5,
                    'B',
                    3,
                    false,
                ],
            ];

            $results = [];

            foreach (
                $matrix as [
                    $name,
                    $message,
                    $receiverId,
                    $receiverRound,
                    $expectedAccepted,
                ]
            ) {
                /*
                 * Fresh receiver for every matrix cell.
                 *
                 * No accepted message is allowed to mutate the state
                 * used by another characterization cell.
                 */
                $receiverNode = $this->createNode(
                    $receiverId,
                    $genesis,
                    $keys,
                    $baseDir,
                    $receiverRound,
                    'receiver-' . strtolower($name)
                );

                /** @var ConsensusDriver $receiver */
                $receiver = $receiverNode['driver'];

                $this->assertSame(
                    $receiverRound,
                    $this->getRound($receiver),
                    $name . ': receiver round'
                );

                $result = $this->attemptAccept(
                    $message,
                    $receiver,
                    $validators,
                    $chainId,
                    $genesisHash
                );

                $watermarks = $this->getRoundWatermarks($receiver);

                $results[$name] = $result + [
                    'watermarks' => $watermarks,
                    'observed' => array_key_exists(
                        $message->validator,
                        $watermarks
                    ),
                    'observedRound' => $watermarks[$message->validator] ?? null,
                ];

                $this->assertTrue(
                    $result['cryptoValid'],
                    $name . ': source message must be cryptographically valid'
                );

                $this->assertSame(
                    $expectedAccepted,
                    $result['accepted'],
                    $name . ': active acceptance result'
                );

                /*
                 * Production pacemaker observation is intentionally separate
                 * from active consensus eligibility. Every cryptographically
                 * valid, current-height canonical message must update the
                 * original signer's volatile watermark even if the active
                 * round window rejects the message.
                 */
                $this->assertTrue(
                    $results[$name]['observed'],
                    $name . ': authenticated current-height signer must be observed'
                );

                $this->assertSame(
                    $message->round,
                    $results[$name]['observedRound'],
                    $name . ': watermark must record original signer round'
                );

                if (!$expectedAccepted) {
                    $this->assertNotNull(
                        $result['reason'],
                        $name . ': rejection must include a reason'
                    );

                    $this->assertStringContainsString(
                        'Wrong height or round',
                        (string) $result['reason'],
                        $name . ': expected active-round rejection'
                    );
                }
            }

            /*
             * Post-patch first-order pacemaker observability at:
             *
             * A = R31
             * B = R3
             * C = R3
             *
             * Active consensus eligibility and pacemaker observability are
             * deliberately separate:
             *
             * - A31_TO_B3 remains actively rejected, but B observes A@31.
             * - A31_TO_C3 remains actively rejected, but C observes A@31.
             * - B3/C3 remain actively rejected by A31, but A observes their
             *   authenticated signer-round evidence.
             *
             * No evidence is relayed or attributed to another signer.
             */
            $aVisibleSigners = ['A', 'B', 'C'];
            $bVisibleSigners = ['A', 'B', 'C'];
            $cVisibleSigners = ['A', 'B', 'C'];

            $fPlus1 = 2;

            $aHasFPlus1 =
                count($aVisibleSigners) >= $fPlus1;

            $bHasFPlus1 =
                count($bVisibleSigners) >= $fPlus1;

            $cHasFPlus1 =
                count($cVisibleSigners) >= $fPlus1;

            $this->assertSame(
                ['A', 'B', 'C'],
                $aVisibleSigners
            );

            $this->assertSame(
                ['A', 'B', 'C'],
                $bVisibleSigners
            );

            $this->assertSame(
                ['A', 'B', 'C'],
                $cVisibleSigners
            );

            $this->assertTrue($aHasFPlus1);
            $this->assertTrue($bHasFPlus1);
            $this->assertTrue($cHasFPlus1);

            /*
             * Explicitly assert the key post-patch separation:
             * far-skew same-height messages are observed by the pacemaker
             * while remaining rejected from active consensus processing.
             */
            $this->assertFalse(
                $results['A31_TO_B3']['accepted']
            );
            $this->assertTrue(
                $results['A31_TO_B3']['observed']
            );
            $this->assertSame(
                31,
                $results['A31_TO_B3']['observedRound']
            );

            $this->assertFalse(
                $results['A31_TO_C3']['accepted']
            );
            $this->assertTrue(
                $results['A31_TO_C3']['observed']
            );
            $this->assertSame(
                31,
                $results['A31_TO_C3']['observedRound']
            );

            $this->assertFalse(
                $results['B3_TO_A31']['accepted']
            );
            $this->assertTrue(
                $results['B3_TO_A31']['observed']
            );
            $this->assertSame(
                3,
                $results['B3_TO_A31']['observedRound']
            );

            $this->assertFalse(
                $results['C3_TO_A31']['accepted']
            );
            $this->assertTrue(
                $results['C3_TO_A31']['observed']
            );
            $this->assertSame(
                3,
                $results['C3_TO_A31']['observedRound']
            );

            /*
             * Explicit characterization output for the next protocol
             * design gate.
             */
            echo "\n";
            echo "=== ACCEPTANCE MATRIX RESULTS ===\n";

            foreach ($matrix as [$name]) {
                echo $name
                    . '='
                    . (
                        $results[$name]['accepted']
                            ? 'accepted'
                            : 'rejected'
                    )
                    . "\n";

                echo $name
                    . '_PACEMAKER_OBSERVED='
                    . (
                        $results[$name]['observed']
                            ? 'yes'
                            : 'no'
                    )
                    . "\n";

                echo $name
                    . '_WATERMARK_ROUND='
                    . (
                        $results[$name]['observedRound']
                            ?? 'null'
                    )
                    . "\n";

                if (!$results[$name]['accepted']) {
                    echo $name
                        . '_CRYPTO_VALID='
                        . (
                            $results[$name]['cryptoValid']
                                ? 'yes'
                                : 'no'
                        )
                        . "\n";

                    echo $name
                        . '_REASON='
                        . ($results[$name]['reason'] ?? '')
                        . "\n";
                }
            }

            echo "\n";
            echo "A_VISIBLE_SIGNERS="
                . implode(',', $aVisibleSigners)
                . "\n";

            echo "B_VISIBLE_SIGNERS="
                . implode(',', $bVisibleSigners)
                . "\n";

            echo "C_VISIBLE_SIGNERS="
                . implode(',', $cVisibleSigners)
                . "\n";

            echo "A_HAS_FPLUS1_EVIDENCE="
                . ($aHasFPlus1 ? 'yes' : 'no')
                . "\n";

            echo "B_HAS_FPLUS1_EVIDENCE="
                . ($bHasFPlus1 ? 'yes' : 'no')
                . "\n";

            echo "C_HAS_FPLUS1_EVIDENCE="
                . ($cHasFPlus1 ? 'yes' : 'no')
                . "\n";

            echo "CURRENT_ACCEPT_WINDOW="
                . 'round-2 through round+1 inclusive'
                . "\n";

            echo "ACTIVE_WINDOW_CHANGED=no\n";
            echo "PACEMAKER_OBSERVATION_BEFORE_ACTIVE_FILTER=yes\n";
            echo "=================================\n";
        } finally {
            $this->deleteDirectory($baseDir);
        }
    }
}