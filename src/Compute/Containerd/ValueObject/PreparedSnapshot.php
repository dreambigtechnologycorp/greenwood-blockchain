<?php

namespace Greenwood\Compute\Containerd\ValueObject;

use Containerd\Services\Snapshots\V1\PrepareSnapshotResponse;
use Containerd\Types\Mount;

class PreparedSnapshot
{
    private string $key;
    private string $snapshotter;
    /** @var Mount[] */
    private array $mounts;

    public function __construct(string $key, string $snapshotter, array $mounts)
    {
        $this->key = $key;
        $this->snapshotter = $snapshotter;
        $this->mounts = $mounts;
    }

    public static function fromPrepareSnapshotResponse(
        PrepareSnapshotResponse $response,
        string $key,
        string $snapshotter
    ): self {
        // Convert RepeatedField to array
        $mounts = [];
        foreach ($response->getMounts() as $mount) {
            $mounts[] = $mount;
        }
        return new self($key, $snapshotter, $mounts);
    }

    public static function fromViewSnapshotResponse(
        \Containerd\Services\Snapshots\V1\ViewSnapshotResponse $response,
        string $key,
        string $snapshotter
    ): self {
        $mounts = [];

        foreach ($response->getMounts() as $mount) {
            $mounts[] = $mount;
        }

        return new self($key, $snapshotter, $mounts);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getSnapshotter(): string
    {
        return $this->snapshotter;
    }

    /**
     * @return Mount[]
     */
    public function getMounts(): array
    {
        return $this->mounts;
    }
}
