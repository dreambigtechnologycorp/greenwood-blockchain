<?php
declare(strict_types=1);

namespace Greenwood\Contracts;

use Greenwood\Contracts\ContractTransactionLimits as L;

/**
 * Canonical Greenwood ABI v1 encoder.
 *
 * Every Greenwood component that constructs contract-invocation or deployment
 * input MUST use this class rather than duplicating the encoding logic.
 *
 * Input encoding:
 *   [u32_BE method_length][method_bytes][u32_BE calldata_length][calldata_bytes]
 *
 * @see docs/CONTRACT_ABI.md for the full ABI specification.
 */
final class AbiEncoder
{
    /**
     * Encode a contract-call invocation input.
     *
     * @param string $method   Method name (max L::MAX_METHOD_BYTES bytes).
     * @param string $calldata Raw calldata bytes (max L::MAX_CALLDATA_BYTES bytes).
     * @return string Canonical invocation input.
     * @throws \InvalidArgumentException
     */
    public static function encodeCall(string $method, string $calldata): string
    {
        if (strlen($method) > L::MAX_METHOD_BYTES) {
            throw new \InvalidArgumentException(
                'Method exceeds maximum length of ' . L::MAX_METHOD_BYTES . ' bytes'
            );
        }
        if (strlen($calldata) > L::MAX_CALLDATA_BYTES) {
            throw new \InvalidArgumentException(
                'Calldata exceeds maximum length of ' . L::MAX_CALLDATA_BYTES . ' bytes'
            );
        }
        return self::encodeBytes($method) . self::encodeBytes($calldata);
    }

    /**
     * Encode a bytes field with a u32 BE length prefix.
     *
     * @param string $data
     * @return string 4 + strlen($data) bytes
     */
    public static function encodeBytes(string $data): string
    {
        return pack('N', strlen($data)) . $data;
    }

    /**
     * Encode a u32 as 4 big-endian bytes.
     */
    public static function encodeU32(int $value): string
    {
        if ($value < 0 || $value > 0xFFFFFFFF) {
            throw new \InvalidArgumentException('Value out of u32 range');
        }
        return pack('N', $value);
    }

    /**
     * Encode an i32 as 4 big-endian bytes (two's complement for negative).
     */
    public static function encodeI32(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }
}
