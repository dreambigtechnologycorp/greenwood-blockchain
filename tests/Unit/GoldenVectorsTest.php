<?php
declare(strict_types=1);
namespace Greenwood\Tests\Unit;
use PHPUnit\Framework\TestCase;
use Greenwood\Tests\Fixtures\ProtocolFixture;
final class GoldenVectorsTest extends TestCase
{
    public function testFrozenProtocolVectors(): void
    {
        $expected=json_decode(file_get_contents(__DIR__.'/../Fixtures/protocol-v1.json'),true,16,JSON_THROW_ON_ERROR);
        self::assertSame($expected,ProtocolFixture::values());
    }
}
