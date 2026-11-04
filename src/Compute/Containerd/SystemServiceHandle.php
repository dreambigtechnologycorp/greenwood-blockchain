<?php

declare(strict_types=1);

namespace Greenwood\Compute\Containerd;

final readonly class SystemServiceHandle
{
    public function __construct(
        public string $serviceId,
        public string $imageReference,
        public string $imageDigest,
        public string $containerId,
        public string $viewKey,
        public string $parentSnapshot,
        public string $leaseId,
        public int $pid
    ) {
    }
}
