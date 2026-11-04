<?php

declare(strict_types=1);

namespace Greenwood\Codec;

/**
 * Canonical encoding for Greenwood protocol objects.
 * 
 * Ensures deterministic byte representation across all implementations.
 */
class Canonical
{
    /**
     * Encode an unsigned 16-bit integer in big-endian.
     * 
     * @param int $value
     * @return string 2-byte binary string
     */
    public static function encodeU16BE(int $value): string
    {
        if ($value < 0 || $value > 0xFFFF) {
            throw new \InvalidArgumentException('Value out of u16 range');
        }
        return pack('n', $value);
    }
    
    /**
     * Encode an unsigned 32-bit integer in big-endian.
     * 
     * @param int $value
     * @return string 4-byte binary string
     */
    public static function encodeU32BE(int $value): string
    {
        if ($value < 0 || $value > 0xFFFFFFFF) {
            throw new \InvalidArgumentException('Value out of u32 range');
        }
        return pack('N', $value);
    }
    
    /**
     * Encode an unsigned 64-bit integer in big-endian.
     * Note: PHP integers may overflow, so this uses string representation.
     * 
     * @param int|string $value
     * @return string 8-byte binary string
     */
    public static function encodeU64BE($value): string
    {
        if (!is_int($value)) throw new \InvalidArgumentException('Integer required');
        
        if ($value < 0) {
            throw new \InvalidArgumentException('Value must be non-negative');
        }
        
        // For values within PHP int range
        $high = ($value >> 32) & 0xFFFFFFFF;
        $low = $value & 0xFFFFFFFF;
        return pack('NN', $high, $low);
    }
    
    /**
     * Encode a byte array with length prefix.
     * 
     * @param string $data
     * @return string Length-prefixed bytes
     */
    public static function encodeBytes(string $data): string
    {
        return pack('N', strlen($data)) . $data;
    }
    
    /**
     * Encode a UTF-8 string with length prefix.
     * 
     * @param string $str
     * @return string Length-prefixed UTF-8 bytes
     */
    public static function encodeString(string $str): string
    {
        return pack('N', strlen($str)) . $str;
    }
    
    /**
     * Encode a boolean.
     * 
     * @param bool $value
     * @return string 1-byte (0x00 or 0x01)
     */
    public static function encodeBool(bool $value): string
    {
        return $value ? "\x01" : "\x00";
    }
    
    /**
     * Decode an unsigned 16-bit big-endian integer.
     * 
     * @param string $data 2-byte string
     * @return int
     */
    public static function decodeU16BE(string $data): int
    {
        if (strlen($data) !== 2) throw new \InvalidArgumentException('Expected u16');
        $values = unpack('n', $data);
        return $values[1];
    }
    
    /**
     * Decode an unsigned 32-bit big-endian integer.
     * 
     * @param string $data 4-byte string
     * @return int
     */
    public static function decodeU32BE(string $data): int
    {
        if (strlen($data) !== 4) throw new \InvalidArgumentException('Expected u32');
        $values = unpack('N', $data);
        return $values[1];
    }
    
    /**
     * Decode a length-prefixed byte array.
     * 
     * @param string $data Input data
     * @param int $offset Offset to read from
     * @return array ['data' => string, 'length' => int]
     */
    public static function decodeBytes(string $data, int &$offset = 0): array
    {
        $len = self::decodeU32BE(substr($data, $offset, 4));
        $offset += 4;
        
        if ($offset < 4 || $len > strlen($data) - $offset) throw new \InvalidArgumentException('Truncated bytes');
        $result = substr($data, $offset, $len);
        $offset += $len;
        
        return ['data' => $result, 'length' => $len];
    }
}
