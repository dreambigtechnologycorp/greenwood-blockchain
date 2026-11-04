<?php
declare(strict_types=1);
namespace Greenwood\Tests\Support;
use PHPUnit\Framework\TestCase;

final class TetheredPacemakerModelTest extends TestCase
{
    public function testThreeHonestValidatorsConverge(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 5, 'B' => 5, 'C' => 5],
            [],
            30.0,
            0.5
        );
        
        $model->run(500000, 100000.0);
        
        $metrics = $model->getMetrics();
        $this->assertArrayHasKey('fullRoundConvergence', $metrics);
        $this->assertTrue($metrics['recoveryWindowEntered'], 'Should enter recovery window');
        $this->assertLessThanOrEqual(1, $metrics['finalGap'], 'Final gap should be at most 1');
    }
    
    public function testBrakeActivationIncreasesTimeout(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 10, 'B' => 3, 'C' => 3],
            ['A' => ['B' => 3, 'C' => 3]],
            30.0,
            0.5
        );
        
        $model->withBrake(true, 120.0);
        $model->run(500000, 100000.0);
        
        $metrics = $model->getMetrics();
        $this->assertGreaterThan(0, $metrics['brakeActivationCount']);
    }
    
    public function testFastTimeoutEnabledWhenProposerAhead(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 3, 'B' => 3, 'C' => 3],
            ['A' => ['B' => 5, 'C' => 5]],
            30.0,
            0.5
        );
        
        $model->withFastTimeout(true);
        $model->run(500000, 100000.0);
        
        $metrics = $model->getMetrics();
        $this->assertGreaterThan(0, $metrics['fastTimeoutCount']);
    }
    
    public function testGetStateSnapshot(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 5, 'B' => 5, 'C' => 5]
        );
        
        $snapshot = $model->getStateSnapshot();
        
        $this->assertArrayHasKey('simulatedTime', $snapshot);
        $this->assertArrayHasKey('eventCount', $snapshot);
        $this->assertArrayHasKey('rounds', $snapshot);
        $this->assertArrayHasKey('watermarks', $snapshot);
        $this->assertArrayHasKey('confirmedFloors', $snapshot);
        $this->assertEquals(['A' => 5, 'B' => 5, 'C' => 5, 'D' => 0], $snapshot['rounds']);
    }
    
    public function testUntetheredLargeSkewPersistentGap(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 31, 'B' => 3, 'C' => 3],
            [],
            30.0,
            0.5
        );
        
        $model->run(500000, 100000.0);
        
        $metrics = $model->getMetrics();
        
        $this->assertEquals(28, $metrics['initialGap']);
        $this->assertGreaterThan(10, $metrics['finalGap'], 'Gap should persist without fastTimeout');
        $this->assertFalse($metrics['recoveryWindowEntered'], 'Should not enter recovery window');
        $this->assertFalse($metrics['fullRoundConvergence'], 'Should not achieve full convergence');
    }
    
    public function testFastTimeoutAloneDoesNotRecoverWithoutAuthenticatedEvidence(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 31, 'B' => 3, 'C' => 3],
            [],
            30.0,
            0.5
        );

        /*
         * initialRounds are local pacemaker state, not authenticated evidence.
         * Merely enabling FAST must not manufacture proposer-ahead evidence.
         */
        $model->withFastTimeout(true);
        $model->run(500000, 100000.0);

        $metrics = $model->getMetrics();

        $this->assertSame(
            0,
            $metrics['fastTimeoutCount'],
            'FAST must not fire without authenticated proposer-ahead evidence'
        );

        $this->assertGreaterThan(
            10,
            $metrics['finalGap'],
            'Large skew should persist when FAST has no authenticated evidence'
        );

        $this->assertFalse(
            $metrics['recoveryWindowEntered'],
            'FAST enablement alone must not create a recovery window'
        );

        $this->assertFalse(
            $metrics['fullRoundConvergence'],
            'FAST enablement alone must not create full convergence'
        );
    }

    public function testBrakeAndFastDoNotRecoverWithoutAuthenticatedEvidence(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 31, 'B' => 3, 'C' => 3],
            [],
            30.0,
            0.5
        );

        /*
         * Brake is characterization-only and is not part of the production
         * patch. With no authenticated watermarks there is no confirmed lower
         * floor to activate it, and FAST has no proposer-ahead evidence.
         */
        $model->withFastTimeout(true);
        $model->withBrake(true, 120.0);
        $model->run(500000, 100000.0);

        $metrics = $model->getMetrics();

        $this->assertSame(
            0,
            $metrics['brakeActivationCount'],
            'Brake must not activate without authenticated floor evidence'
        );

        $this->assertSame(
            0,
            $metrics['fastTimeoutCount'],
            'FAST must not fire without authenticated proposer-ahead evidence'
        );

        $this->assertGreaterThan(
            10,
            $metrics['finalGap'],
            'Large skew should persist when neither mechanism has evidence'
        );

        $this->assertFalse(
            $metrics['fullRoundConvergence'],
            'No-evidence brake + FAST must not manufacture convergence'
        );
    }

    public function testNormalBaselineNoDivergence(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 3, 'B' => 3, 'C' => 3],
            [],
            30.0,
            0.5
        );
        
        $model->run(500000, 100000.0);
        
        $metrics = $model->getMetrics();
        
        $this->assertTrue($metrics['recoveryWindowEntered'], 'Should enter recovery window');
        $this->assertTrue($metrics['normal3Of4ConsensusReachable'], 'Normal 3-of-4 consensus reachable');
    }
    
    public function testByzantineHugeWatermarkDoesNotCauseJump(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 3, 'B' => 3, 'C' => 3],
            [
                'A' => ['D' => 1000000],
                'B' => ['D' => 1000000],
                'C' => ['D' => 1000000]
            ],
            30.0,
            0.5
        );
        
        $model->run(500000, 100000.0);
        
        $metrics = $model->getMetrics();
        
        $snapshot = $model->getStateSnapshot();
        $this->assertEquals(1000000, $snapshot['watermarks']['A']['D']);
        $this->assertLessThanOrEqual(1, $metrics['finalGap'], 'Honest validators should converge (gap <= 1)');
        $this->assertLessThan(10000, max($snapshot['confirmedFloors']), 'Floor should not reach 1000000');
    }
    
    public function testSelectiveByzantineDeliveryDoesNotCauseDivergence(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 3, 'B' => 3, 'C' => 3],
            [
                'A' => ['D' => 1000000],
                'B' => [],
                'C' => []
            ],
            30.0,
            0.5
        );
        
        $model->run(500000, 100000.0);
        
        $metrics = $model->getMetrics();
        
        $snapshot = $model->getStateSnapshot();
        $this->assertLessThanOrEqual(1, abs($snapshot['rounds']['A'] - $snapshot['rounds']['B']), 'A and B should be within gap 1');
        $this->assertLessThanOrEqual(1, abs($snapshot['rounds']['B'] - $snapshot['rounds']['C']), 'B and C should be within gap 1');
        $this->assertLessThanOrEqual(1, $metrics['finalGap'], 'Final gap should be at most 1');
    }
    
    public function testWatermarkMonotonicity(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 3, 'B' => 3, 'C' => 3],
            [],
            30.0,
            0.5
        );

        $a = $model->getValidator('A');
        $b = $model->getValidator('B');

        $a->recordSignedRound(4);
        $model->deliverSignedRound('A', 'B', 4);

        $this->assertSame(
            4,
            $b->watermarks['A'],
            'First authenticated A@4 evidence should establish B watermark A=4'
        );

        $a->recordSignedRound(2);
        $model->deliverSignedRound('A', 'B', 2);

        $this->assertSame(
            4,
            $b->watermarks['A'],
            'Lower replay must not regress B watermark for A'
        );

        $a->recordSignedRound(6);
        $model->deliverSignedRound('A', 'B', 6);

        $this->assertSame(
            6,
            $b->watermarks['A'],
            'Higher authenticated evidence should advance B watermark for A'
        );
    }

    public function testConfirmedFloorRequiresTwoValidators(): void
    {
        $model = new TetheredPacemakerModel(
            ['A' => 5, 'B' => 3, 'C' => 3],
            [
                'A' => ['D' => 100]
            ],
            30.0,
            0.5
        );
        
        $model->run(500000, 100000.0);
        
        $metrics = $model->getMetrics();
        
        $this->assertLessThan(100, $metrics['maximumGap'], 'D@100 should not dominate floor');
    }
    

    public function testFloorCalculation(): void
    {
        // Test that floor is correctly calculated from watermarks
        $model = new TetheredPacemakerModel(
            ['A' => 5, 'B' => 3, 'C' => 3],
            [
                'A' => ['B' => 3, 'C' => 3],
                'B' => ['A' => 5, 'C' => 3],
                'C' => ['A' => 5, 'B' => 3],
            ],
            30.0,
            0.5
        );
        
        $snapshot = $model->getStateSnapshot();
        
        // A has watermarks B=3, C=3, floor should be 3
        $this->assertEquals(3, $snapshot['confirmedFloors']['A'], 'A should have floor 3');
        // B has watermarks A=5, C=3, floor should be 3
        $this->assertEquals(3, $snapshot['confirmedFloors']['B'], 'B should have floor 3');
        // C has watermarks A=5, B=3, floor should be 3
        $this->assertEquals(3, $snapshot['confirmedFloors']['C'], 'C should have floor 3');
        // D is offline, floor should be null
        $this->assertNull($snapshot['confirmedFloors']['D'], 'D should have null floor');
    }

    public function testOfflineValidatorDoesNotAdvancePacemaker(): void
    {
        // A, B, C online, D offline starting at round 0
        $model = new TetheredPacemakerModel(
            ['A' => 3, 'B' => 3, 'C' => 3, 'D' => 0],
            [],
            30.0,
            0.5
        );
        
        // Mark D as offline
        $validatorD = $model->getValidator('D');
        $validatorD->online = false;
        
        $model->run(500000, 100000.0);
        
        $snapshot = $model->getStateSnapshot();
        
        // D should remain at round 0
        $this->assertEquals(0, $snapshot['rounds']['D'], 'Offline D should remain at localRound=0');
        
        // D should have no signed rounds (empty watermarks)
        $this->assertEmpty($snapshot['watermarks']['D'], 'Offline D should have empty watermarks');
        
        // A, B, C should converge
        $rounds = [$snapshot['rounds']['A'], $snapshot['rounds']['B'], $snapshot['rounds']['C']];
        $this->assertLessThanOrEqual(1, max($rounds) - min($rounds), 'Online validators should converge');
    }
}
