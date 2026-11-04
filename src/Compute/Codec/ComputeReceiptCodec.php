<?php
declare(strict_types=1);
namespace Greenwood\Compute\Codec;

use Greenwood\Codec\Reader;
use Greenwood\Compute\Protocol\ComputeReceipt;

/**
 * Canonical binary codec for GREENWOOD_COMPUTE_V1 receipts.
 * Rejects truncation, malformed lengths, unknown versions/statuses,
 * oversized fields, and trailing bytes.
 */
final class ComputeReceiptCodec
{
    public const MAX_RECEIPT_BYTES = 1024;

    /** Decode a signed receipt: unsigned body | u16(64) | signature[64]. */
    public static function decode(string $bytes): ComputeReceipt
    {
        $r = new Reader($bytes, self::MAX_RECEIPT_BYTES);
        $receipt = self::read($r);
        if ($r->u16() !== 64) {
            throw new \InvalidArgumentException('Invalid signature length');
        }
        $receipt->setSignature($r->take(64));
        $r->end();
        return $receipt;
    }

    /** Decode only the unsigned body (e.g. before a signature is attached). */
    public static function decodeUnsigned(string $bytes): ComputeReceipt
    {
        $r = new Reader($bytes, self::MAX_RECEIPT_BYTES);
        $receipt = self::read($r);
        $r->end();
        return $receipt;
    }

    public static function read(Reader $r): ComputeReceipt
    {
        $version = $r->u16();
        if ($version !== ComputeReceipt::PROTOCOL_VERSION) {
            throw new \InvalidArgumentException('Unknown compute protocol version: '.$version);
        }
        $requestId = $r->take(32);
        $providerId = $r->take(32);
        $executionId = $r->take(32);
        $workloadDigest = $r->take(32);
        $inputDigest = $r->take(32);
        $outputDigest = $r->take(32);
        $outputRef = $r->bytes(ComputeReceipt::MAX_REF_BYTES);
        $status = $r->u8();
        $exitPresent = $r->u8();
        if ($exitPresent > 1) {
            throw new \InvalidArgumentException('Invalid exit_code presence flag');
        }
        $exitCode = $exitPresent === 1 ? $r->u32() : null;
        $cpuTimeMs = $r->u64();
        $memoryPeakBytes = $r->u64();
        $runtimeDurationMs = $r->u64();
        $fuelPresent = $r->u8();
        if ($fuelPresent > 1) {
            throw new \InvalidArgumentException('Invalid fuel_used presence flag');
        }
        $fuelUsed = $fuelPresent === 1 ? $r->u64() : null;
        $outputBytesObserved = $r->u64();
        $stdoutTruncated = self::boolByte($r);
        $stderrTruncated = self::boolByte($r);
        return new ComputeReceipt(
            requestId: $requestId,
            providerId: $providerId,
            executionId: $executionId,
            workloadDigest: $workloadDigest,
            inputDigest: $inputDigest,
            outputDigest: $outputDigest,
            outputRef: $outputRef,
            status: $status,
            exitCode: $exitCode,
            cpuTimeMs: $cpuTimeMs,
            memoryPeakBytes: $memoryPeakBytes,
            runtimeDurationMs: $runtimeDurationMs,
            fuelUsed: $fuelUsed,
            outputBytesObserved: $outputBytesObserved,
            stdoutTruncated: $stdoutTruncated,
            stderrTruncated: $stderrTruncated,
        );
    }

    private static function boolByte(Reader $r): bool
    {
        $v = $r->u8();
        if ($v > 1) {
            throw new \InvalidArgumentException('Invalid boolean flag');
        }
        return $v === 1;
    }
}
