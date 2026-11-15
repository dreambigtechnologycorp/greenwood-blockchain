<?php
declare(strict_types=1);
namespace Greenwood\Economics;

/** All native economic values are checked integer base units, never floats. */
final class Amount
{
    public const DECIMALS = 6;
    public const SCALE = 1_000_000;
    public const ISSUANCE = 1_000_000_000_000_000;

    public static function parse(string $value): int
    {
        if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,6}))?$/D', $value, $m)) throw new \InvalidArgumentException('Invalid GWD decimal');
        $digits = ltrim($m[1] . str_pad($m[2] ?? '', 6, '0'), '0');
        return self::integer($digits === '' ? '0' : $digits);
    }
    public static function integer(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value)
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) throw new \InvalidArgumentException('Invalid/overflowing base units');
        return (int) $value;
    }
    public static function add(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || $a > PHP_INT_MAX - $b) throw new \InvalidArgumentException('Amount overflow');
        return $a + $b;
    }
    public static function multiply(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || ($b !== 0 && $a > intdiv(PHP_INT_MAX, $b))) throw new \InvalidArgumentException('Amount overflow');
        return $a * $b;
    }
    /** floor(a*b/d), with bounded intermediate values and checked result. */
    public static function mulDiv(int $a, int $b, int $d, bool $ceil = false): int
    {
        if ($a < 0 || $b < 0 || $d <= 0) throw new \InvalidArgumentException('Invalid conversion');
        $q = 0; $r = 0; $bits = decbin($b);
        foreach (str_split($bits) as $bit) {
            $q = self::multiply($q, 2);
            if ($r >= $d - $r) { $r -= $d - $r; $q = self::add($q, 1); } else $r += $r;
            if ($bit === '1') {
                $q = self::add($q, intdiv($a, $d)); $part = $a % $d;
                if ($r >= $d - $part) { $r -= $d - $part; $q = self::add($q, 1); } else $r += $part;
            }
        }
        return $ceil && $r !== 0 ? self::add($q, 1) : $q;
    }
}
