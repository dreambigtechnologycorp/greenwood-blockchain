<?php
declare(strict_types=1);
namespace Greenwood\Compute\Protocol;

/**
 * GREENWOOD_COMPUTE_V1 protocol constants. Frozen for v1.
 * New protocol versions require new domains, new codecs and new vectors.
 */
final class ComputeProtocol
{
    public const VERSION = 1;
    public const VERSION_NAME = 'GREENWOOD_COMPUTE_V1';

    private function __construct() {}
}
