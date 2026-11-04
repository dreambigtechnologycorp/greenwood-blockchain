<?php
declare(strict_types=1);

namespace Greenwood\Tests\Compute\Runtime;

use Greenwood\Compute\Runtime\RuntimeInfo;
use PHPUnit\Framework\TestCase;

class RuntimeInfoTest extends TestCase
{
    public function testForContainerd(): void
    {
        $info = RuntimeInfo::forContainerd(
            '2.0.0',
            '/run/containerd/containerd.sock',
            'linux',
            'arm64',
            ['memory_limit' => true, 'cpu_limit' => true]
        );

        $this->assertSame('containerd', $info->getRuntimeName());
        $this->assertSame('2.0.0', $info->getRuntimeVersion());
        $this->assertSame('/run/containerd/containerd.sock', $info->getEndpoint());
        $this->assertSame('linux', $info->getPlatform());
        $this->assertSame('arm64', $info->getArchitecture());
        $this->assertTrue($info->isAvailable());
        $this->assertTrue($info->hasCapability('memory_limit'));
        $this->assertTrue($info->hasCapability('cpu_limit'));
        $this->assertFalse($info->hasCapability('unknown_capability'));
    }

    public function testUnavailable(): void
    {
        $info = RuntimeInfo::unavailable();

        $this->assertSame('unknown', $info->getRuntimeName());
        $this->assertSame('unknown', $info->getRuntimeVersion());
        $this->assertSame('', $info->getEndpoint());
        $this->assertFalse($info->isAvailable());
        $this->assertEquals([], $info->getCapabilities());
    }

    public function testToArray(): void
    {
        $info = RuntimeInfo::forContainerd('2.0.0');
        $array = $info->toArray();

        $this->assertIsArray($array);
        $this->assertSame('containerd', $array['runtimeName']);
        $this->assertSame('2.0.0', $array['runtimeVersion']);
        $this->assertTrue($array['available']);
    }

    public function testCapabilitiesEmptyByDefault(): void
    {
        $info = RuntimeInfo::forContainerd('2.0.0');
        $this->assertEquals([], $info->getCapabilities());
        $this->assertFalse($info->hasCapability('any_capability'));
    }

    public function testPlatformDefaults(): void
    {
        $info = RuntimeInfo::unavailable();
        $this->assertSame(PHP_OS_FAMILY, $info->getPlatform());
        $this->assertNotNull($info->getArchitecture());
    }
}
