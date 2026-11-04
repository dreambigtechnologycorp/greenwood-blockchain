<?php

namespace Greenwood\Compute\Containerd\Service;

use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\ValueObject\PreparedSnapshot;
use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;
use Greenwood\Contracts\ContainerdUnavailableException;
use Containerd\Services\Snapshots\V1\PrepareSnapshotRequest;
use Containerd\Services\Snapshots\V1\PrepareSnapshotResponse;
use Containerd\Services\Snapshots\V1\StatSnapshotRequest;
use Containerd\Services\Snapshots\V1\RemoveSnapshotRequest;
use Containerd\Services\Snapshots\V1\StatSnapshotResponse;
use Containerd\Services\Snapshots\V1\ListSnapshotsRequest;
use Containerd\Services\Snapshots\V1\Info;

/**
 * Snapshot service wrapper for containerd
 */
class SnapshotService
{
    private ContainerdClientSet $clients;
    private const PREFIX = "gw-snapshot-";

    public function __construct(ContainerdClientSet $clients)
    {
        $this->clients = $clients;
    }

    public function getNamespace(): string
    {
        return $this->clients->getConnection()->getNamespace();
    }

    public function generateSnapshotId(string $executionId): string
    {
        return self::PREFIX . $executionId;
    }

    /**
     * Get the configured snapshotter from containerd
     */
    public function getSnapshotter(): string
    {
        return $this->clients->getSnapshotter();
    }

    /**
     * Prepare a snapshot.
     *
     * When $leaseId is provided, the prepared snapshot is associated with
     * that lease (containerd-lease gRPC header) so the garbage collector
     * cannot collect it while it is unreferenced by a container.
     */
    public function prepare(string $key, string $parent = "", ?string $leaseId = null): PreparedSnapshot
    {
        $snapshotter = $this->getSnapshotter();
        
        $request = new PrepareSnapshotRequest();
        $request->setSnapshotter($snapshotter);
        $request->setKey($key);
        $request->setParent($parent);
        $request->setLabels([]);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            if ($leaseId !== null) {
                $metadata['containerd-lease'] = [$leaseId];
            }
            [$response, $status] = $this->clients->getSnapshots()->Prepare(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            if ($status->code !== \Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    "Failed to prepare snapshot: " . $status->details,
                    $status->code
                );
            }

            return PreparedSnapshot::fromPrepareSnapshotResponse($response, $key, $snapshotter);
        } catch (\Grpc\Exception\RpcException $e) {
            throw new ContainerdRpcException(
                "Failed to prepare snapshot: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (\Exception $e) {
            throw new ContainerdException(
                "Snapshot prepare failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * List all snapshots for the configured snapshotter
     *
     * @return Info[] Array of snapshot info
     */
    public function view(
        string $key,
        string $parent,
        ?string $leaseId = null
    ): PreparedSnapshot {
        if ($parent === '') {
            throw new \InvalidArgumentException(
                'Read-only snapshot view requires a committed parent'
            );
        }

        $snapshotter = $this->getSnapshotter();

        $request = new \Containerd\Services\Snapshots\V1\ViewSnapshotRequest();
        $request->setSnapshotter($snapshotter);
        $request->setKey($key);
        $request->setParent($parent);
        $request->setLabels([]);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();

            if ($leaseId !== null) {
                $metadata['containerd-lease'] = [$leaseId];
            }

            [$response, $status] = $this->clients
                ->getSnapshots()
                ->View(
                    $request,
                    $metadata,
                    ['timeout' => 30_000_000]
                )
                ->wait();

            if ($status->code !== \Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    'Failed to create read-only snapshot view: '
                    . $status->details,
                    $status->code
                );
            }

            return PreparedSnapshot::fromViewSnapshotResponse(
                $response,
                $key,
                $snapshotter
            );
        } catch (\Grpc\Exception\RpcException $e) {
            throw new ContainerdRpcException(
                'Failed to create read-only snapshot view: '
                . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (ContainerdRpcException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ContainerdException(
                'Snapshot view failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function list(): array
    {
        $snapshotter = $this->getSnapshotter();
        
        $request = new ListSnapshotsRequest();
        $request->setSnapshotter($snapshotter);
        $request->setFilters([]);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();

            // Snapshots.List is a SERVER-STREAMING RPC: iterate the
            // response stream, then inspect the final status.
            $call = $this->clients->getSnapshots()->List(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            );

            $infos = [];
            foreach ($call->responses() as $response) {
                foreach ($response->getInfo() as $info) {
                    $infos[] = $info;
                }
            }

            $status = $call->getStatus();
            if ($status->code !== \Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    "Failed to list snapshots: " . $status->details,
                    $status->code
                );
            }

            return $infos;
        } catch (\Grpc\Exception\RpcException $e) {
            throw new ContainerdRpcException(
                "Failed to list snapshots: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (\Exception $e) {
            throw new ContainerdException(
                "Snapshot list failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Stat a snapshot (get its info)
     */
    public function stat(string $key): Info
    {
        $snapshotter = $this->getSnapshotter();
        
        $request = new StatSnapshotRequest();
        $request->setSnapshotter($snapshotter);
        $request->setKey($key);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getSnapshots()->Stat(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            if ($status->code !== \Grpc\STATUS_OK) {
                if ($status->code === \Grpc\STATUS_NOT_FOUND) {
                    throw new ContainerdException(
                        "Snapshot not found: " . $key,
                        \Grpc\STATUS_NOT_FOUND
                    );
                }
                throw new ContainerdRpcException(
                    "Stat snapshot failed: " . $status->details,
                    $status->code
                );
            }

            return $response->getInfo();
        } catch (\Grpc\Exception\RpcException $e) {
            if ($e->getCode() === \Grpc\STATUS_NOT_FOUND) {
                throw new ContainerdException(
                    "Snapshot not found: " . $key,
                    \Grpc\STATUS_NOT_FOUND
                );
            }
            throw new ContainerdRpcException(
                "Failed to stat snapshot: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (\Exception $e) {
            throw new ContainerdException(
                "Snapshot stat failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Remove a snapshot (idempotent)
     */
    public function remove(string $key): void
    {
        $snapshotter = $this->getSnapshotter();
        
        $request = new RemoveSnapshotRequest();
        $request->setSnapshotter($snapshotter);
        $request->setKey($key);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getSnapshots()->Remove(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            // Remove is idempotent - success even if snapshot doesn't exist
        } catch (\Grpc\Exception\RpcException $e) {
            // If already not found, treat as success
            if ($e->getCode() !== \Grpc\STATUS_NOT_FOUND) {
                throw new ContainerdRpcException(
                    "Failed to remove snapshot: " . $e->getMessage(),
                    $e->getCode(),
                    $e
                );
            }
        } catch (\Exception $e) {
            throw new ContainerdException(
                "Snapshot removal failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

}
