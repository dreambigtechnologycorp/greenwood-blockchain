<?php
declare(strict_types=1);

namespace Greenwood\Tests\Support;

use PHPUnit\Framework\TestCase;

final class TetheredPacemakerSemanticTest extends TestCase
{
    public function testCanonicalMembershipAlwaysContainsABCD(): void
    {
        $model = new TetheredPacemakerModel(
            ["A" => 5, "B" => 5, "C" => 5],
            []
        );
        
        $snapshot = $model->getStateSnapshot();
        
        $this->assertArrayHasKey("A", $snapshot["rounds"]);
        $this->assertArrayHasKey("B", $snapshot["rounds"]);
        $this->assertArrayHasKey("C", $snapshot["rounds"]);
        $this->assertArrayHasKey("D", $snapshot["rounds"]);
        
        $this->assertEquals(5, $snapshot["rounds"]["A"]);
        $this->assertEquals(5, $snapshot["rounds"]["B"]);
        $this->assertEquals(5, $snapshot["rounds"]["C"]);
        $this->assertEquals(0, $snapshot["rounds"]["D"]);
    }

    public function testOfflineDRemainsCanonicalMember(): void
    {
        $model = new TetheredPacemakerModel(
            ["A" => 10, "B" => 3, "C" => 3],
            []
        );
        
        $snapshot = $model->getStateSnapshot();
        
        $this->assertArrayHasKey("D", $snapshot["rounds"]);
        
        $validator = $model->getValidator("D");
        $this->assertFalse($validator->online);
        $this->assertEquals(0, $validator->localRound);
    }

    public function testInitialRoundsDoNotCreateEvidence(): void
    {
        $model = new TetheredPacemakerModel(
            ["A" => 10, "B" => 3, "C" => 3],
            []
        );
        
        $snapshot = $model->getStateSnapshot();
        
        $this->assertEquals([], $snapshot["watermarks"]["A"]);
        $this->assertEquals([], $snapshot["watermarks"]["B"]);
        $this->assertEquals([], $snapshot["watermarks"]["C"]);
    }

    public function testFloorAbsentWithOnlyOneObservation(): void
    {
        $model = new TetheredPacemakerModel(
            ["A" => 10, "B" => 3, "C" => 3],
            [
                "A" => [
                    "A" => 10,
                ],
            ]
        );
        
        $snapshot = $model->getStateSnapshot();
        
        $this->assertNull($snapshot["confirmedFloors"]["A"]);
    }

    public function testFloorUsesTwoDistinctValidators(): void
    {
        $model = new TetheredPacemakerModel(
            ["A" => 10, "B" => 3, "C" => 3],
            [
                "A" => [
                    "A" => 10,
                    "B" => 3,
                ],
            ]
        );
        
        $snapshot = $model->getStateSnapshot();
        
        $this->assertEquals(3, $snapshot["confirmedFloors"]["A"]);
    }

    public function testFloorIsSecondHighestObservation(): void
    {
        $model = new TetheredPacemakerModel(
            ["A" => 10, "B" => 3, "C" => 3],
            [
                "A" => [
                    "A" => 10,
                    "B" => 3,
                    "C" => 3,
                ],
            ]
        );
        
        $snapshot = $model->getStateSnapshot();
        $this->assertEquals(3, $snapshot["confirmedFloors"]["A"]);
    }

    public function testFloorIsSecondHighestObservationA31B17C3(): void
    {
        $model = new TetheredPacemakerModel(
            ["A" => 31, "B" => 17, "C" => 3],
            [
                "A" => [
                    "A" => 31,
                    "B" => 17,
                    "C" => 3,
                ],
            ]
        );
        
        $snapshot = $model->getStateSnapshot();
        $this->assertEquals(17, $snapshot["confirmedFloors"]["A"]);
    }

    public function testSingleByzantineHugeWatermarkCannotEstablishFloor(): void
    {
        $model = new TetheredPacemakerModel(
            ["A" => 31, "B" => 3, "C" => 3],
            [
                "A" => [
                    "D" => 1000000,
                ],
            ]
        );
        
        $snapshot = $model->getStateSnapshot();
        
        $this->assertNull($snapshot["confirmedFloors"]["A"]);
    }

    public function testFloorIsSecondHighestOfCanonicalMembers(): void
    {
        $model = new TetheredPacemakerModel(
            ["A" => 31, "B" => 3, "C" => 3],
            [
                "A" => [
                    "A" => 31,
                    "B" => 3,
                    "C" => 3,
                    "D" => 1000000,
                ],
            ]
        );
        
        $snapshot = $model->getStateSnapshot();
        
        $this->assertEquals(31, $snapshot["confirmedFloors"]["A"]);
    }

    public function testRoundZeroIsDistinctFromAbsentFloor(): void
    {
        $model = new TetheredPacemakerModel(
            ["A" => 0, "B" => 0, "C" => 3],
            [
                "A" => [
                    "A" => 0,
                    "B" => 0,
                ],
            ]
        );
        
        $snapshot = $model->getStateSnapshot();
        
        $this->assertEquals(0, $snapshot["confirmedFloors"]["A"]);
    }

    public function testMissingKeyNeverInterpretedAsR0(): void
    {
        $model = new TetheredPacemakerModel(
            ["A" => 10, "B" => 3, "C" => 3],
            [
                "A" => [
                    "A" => 10,
                    "B" => 3,
                ],
            ]
        );
        
        $snapshot = $model->getStateSnapshot();
        
        $this->assertEquals(3, $snapshot["confirmedFloors"]["A"]);
    }
}
