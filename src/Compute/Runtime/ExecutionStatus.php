<?php
declare(strict_types=1);
namespace Greenwood\Compute\Runtime;

/**
 * Immutable execution status enum.
 * 
 * These statuses represent the terminal state of an execution.
 * They are not used for intermediate states like "running".
 */
final class ExecutionStatus
{
    public const COMPLETED = 'COMPLETED';
    public const FAILED = 'FAILED';
    public const TIMED_OUT = 'TIMED_OUT';
    public const TERMINATED = 'TERMINATED';
    public const RUNTIME_ERROR = 'RUNTIME_ERROR';

    private string $value;

    private function __construct(string $value)
    {
        $allowed = [
            self::COMPLETED,
            self::FAILED,
            self::TIMED_OUT,
            self::TERMINATED,
            self::RUNTIME_ERROR,
        ];
        
        if (!\in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Invalid execution status: {$value}. Must be one of: " . implode(', ', $allowed)
            );
        }
        
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function isCompleted(): bool
    {
        return $this->value === self::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->value === self::FAILED;
    }

    public function isTimedOut(): bool
    {
        return $this->value === self::TIMED_OUT;
    }

    public function isTerminated(): bool
    {
        return $this->value === self::TERMINATED;
    }

    public function isError(): bool
    {
        return $this->value === self::RUNTIME_ERROR;
    }

    public function isSuccess(): bool
    {
        return $this->value === self::COMPLETED;
    }

    public static function completed(): self
    {
        return new self(self::COMPLETED);
    }

    public static function failed(): self
    {
        return new self(self::FAILED);
    }

    public static function timedOut(): self
    {
        return new self(self::TIMED_OUT);
    }

    public static function terminated(): self
    {
        return new self(self::TERMINATED);
    }

    public static function runtimeError(): self
    {
        return new self(self::RUNTIME_ERROR);
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
