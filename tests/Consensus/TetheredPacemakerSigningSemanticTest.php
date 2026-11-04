<?php

declare(strict_types=1);

namespace Greenwood\Tests\Support;

use PHPUnit\Framework\TestCase;

final class TetheredPacemakerSigningSemanticTest extends TestCase
{
    public function testInitialRoundDoesNotCreateSignedRound(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 3],
            []
        );

        $snapshot = $model->getStateSnapshot();
        $aValidator = $model->getValidator('A');

        $this->assertEquals(3, $aValidator->localRound);
        $this->assertEquals([], $aValidator->signedRounds);
        $this->assertArrayNotHasKey('A', $aValidator->watermarks);
    }

    public function testInitialRoundDoesNotCreateSelfWatermark(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 10],
            []
        );

        $snapshot = $model->getStateSnapshot();
        $aValidator = $model->getValidator('A');

        $this->assertEquals(10, $aValidator->localRound);
        $this->assertArrayNotHasKey('A', $aValidator->watermarks);
    }

    public function testPacemakerMovementDoesNotCreateSignedRound(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 3, 'B' => 3, 'C' => 3],
            []
        );

        $aValidator = $model->getValidator('A');
        $initialSignedRounds = $aValidator->signedRounds;

        $model->run(500000, 100000.0);

        $finalSignedRounds = $aValidator->signedRounds;

        $this->assertEquals($initialSignedRounds, $finalSignedRounds);
    }

    public function testPacemakerMovementDoesNotCreateSelfWatermark(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 3, 'B' => 3, 'C' => 3],
            []
        );

        $aValidator = $model->getValidator('A');
        $hasInitialSelfWatermark = array_key_exists('A', $aValidator->watermarks);

        $model->run(500000, 100000.0);

        $hasFinalSelfWatermark = array_key_exists('A', $aValidator->watermarks);

        $this->assertFalse($hasInitialSelfWatermark);
        $this->assertFalse($hasFinalSelfWatermark);
    }

    public function testExplicitSigningCreatesSignedRound(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 3],
            []
        );

        $aValidator = $model->getValidator('A');
        $aValidator->recordSignedRound(17);

        $this->assertEquals([17 => true], $aValidator->signedRounds);
    }

    public function testExplicitSigningCreatesSelfWatermark(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 3],
            []
        );

        $aValidator = $model->getValidator('A');
        $aValidator->recordSignedRound(17);

        $this->assertEquals(17, $aValidator->watermarks['A']);
    }

    public function testExplicitSigningIsMonotonic(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 3],
            []
        );

        $aValidator = $model->getValidator('A');
        $aValidator->recordSignedRound(17);
        $aValidator->recordSignedRound(10);

        $this->assertEquals(17, $aValidator->watermarks['A']);
    }

    public function testExplicitSigningRoundZeroIsRecorded(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 0],
            []
        );

        $aValidator = $model->getValidator('A');
        $aValidator->recordSignedRound(0);

        $this->assertEquals([0 => true], $aValidator->signedRounds);
        $this->assertEquals(0, $aValidator->watermarks['A']);
    }
}