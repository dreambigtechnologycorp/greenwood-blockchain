<?php

declare(strict_types=1);

namespace Greenwood\Tests\Unit\Crypto;

use Greenwood\Crypto\ProtocolHash;
use PHPUnit\Framework\TestCase;

class ProtocolHashTest extends TestCase
{
    public function testHashEmpty(): void
    {
        $result = ProtocolHash::hash('TEST_DOMAIN', '');
        $this->assertEquals(32, strlen($result));
    }
    
    public function testHashWithValue(): void
    {
        $result = ProtocolHash::hash('TEST_DOMAIN', 'hello');
        $this->assertEquals(32, strlen($result));
    }
    
    public function testDomainSeparation(): void
    {
        $result1 = ProtocolHash::hash('DOMAIN_A', 'data');
        $result2 = ProtocolHash::hash('DOMAIN_B', 'data');
        $this->assertNotEquals($result1, $result2);
    }
    
    public function testSameInputSameOutput(): void
    {
        $result1 = ProtocolHash::hash('TEST', 'data');
        $result2 = ProtocolHash::hash('TEST', 'data');
        $this->assertEquals($result1, $result2);
    }
}
