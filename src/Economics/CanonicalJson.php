<?php
declare(strict_types=1);
namespace Greenwood\Economics;

/** Canonical subset: integers, booleans, strings, null, lists, sorted maps; no floats. */
final class CanonicalJson
{
    private static function normalize(mixed $v, int $depth = 0): mixed
    {
        if ($depth > 24 || is_float($v) || is_object($v) || is_resource($v)) throw new \InvalidArgumentException('Unsupported canonical value');
        if (!is_array($v)) return $v;
        if (count($v) > 20000) throw new \InvalidArgumentException('Canonical collection too large');
        if (!array_is_list($v)) ksort($v, SORT_STRING);
        foreach ($v as $key => $value) $v[$key] = self::normalize($value, $depth + 1);
        return $v;
    }
    public static function encode(array $value): string
    {
        return json_encode(self::normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    public static function decode(string $bytes): array
    {
        if (strlen($bytes) > 65536) throw new \InvalidArgumentException('Economic payload too large');
        try { $v = json_decode($bytes, true, 25, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new \InvalidArgumentException('Invalid economic JSON', 0, $e); }
        if (!is_array($v) || self::encode($v) !== $bytes) throw new \InvalidArgumentException('Noncanonical economic payload');
        return $v;
    }
}
