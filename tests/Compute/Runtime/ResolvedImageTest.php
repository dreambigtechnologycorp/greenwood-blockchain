<?php
declare(strict_types=1);

namespace Greenwood\Tests\Compute\Runtime;

use Greenwood\Compute\Runtime\ResolvedImage;
use PHPUnit\Framework\TestCase;

class ResolvedImageTest extends TestCase
{
    public function testValidDigest(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);
        $image = new ResolvedImage('myapp:latest', $digest, 'application/vnd.oci.image.index.v1+json', 'linux/arm64');

        $this->assertSame('myapp:latest', $image->getRequestedReference());
        $this->assertSame($digest, $image->getResolvedDigest());
        $this->assertSame('application/vnd.oci.image.index.v1+json', $image->getMediaType());
        $this->assertSame('linux/arm64', $image->getPlatform());
    }

    public function testFromDigest(): void
    {
        $digest = 'sha256:' . str_repeat('b', 64);
        $image = ResolvedImage::fromDigest($digest, 'linux/amd64');

        $this->assertSame($digest, $image->getRequestedReference());
        $this->assertSame($digest, $image->getResolvedDigest());
        $this->assertSame('linux/amd64', $image->getPlatform());
    }

    public function testFromTag(): void
    {
        $tag = 'myapp:v1.2.3';
        $digest = 'sha256:' . str_repeat('c', 64);
        $image = ResolvedImage::fromTag($tag, $digest);

        $this->assertSame($tag, $image->getRequestedReference());
        $this->assertSame($digest, $image->getResolvedDigest());
    }

    public function testMatchesDigest(): void
    {
        $digest = 'sha256:' . str_repeat('d', 64);
        $image = ResolvedImage::fromDigest($digest);

        $this->assertTrue($image->matchesDigest($digest));
        $this->assertFalse($image->matchesDigest('sha256:' . str_repeat('e', 64)));
    }

    public function testInvalidDigestMissingPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Must start with');
        new ResolvedImage('myapp', 'invalid-digest-format');
    }

    public function testInvalidDigestWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Must be exactly 64 hex characters');
        new ResolvedImage('myapp', 'sha256:abc123');
    }

    public function testInvalidDigestNonHex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Must be exactly 64 hex characters');
        new ResolvedImage('myapp', 'sha256:' . str_repeat('g', 64));
    }

    public function testToArray(): void
    {
        $digest = 'sha256:' . str_repeat('f', 64);
        $image = new ResolvedImage('myapp:latest', $digest, 'application/vnd.oci.image.index.v1+json');
        $array = $image->toArray();

        $this->assertIsArray($array);
        $this->assertSame('myapp:latest', $array['requestedReference']);
        $this->assertSame($digest, $array['resolvedDigest']);
        $this->assertSame('application/vnd.oci.image.index.v1+json', $array['mediaType']);
    }

    public function testMediaTypeNullable(): void
    {
        $digest = 'sha256:' . str_repeat('f', 64);
        $image = ResolvedImage::fromDigest($digest);
        $this->assertNull($image->getMediaType());
    }

    public function testPlatformNullable(): void
    {
        $digest = 'sha256:' . str_repeat('f', 64);
        $image = ResolvedImage::fromDigest($digest);
        $this->assertNull($image->getPlatform());
    }
}
