<?php
declare(strict_types=1);

namespace Greenwood\Tests\Compute\Runtime;

use Greenwood\Compute\Runtime\ResourceUsage;
use PHPUnit\Framework\TestCase;

class ResourceUsageTest extends TestCase
{
    public function testWithMemory(): void
    {
        $usage = ResourceUsage::withMemory(128 * 1024 * 1024, 256 * 1024 * 1024);

        $this->assertSame(128 * 1024 * 1024, $usage->getMemoryBytes());
        $this->assertSame(256 * 1024 * 1024, $usage->getMemoryPeakBytes());
        $this->assertNull($usage->getCpuNanoseconds());
        $this->assertNull($usage->getDiskBytes());
    }

    public function testWithCpu(): void
    {
        $usage = ResourceUsage::withCpu(1.5 * 1_000_000_000); // 1.5 seconds in nanoseconds

        $this->assertSame(1.5 * 1_000_000_000, $usage->getCpuNanoseconds());
        $this->assertEquals(1.5, $usage->getCpuSeconds());
        $this->assertNull($usage->getMemoryBytes());
    }

    public function testFullUsage(): void
    {
        $usage = ResourceUsage::full(
            cpuNanoseconds: 2.5 * 1_000_000_000,
            memoryBytes: 128 * 1024 * 1024,
            memoryPeakBytes: 256 * 1024 * 1024,
            diskBytes: 1024 * 1024,
            ioReadBytes: 10 * 1024 * 1024,
            ioWriteBytes: 5 * 1024 * 1024
        );

        $this->assertEquals(2.5, $usage->getCpuSeconds());
        $this->assertEquals(128.0, $usage->getMemoryMegabytes());
        $this->assertEquals(256.0, $usage->getMemoryPeakMegabytes());
        $this->assertSame(1024 * 1024, $usage->getDiskBytes());
        $this->assertSame(10 * 1024 * 1024, $usage->getIoReadBytes());
        $this->assertSame(5 * 1024 * 1024, $usage->getIoWriteBytes());
    }

    public function testNegativeMemoryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Memory bytes cannot be negative');
        new ResourceUsage(memoryBytes: -1);
    }

    public function testNegativeCpuThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CPU nanoseconds cannot be negative');
        new ResourceUsage(cpuNanoseconds: -1);
    }

    public function testToArray(): void
    {
        $usage = ResourceUsage::full(
            cpuNanoseconds: 1_000_000_000,
            memoryBytes: 128 * 1024 * 1024
        );
        $array = $usage->toArray();

        $this->assertIsArray($array);
        $this->assertEquals(1_000_000_000.0, $array['cpuNanoseconds']);
        $this->assertSame(128 * 1024 * 1024, $array['memoryBytes']);
    }

    public function testNullValues(): void
    {
        $usage = new ResourceUsage();
        $this->assertNull($usage->getCpuNanoseconds());
        $this->assertNull($usage->getMemoryBytes());
    }
}
