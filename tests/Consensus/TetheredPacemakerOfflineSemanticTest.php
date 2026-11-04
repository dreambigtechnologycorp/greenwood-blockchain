<?php

declare(strict_types=1);

namespace Greenwood\Tests\Consensus;

use PHPUnit\Framework\TestCase;

/**
 * Test offline validator behavior in the TetheredPacemakerModel.
 * 
 * Validates that an offline validator (D not present in initialRounds)
 * remains offline and does not autonomously advance its pacemaker or sign.
 */
final class TetheredPacemakerOfflineSemanticTest extends TestCase
{
    public function testOfflineValidatorDoesNotAdvancePacemaker(): void
    {
        // Construct model with only A, B, C in initialRounds
        // D is canonical but offline (not in initialRounds)
        $model = new \Greenwood\Tests\Support\TetheredPacemakerModel(
            [
                'A' => 3,
                'B' => 3,
                'C' => 3,
            ],
            []
        );
        
        // Verify D exists and is offline before running
        $dValidator = $model->getValidator('D');
        $this->assertArrayHasKey('D', $model->getStateSnapshot()['rounds']);
        $this->assertFalse($dValidator->online, 'D should be offline');
        $this->assertEquals(0, $dValidator->localRound, 'D localRound should be 0');
        
        // Run many events to simulate time passing
        $model->run(500000, 100000.0);
        
        // Verify D remains offline and unchanged
        $this->assertFalse($dValidator->online, 'D should remain offline');
        $this->assertEquals(0, $dValidator->localRound, 'D localRound should remain 0');
        $this->assertEquals([], $dValidator->signedRounds, 'D should have no signed rounds');
        $this->assertEquals([], $dValidator->watermarks, 'D should have no watermarks');
        $this->assertNull($dValidator->confirmedFloor, 'D should have null confirmedFloor');
    }
    
    public function testOfflineDRemainsCanonicalMemberWithNullFloor(): void
    {
        $model = new \Greenwood\Tests\Support\TetheredPacemakerModel(
            [
                'A' => 31,
                'B' => 3,
                'C' => 3,
            ],
            []
        );
        
        $dValidator = $model->getValidator('D');
        
        // D exists as canonical member but is offline
        $this->assertFalse($dValidator->online);
        $this->assertEquals(0, $dValidator->localRound);
        
        // D has no watermarks because it never receives evidence
        $this->assertEquals([], $dValidator->watermarks);
        
        // D's confirmedFloor is null (no observations)
        $this->assertNull($dValidator->confirmedFloor);
    }
    
    public function testMultipleOfflineValidators(): void
    {
        // Only A online, B/C/D all offline
        $model = new \Greenwood\Tests\Support\TetheredPacemakerModel(
            [
                'A' => 10,
            ],
            []
        );
        
        // A is online
        $aValidator = $model->getValidator('A');
        $this->assertTrue($aValidator->online);
        $this->assertEquals(10, $aValidator->localRound);
        
        // B, C, D are offline
        foreach (['B', 'C', 'D'] as $id) {
            $validator = $model->getValidator($id);
            $this->assertFalse($validator->online, "$id should be offline");
            $this->assertEquals(0, $validator->localRound, "$id localRound should be 0");
            $this->assertEquals([], $validator->signedRounds, "$id should have no signed rounds");
        }
    }
    
    public function testOfflineValidatorDoesNotSign(): void
    {
        $model = new \Greenwood\Tests\Support\TetheredPacemakerModel(
            [
                'A' => 3,
                'B' => 3,
                'C' => 3,
            ],
            []
        );
        
        // Run a large number of events
        $model->run(1000000, 200000.0);
        
        // D is offline and should have no signed rounds
        $dValidator = $model->getValidator('D');
        $this->assertEquals([], $dValidator->signedRounds, 'Offline D should have no signed rounds');
        
        // D should have no watermarks (no evidence ever received)
        $this->assertEquals([], $dValidator->watermarks, 'Offline D should have no watermarks');
    }
}