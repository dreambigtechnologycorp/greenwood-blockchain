<?php
declare(strict_types=1);
namespace Greenwood\Compute\Protocol;

/**
 * Request lifecycle for protocol-level replay protection (Pass 4B section 19).
 *
 * PENDING  -> request registered, not yet assigned
 * ASSIGNED -> assigned on-chain to exactly one provider
 * TERMINAL -> a terminal receipt has been accepted; no second terminal
 *             receipt can ever be accepted for this request
 */
final class RequestState
{
    public const PENDING = 'PENDING';
    public const ASSIGNED = 'ASSIGNED';
    public const TERMINAL = 'TERMINAL';

    private function __construct() {}
}
