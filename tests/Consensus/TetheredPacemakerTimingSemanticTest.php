<?php

declare(strict_types=1);

namespace Greenwood\Tests\Support;

use PHPUnit\Framework\TestCase;

final class TetheredPacemakerTimingSemanticTest extends TestCase
{
    public function testNormalTimeoutRound0(): void
    {
        $model = new TetheredPacemakerModel(['A' => 0], []);
        $timeout = $model->normalTimeoutForRound(0);
        $this->assertEquals(3.0, $timeout);
    }

    public function testNormalTimeoutRound3(): void
    {
        $model = new TetheredPacemakerModel(['A' => 3], []);
        $timeout = $model->normalTimeoutForRound(3);
        $this->assertEquals(7.5, $timeout);
    }

    public function testNormalTimeoutRound4(): void
    {
        $model = new TetheredPacemakerModel(['A' => 4], []);
        $timeout = $model->normalTimeoutForRound(4);
        $this->assertEquals(9.0, $timeout);
    }

    public function testNormalTimeoutRound10(): void
    {
        $model = new TetheredPacemakerModel(['A' => 10], []);
        $timeout = $model->normalTimeoutForRound(10);
        $this->assertEquals(18.0, $timeout);
    }

    public function testNormalTimeoutRound18(): void
    {
        $model = new TetheredPacemakerModel(['A' => 18], []);
        $timeout = $model->normalTimeoutForRound(18);
        $this->assertEquals(30.0, $timeout);
    }

    public function testNormalTimeoutRound31(): void
    {
        $model = new TetheredPacemakerModel(['A' => 31], []);
        $timeout = $model->normalTimeoutForRound(31);
        $this->assertEquals(30.0, $timeout);
    }

    public function testBrakeDoesNotActivateWithNullFloor(): void
    {
        // A has only ONE canonical watermark key (A@10), so confirmedFloor is null
        $model = new TetheredPacemakerModel(
            ['A' => 10, 'B' => 3, 'C' => 3],
            [
                'A' => [
                    'A' => 10,
                ],
            ]
        );
        
        $decision = $model->timeoutDecisionForValidator('A');
        
        $this->assertNull($model->getValidator('A')->confirmedFloor);
        $this->assertEquals(18.0, $decision['effective']);
        $this->assertFalse($decision['brakeActivated']);
        $this->assertNull($decision['gap']);
        $this->assertNull($decision['candidate']);
    }

    public function testBrakeDoesNotActivateWhenLocalEqualsFloor(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 10, 'B' => 10, 'C' => 3],
            [
                'A' => ['A' => 10, 'B' => 10, 'C' => 3],
            ]
        );
        
        $decision = $model->timeoutDecisionForValidator('A');
        
        $this->assertEquals(10, $model->getValidator('A')->confirmedFloor);
        $this->assertEquals(10, $model->getValidator('A')->localRound);
        $this->assertEquals(18.0, $decision['effective']);
        $this->assertFalse($decision['brakeActivated']);
    }

    public function testBrakeDoesNotActivateWhenLocalBelowFloor(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 10, 'B' => 12, 'C' => 12],
            [
                'A' => ['A' => 10, 'B' => 12, 'C' => 12],
            ]
        );
        
        $decision = $model->timeoutDecisionForValidator('A');
        
        $this->assertEquals(12, $model->getValidator('A')->confirmedFloor);
        $this->assertEquals(10, $model->getValidator('A')->localRound);
        $this->assertEquals(18.0, $decision['effective']);
        $this->assertFalse($decision['brakeActivated']);
    }

    public function testBrakeActivatesAgainstConfirmedLowerFloor(): void
    {
        // A has TWO canonical watermark keys (A@10, B@3), establishing confirmedFloor=3
        $model = new TetheredPacemakerModel(
            ['A' => 10, 'B' => 3, 'C' => 3],
            [
                'A' => [
                    'A' => 10,
                    'B' => 3,
                ],
            ]
        );
        $model->withBrake(true, 120.0);
        
        $decision = $model->timeoutDecisionForValidator('A');
        
        $this->assertEquals(3, $model->getValidator('A')->confirmedFloor);
        $this->assertEquals(10, $model->getValidator('A')->localRound);
        $this->assertEquals(7, $decision['gap']);
        $this->assertEquals(18.0, $decision['normal']);
        $this->assertEquals(81.0, $decision['candidate']);
        $this->assertEquals(81.0, $decision['effective']);
        $this->assertTrue($decision['brakeActivated']);
    }

    public function testBrakeUsesExpectedFormula(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 10, 'B' => 3, 'C' => 3],
            [
                'A' => ['A' => 10, 'B' => 3, 'C' => 3],
            ]
        );
        $model->withBrake(true, 120.0);
        
        $decision = $model->timeoutDecisionForValidator('A');
        
        $this->assertEquals(7, $decision['gap']);
        $this->assertEquals(18.0, $decision['normal']);
        $this->assertEquals(81.0, $decision['candidate']);
        $this->assertEquals(81.0, $decision['effective']);
        $this->assertTrue($decision['brakeActivated']);
    }

    public function testBrakeMaxCannotBeBelowNormalTimeout(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 10, 'B' => 3, 'C' => 3],
            [
                'A' => ['A' => 10, 'B' => 3, 'C' => 3],
            ]
        );
        $model->withBrake(true, 10.0);
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('brakeMax');
        
        $model->timeoutDecisionForValidator('A');
    }

    public function testHandleTimeoutUsesSameBrakeDecision(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 10, 'B' => 3, 'C' => 3],
            [
                'A' => ['A' => 10, 'B' => 3, 'C' => 3],
            ]
        );
        $model->withBrake(true, 120.0);
        $model->withFastTimeout(false);
        
        // Trigger exactly one A timeout
        $model->run(1, 100.0);
        
        $aValidator = $model->getValidator('A');
        $metrics = $model->getMetrics();
        
        // The effective timeout used for the event was 81
        $this->assertEquals(81.0, $aValidator->effectiveTimeout);
        // brakeActivationCount increased by exactly 1
        $this->assertEquals(1, $metrics['brakeActivationCount']);
        // localRound may have advanced
        $this->assertGreaterThanOrEqual(11, $aValidator->localRound);
    }
}