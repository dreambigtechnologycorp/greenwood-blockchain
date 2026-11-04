<?php
declare(strict_types=1);
namespace Greenwood\Compute\Codec;

use Greenwood\Codec\{Canonical as C, Reader};
use Greenwood\Compute\Protocol\ComputeReceiptV2;

final class ComputeReceiptV2Codec
{
    public static function decode(string $bytes): ComputeReceiptV2 { return self::parse($bytes, true); }
    public static function decodeUnsigned(string $bytes): ComputeReceiptV2 { return self::parse($bytes, false); }

    private static function parse(string $bytes, bool $signed): ComputeReceiptV2
    {
        $r = new Reader($bytes, 1028);
        if ($r->u16() !== 2) throw new \InvalidArgumentException('L1 requires receipt V2');
        $id = $r->take(32);
        $generation = $r->u32();
        // Reconstruct only the shared field layout. Never verify a V2 signature under V1 domains.
        $shared = C::encodeU16BE(1) . $id . substr($bytes, 38);
        $body = $signed ? ComputeReceiptCodec::decode($shared) : ComputeReceiptCodec::decodeUnsigned($shared);
        $result = new ComputeReceiptV2($body, $generation);
        if ($signed) $result->setSignature($body->getSignature());
        return $result;
    }
}
