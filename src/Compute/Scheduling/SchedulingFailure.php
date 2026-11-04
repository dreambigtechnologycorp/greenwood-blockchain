<?php
declare(strict_types=1);
namespace Greenwood\Compute\Scheduling;

/**
 * Scheduler diagnostics (Pass 4D section 34). Zero eligible providers leaves
 * the request unassigned; these are operational reasons, not new consensus
 * status enums. Generation is never incremented by scheduling failure.
 */
final class SchedulingFailure extends \InvalidArgumentException
{
    public const NO_PROVIDER = 'NO_PROVIDER';
    public const NO_ACTIVE_PROVIDER = 'NO_ACTIVE_PROVIDER';
    public const NO_TRUST_MATCH = 'NO_TRUST_MATCH';
    public const NO_ARCH_MATCH = 'NO_ARCH_MATCH';
    public const NO_CAPACITY = 'NO_CAPACITY';

    public function __construct(private string $reason)
    {
        parent::__construct('No eligible provider: '.$reason);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
