<?php
declare(strict_types=1);
namespace Greenwood\Compute\Codec;

use Greenwood\Codec\Reader;
use Greenwood\Compute\Protocol\ComputeRequest;

/**
 * Canonical binary codec for GREENWOOD_COMPUTE_V1 requests.
 * Rejects truncation, oversized fields, unknown versions/trust classes,
 * and trailing bytes, per Greenwood codec error conventions.
 */
final class ComputeRequestCodec
{
    public const MAX_REQUEST_BYTES = 1024;

    public static function decode(string $bytes): ComputeRequest
    {
        $r = new Reader($bytes, self::MAX_REQUEST_BYTES);
        $request = self::read($r);
        $r->end();
        return $request;
    }

    public static function read(Reader $r): ComputeRequest
    {
        $version = $r->u16();
        if ($version !== ComputeRequest::PROTOCOL_VERSION) {
            throw new \InvalidArgumentException('Unknown compute protocol version: '.$version);
        }
        return new ComputeRequest(
            requester: $r->take(32),
            requestNonce: $r->u32(),
            workloadDigest: $r->take(32),
            workloadRef: $r->bytes(ComputeRequest::MAX_REF_BYTES),
            inputDigest: $r->take(32),
            inputRef: $r->bytes(ComputeRequest::MAX_REF_BYTES),
            trustClass: $r->u8(),
            cpuLimitMillis: $r->u32(),
            memoryLimitBytes: $r->u64(),
            pidLimit: $r->u32(),
            executionTimeoutMs: $r->u64(),
            outputRetentionBytes: $r->u32(),
            maxPayment: $r->u64(),
            deadlineHeight: $r->u64(),
        );
    }
}
