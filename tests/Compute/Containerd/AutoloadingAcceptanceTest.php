<?php

namespace Greenwood\Tests\Compute\Containerd;

use PHPUnit\Framework\TestCase;

/**
 * Autoloading acceptance test for generated containerd protobuf classes
 */
class AutoloadingAcceptanceTest extends TestCase
{
    public function testGeneratedVersionResponseClassExists(): void
    {
        $this->assertTrue(
            class_exists(\Containerd\Services\Version\V1\VersionResponse::class),
            "Containerd\Services\Version\V1\VersionResponse should be loadable"
        );
    }

    public function testGeneratedVersionClientClassExists(): void
    {
        $this->assertTrue(
            class_exists(\Containerd\Services\Version\V1\VersionClient::class),
            "Containerd\Services\Version\V1\VersionClient should be loadable"
        );
    }

    public function testGeneratedGPBMetadataClassExists(): void
    {
        $this->assertTrue(
            class_exists(\GPBMetadata\Api\Services\Version\V1\Version::class),
            "GPBMetadata\Api\Services\Version\V1\Version should be loadable"
        );
    }

    public function testVersionResponseMessageConstruction(): void
    {
        $response = new \Containerd\Services\Version\V1\VersionResponse();
        $this->assertInstanceOf(\Containerd\Services\Version\V1\VersionResponse::class, $response);
    }

    public function testVersionResponseGetterSetter(): void
    {
        $response = new \Containerd\Services\Version\V1\VersionResponse();
        $response->setVersion("2.0.0");
        $response->setRevision("abc123");
        
        $this->assertEquals("2.0.0", $response->getVersion());
        $this->assertEquals("abc123", $response->getRevision());
    }

    public function testGeneratedNamespacesClassExists(): void
    {
        $this->assertTrue(
            class_exists(\Containerd\Services\Namespaces\V1\NamespacesClient::class),
            "Containerd\Services\Namespaces\V1\NamespacesClient should be loadable"
        );
    }

    public function testGeneratedSnapshotsClassExists(): void
    {
        $this->assertTrue(
            class_exists(\Containerd\Services\Snapshots\V1\SnapshotsClient::class),
            "Containerd\Services\Snapshots\V1\SnapshotsClient should be loadable"
        );
    }

    public function testGeneratedContentClassExists(): void
    {
        $this->assertTrue(
            class_exists(\Containerd\Services\Content\V1\ContentClient::class),
            "Containerd\Services\Content\V1\ContentClient should be loadable"
        );
    }

    public function testGeneratedImagesClassExists(): void
    {
        $this->assertTrue(
            class_exists(\Containerd\Services\Images\V1\ImagesClient::class),
            "Containerd\Services\Images\V1\ImagesClient should be loadable"
        );
    }

    public function testGeneratedContainersClassExists(): void
    {
        $this->assertTrue(
            class_exists(\Containerd\Services\Containers\V1\ContainersClient::class),
            "Containerd\Services\Containers\V1\ContainersClient should be loadable"
        );
    }

    public function testGeneratedTasksClassExists(): void
    {
        $this->assertTrue(
            class_exists(\Containerd\Services\Tasks\V1\TasksClient::class),
            "Containerd\Services\Tasks\V1\TasksClient should be loadable"
        );
    }
}
