<?php
declare(strict_types=1);
namespace Greenwood\Compute\Scheduling;

/**
 * Provider eligibility state for scheduling (Pass 4D section 5).
 *
 * ACTIVE    -> may receive new assignments
 * DRAINING  -> finish current work, no new assignments
 * SUSPENDED -> no assignments
 *
 * This is protocol scheduling state only. Temporary unreachability is
 * operational liveness and is deliberately not encoded here.
 */
final class ProviderStatus
{
    public const ACTIVE = 'ACTIVE';
    public const DRAINING = 'DRAINING';
    public const SUSPENDED = 'SUSPENDED';

    private function __construct() {}

    public static function isValid(string $value): bool
    {
        return \in_array($value, [self::ACTIVE, self::DRAINING, self::SUSPENDED], true);
    }

    public static function check(string $value): string
    {
        if (!self::isValid($value)) {
            throw new \InvalidArgumentException('Invalid provider status: '.$value);
        }
        return $value;
    }
}
