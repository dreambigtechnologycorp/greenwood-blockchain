<?php
declare(strict_types=1);
namespace Greenwood\Compute\Protocol;

/**
 * Frozen trust-class encoding for GREENWOOD_COMPUTE_V1.
 *
 * Numeric values are frozen and must never change:
 *   0 = OPEN       any eligible provider may execute
 *   1 = TRUSTED    provider must be in the trusted set
 *   2 = SOVEREIGN  provider must be a sovereign (validator-grade) provider
 *
 * Confidential Compute is intentionally absent from v1.
 */
final class TrustClass
{
    public const OPEN = 0;
    public const TRUSTED = 1;
    public const SOVEREIGN = 2;

    private function __construct() {}

    public static function isValid(int $value): bool
    {
        return \in_array($value, [self::OPEN, self::TRUSTED, self::SOVEREIGN], true);
    }

    public static function check(int $value): int
    {
        if (!self::isValid($value)) {
            throw new \InvalidArgumentException('Invalid trust class: '.$value);
        }
        return $value;
    }

    public static function name(int $value): string
    {
        return match (self::check($value)) {
            self::OPEN => 'OPEN',
            self::TRUSTED => 'TRUSTED',
            self::SOVEREIGN => 'SOVEREIGN',
        };
    }
}
