<?php

declare(strict_types=1);

namespace Greenwood\Tests\Compute\Containerd;

use Containerd\Services\Snapshots\V1\ViewSnapshotResponse;
use Containerd\Types\Mount;
use Greenwood\Compute\Containerd\OciSpecBuilder;
use Greenwood\Compute\Containerd\Service\SnapshotService;
use Greenwood\Compute\Containerd\ValueObject\PreparedSnapshot;
use PHPUnit\Framework\TestCase;

final class SystemServicePrimitivesTest extends TestCase
{
    public function testHostNetworkDefaultsToPrivateNamespace(): void
    {
        $spec = json_decode(
            (new OciSpecBuilder('rootfs'))->buildJson(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertContains(
            'network',
            array_column($spec['linux']['namespaces'], 'type')
        );
    }

    public function testHostNetworkRemovesNetworkNamespace(): void
    {
        $spec = json_decode(
            (new OciSpecBuilder('rootfs'))
                ->setHostNetwork(true)
                ->buildJson(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertNotContains(
            'network',
            array_column($spec['linux']['namespaces'], 'type')
        );
    }

    public function testViewResponseMapsToPreparedSnapshot(): void
    {
        $mount = new Mount();
        $mount->setType('erofs');
        $mount->setSource('/dev/loop-test');
        $mount->setOptions(['ro']);

        $response = new ViewSnapshotResponse();
        $response->setMounts([$mount]);

        $snapshot = PreparedSnapshot::fromViewSnapshotResponse(
            $response,
            'gw-system-view-test',
            'erofs'
        );

        self::assertSame(
            'gw-system-view-test',
            $snapshot->getKey()
        );

        self::assertSame(
            'erofs',
            $snapshot->getSnapshotter()
        );

        self::assertCount(
            1,
            $snapshot->getMounts()
        );

        self::assertSame(
            'erofs',
            $snapshot->getMounts()[0]->getType()
        );

        self::assertContains(
            'ro',
            iterator_to_array(
                $snapshot->getMounts()[0]->getOptions()
            )
        );
    }

    public function testSnapshotServiceHasViewContract(): void
    {
        $method = new \ReflectionMethod(
            SnapshotService::class,
            'view'
        );

        self::assertTrue($method->isPublic());
        self::assertSame(
            ['key', 'parent', 'leaseId'],
            array_map(
                static fn(\ReflectionParameter $parameter): string =>
                    $parameter->getName(),
                $method->getParameters()
            )
        );

        self::assertSame(
            2,
            $method->getNumberOfRequiredParameters()
        );
    }
}
