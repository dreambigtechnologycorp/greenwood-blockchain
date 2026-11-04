<?php

namespace Greenwood\Tests\Compute\Containerd;

use Greenwood\Compute\Containerd\ContainerdConnection;
use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\Service\VersionService;
use Greenwood\Contracts\ContainerdUnavailableException;
use PHPUnit\Framework\TestCase;

class VersionServiceTest extends TestCase
{
    private ?VersionService $service = null;

    protected function setUp(): void
    {
        $this->service = new VersionService(new ContainerdClientSet(new ContainerdConnection()));
    }

    public function testConnectionAvailable(): void
    {
        $connection = new ContainerdConnection();
        $this->assertTrue($connection->checkConnection());
    }

    public function testVersionServiceExists(): void
    {
        $this->assertNotNull($this->service);
    }
}
