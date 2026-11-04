<?php

declare(strict_types=1);

namespace Greenwood\Tests\Consensus;

use PHPUnit\Framework\TestCase;

final class TetheredPacemakerEvidenceTransportTest extends TestCase
{
    public function testPacemakerMovementCreatesNoPeerWatermark(): void
    {
        $model = new \Greenwood\Tests\Support\TetheredPacemakerModel(
            ['A' => 3, 'B' => 3, 'C' => 3],
            [
                'A' => [],
                'B' => [],
                'C' => [],
            ]
        );
        $model->withBrake(false);
        $model->run(1, 100.0);
        
        $this->assertEquals(4, $model->getValidator('A')->localRound);
        $this->assertArrayNotHasKey('A', $model->getValidator('B')->watermarks);
        $this->assertArrayNotHasKey('A', $model->getValidator('C')->watermarks);
        // Pacemaker movement does NOT create self watermark
        $this->assertArrayNotHasKey('A', $model->getValidator('A')->watermarks);
    }

    public function testExplicitSigningCreatesOnlyLocalEvidence(): void
    {
        $model = new \Greenwood\Tests\Support\TetheredPacemakerModel(
            ['A' => 3, 'B' => 3, 'C' => 3],
            [
                'A' => [],
                'B' => [],
                'C' => [],
            ]
        );
        
        $model->getValidator('A')->recordSignedRound(4);
        
        $this->assertTrue($model->getValidator('A')->signedRounds[4]);
        $this->assertEquals(4, $model->getValidator('A')->watermarks['A']);
        $this->assertArrayNotHasKey('A', $model->getValidator('B')->watermarks);
        $this->assertArrayNotHasKey('A', $model->getValidator('C')->watermarks);
    }

    public function testUnsignedRoundCannotBeDeliveredAsAuthenticatedEvidence(): void
    {
        $model = new \Greenwood\Tests\Support\TetheredPacemakerModel(
            ['A' => 3, 'B' => 3, 'C' => 3],
            [
                'A' => [],
                'B' => [],
                'C' => [],
            ]
        );
        
        $this->assertFalse(isset($model->getValidator('A')->signedRounds[4]));
        $model->deliverSignedRound('A', 'C', 4);
        $this->assertArrayNotHasKey('A', $model->getValidator('C')->watermarks);
    }

    public function testSignedRoundCanBeDeliveredToOnePeer(): void
    {
        $model = new \Greenwood\Tests\Support\TetheredPacemakerModel(
            ['A' => 3, 'B' => 3, 'C' => 3],
            [
                'A' => [],
                'B' => [],
                'C' => [],
            ]
        );
        
        $model->getValidator('A')->recordSignedRound(4);
        $model->deliverSignedRound('A', 'B', 4);
        
        $this->assertEquals(4, $model->getValidator('B')->watermarks['A']);
        $this->assertArrayNotHasKey('A', $model->getValidator('C')->watermarks);
    }

    public function testSignedRoundDeliveryDoesNotPropagateToOtherPeers(): void
    {
        $model = new \Greenwood\Tests\Support\TetheredPacemakerModel(
            ['A' => 3, 'B' => 3, 'C' => 3],
            [
                'A' => [],
                'B' => [],
                'C' => [],
            ]
        );
        
        $model->getValidator('A')->recordSignedRound(4);
        $model->deliverSignedRound('A', 'B', 4);
        
        $this->assertEquals(4, $model->getValidator('B')->watermarks['A']);
        $this->assertArrayNotHasKey('A', $model->getValidator('C')->watermarks);
        $this->assertFalse(isset($model->getValidator('B')->signedRounds[4]));
        $this->assertArrayNotHasKey('B', $model->getValidator('C')->watermarks);
    }


    public function testSignedRoundZeroCanBeDelivered(): void
    {
        $model = new \Greenwood\Tests\Support\TetheredPacemakerModel(
            ['A' => 0, 'B' => 0, 'C' => 0],
            [
                'A' => [],
                'B' => [],
                'C' => [],
            ]
        );
        
        $model->getValidator('A')->recordSignedRound(0);
        $model->deliverSignedRound('A', 'B', 0);
        
        $this->assertEquals(0, $model->getValidator('B')->watermarks['A']);
    }


    public function testRecipientWatermarkIsMonotonic(): void
    {
        $model = new \Greenwood\Tests\Support\TetheredPacemakerModel(
            ['A' => 3, 'B' => 3, 'C' => 3],
            [
                'A' => [],
                'B' => [],
                'C' => [],
            ]
        );
        
        // A signs R4
        $model->getValidator('A')->recordSignedRound(4);
        $model->deliverSignedRound('A', 'B', 4);
        $this->assertEquals(4, $model->getValidator('B')->watermarks['A']);
        
        // A explicitly signs R2 (lower, should not regress watermark)
        $model->getValidator('A')->recordSignedRound(2);
        $model->deliverSignedRound('A', 'B', 2);
        $this->assertEquals(4, $model->getValidator('B')->watermarks['A']);
        
        // A explicitly signs R6 (higher, should advance watermark)
        $model->getValidator('A')->recordSignedRound(6);
        $model->deliverSignedRound('A', 'B', 6);
        $this->assertEquals(6, $model->getValidator('B')->watermarks['A']);
        
        // Verify A's signedRounds contains all three
        $this->assertTrue($model->getValidator('A')->signedRounds[2]);
        $this->assertTrue($model->getValidator('A')->signedRounds[4]);
        $this->assertTrue($model->getValidator('A')->signedRounds[6]);
        
        // Verify A's own watermark is monotonic (after signing R2, then R6)
        $this->assertEquals(6, $model->getValidator('A')->watermarks['A']);
    }
    public function testReceivedEvidenceDoesNotCreateRecipientSignedRound(): void
    {
        $model = new \Greenwood\Tests\Support\TetheredPacemakerModel(
            ['A' => 3, 'B' => 3, 'C' => 3],
            [
                'A' => [],
                'B' => [],
                'C' => [],
            ]
        );
        
        $model->getValidator('A')->recordSignedRound(4);
        $model->deliverSignedRound('A', 'B', 4);
        
        $this->assertEquals(4, $model->getValidator('B')->watermarks['A']);
        $this->assertFalse(isset($model->getValidator('B')->signedRounds[4]));
    }
}
