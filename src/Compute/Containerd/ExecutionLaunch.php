<?php
declare(strict_types=1);
namespace Greenwood\Compute\Containerd;

/** Optional provider linkage; defaults keep the Pass 4A execution path unchanged. */
final readonly class ExecutionLaunch
{
    public function __construct(public string $runtimeId, public string $directory, public string $requestId, public int $generation, public int $retentionBytes, public ?\Closure $checkpoint = null)
    {
        if (!preg_match('/^native-[0-9a-f]{16}$/D', $runtimeId) || !str_starts_with($directory, '/')
            || strlen($requestId) !== 32 || $generation < 1 || $generation > 0xffffffff || $retentionBytes < 0 || $retentionBytes > 1048576) throw new \InvalidArgumentException('Invalid execution launch linkage');
    }
}
