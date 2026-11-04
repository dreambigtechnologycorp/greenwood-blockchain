<?php
declare(strict_types=1);
namespace Greenwood\Tests\Unit\Compute;

use Greenwood\Compute\Codec\ComputeRequestCodec;
use Greenwood\Compute\Codec\ComputeReceiptCodec;
use Greenwood\Tests\Fixtures\ComputeFixture as F;
use PHPUnit\Framework\TestCase;

final class ComputeBoundaryTest extends TestCase
{
    public function testEveryTruncatedPrefixIsRejected(): void
    {
        foreach ([
            [F::request()->toBytes(), ComputeRequestCodec::decode(...)],
            [F::receipt()->toUnsignedBytes(), ComputeReceiptCodec::decodeUnsigned(...)],
            [F::receipt()->sign(F::key())->toSignedBytes(), ComputeReceiptCodec::decode(...)],
        ] as [$bytes, $decode]) {
            for ($length = 0; $length < strlen($bytes); ++$length) {
                try {
                    $decode(substr($bytes, 0, $length));
                    self::fail('Accepted truncated prefix at ' . $length);
                } catch (\InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
    }
}
