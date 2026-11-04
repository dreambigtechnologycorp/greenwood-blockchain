<?php
declare(strict_types=1);
namespace Greenwood\Compute\Protocol;

use Greenwood\Compute\Runtime\ExecutionStatus;

/**
 * Frozen wire encoding of the Pass 4A runtime execution statuses.
 *
 * This is NOT a second status enum: it is the canonical numeric projection
 * of Greenwood\Compute\Runtime\ExecutionStatus onto the compute protocol.
 * Semantics come from ExecutionStatus; only the encoding is added here.
 *
 * Numeric values are frozen and must never change:
 *   0 = COMPLETED
 *   1 = FAILED
 *   2 = TIMED_OUT
 *   3 = TERMINATED
 *   4 = RUNTIME_ERROR
 */
final class ComputeStatus
{
    public const COMPLETED = 0;
    public const FAILED = 1;
    public const TIMED_OUT = 2;
    public const TERMINATED = 3;
    public const RUNTIME_ERROR = 4;

    private const STRING_TO_INT = [
        ExecutionStatus::COMPLETED => self::COMPLETED,
        ExecutionStatus::FAILED => self::FAILED,
        ExecutionStatus::TIMED_OUT => self::TIMED_OUT,
        ExecutionStatus::TERMINATED => self::TERMINATED,
        ExecutionStatus::RUNTIME_ERROR => self::RUNTIME_ERROR,
    ];

    private function __construct() {}

    public static function check(int $value): int
    {
        if (!\in_array($value, \array_values(self::STRING_TO_INT), true)) {
            throw new \InvalidArgumentException('Invalid compute status: '.$value);
        }
        return $value;
    }

    public static function fromExecutionStatus(ExecutionStatus $status): int
    {
        return self::STRING_TO_INT[$status->getValue()];
    }

    public static function toExecutionStatus(int $value): ExecutionStatus
    {
        self::check($value);
        return ExecutionStatus::fromString(\array_search($value, self::STRING_TO_INT, true));
    }

    /**
     * Frozen exit-code relationship (Pass 4B section 15).
     * Null means absent only; there is no sentinel overloading.
     */
    public static function requiresExitCode(int $status): bool
    {
        return \in_array(self::check($status), [self::COMPLETED, self::FAILED], true);
    }

    public static function forbidsExitCode(int $status): bool
    {
        return self::check($status) === self::RUNTIME_ERROR;
    }
}
