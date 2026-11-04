<?php
declare(strict_types=1);

namespace Greenwood\Tests\Compute\Runtime;

use Greenwood\Compute\Runtime\Mount;
use PHPUnit\Framework\TestCase;

class MountTest extends TestCase
{
    public function testBindMount(): void
    {
        $mount = Mount::bind('/host/source', '/container/dest');

        $this->assertSame(Mount::TYPE_BIND, $mount->getType());
        $this->assertSame('/host/source', $mount->getSource());
        $this->assertSame('/container/dest', $mount->getDestination());
        $this->assertFalse($mount->isReadOnly());
        $this->assertEquals([], $mount->getOptions());
    }

    public function testReadOnlyBindMount(): void
    {
        $mount = Mount::bindReadOnly('/host/source', '/container/dest');

        $this->assertSame(Mount::TYPE_BIND, $mount->getType());
        $this->assertTrue($mount->isReadOnly());
    }

    public function testTmpfsMount(): void
    {
        $mount = Mount::tmpfs('/tmp');

        $this->assertSame(Mount::TYPE_TMPFS, $mount->getType());
        $this->assertSame('', $mount->getSource());
        $this->assertSame('/tmp', $mount->getDestination());
    }

    public function testDestinationMustBeAbsolutePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mount destination must be an absolute path');
        Mount::bind('/host/source', 'relative/dest');
    }

    public function testInvalidMountType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid mount type');
        new Mount('invalid', '/source', '/dest');
    }

    public function testEmptySource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mount source cannot be empty');
        Mount::bind('', '/dest');
    }

    public function testEmptyDestination(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mount destination cannot be empty');
        Mount::bind('/source', '');
    }

    public function testWithOptions(): void
    {
        $mount = Mount::bind('/host', '/dest', ['ro', 'noexec']);
        $this->assertEquals(['ro', 'noexec'], $mount->getOptions());
    }

    public function testToArray(): void
    {
        $mount = Mount::bindReadOnly('/host/source', '/container/dest');
        $array = $mount->toArray();

        $this->assertIsArray($array);
        $this->assertSame(Mount::TYPE_BIND, $array['type']);
        $this->assertTrue($array['readOnly']);
    }
}
