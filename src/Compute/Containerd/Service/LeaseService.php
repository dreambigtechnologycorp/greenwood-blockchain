<?php

declare(strict_types=1);

namespace Greenwood\Compute\Containerd\Service;

use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;
use Containerd\Services\Leases\V1\CreateRequest;
use Containerd\Services\Leases\V1\DeleteRequest;
use Containerd\Services\Leases\V1\Lease;

/**
 * Lease service wrapper for containerd.
 *
 * A lease pins metadata resources (snapshots, content, images) against
 * containerd's garbage collector. Every Greenwood execution creates one
 * lease covering the whole execution lifetime: without it, the ACTIVE
 * execution snapshot is unreferenced between Snapshot.Prepare and
 * Container.Create, and any concurrent lease deletion elsewhere in the
 * namespace (e.g. a Transfer job completing) triggers a GC pass that can
 * collect it — the reproduced concurrent rootfs/overlay race.
 */
class LeaseService
{
    private ContainerdClientSet $clients;

    public function __construct(ContainerdClientSet $clients)
    {
        $this->clients = $clients;
    }

    /**
     * Create a lease with the given ID (idempotent per ID).
     */
    public function create(string $leaseId): void
    {
        $lease = new Lease();
        $lease->setId($leaseId);
        $lease->setLabels(['greenwood.io/owner' => 'greenwood']);

        $request = new CreateRequest();
        $request->setId($leaseId);
        $request->setLabels(['greenwood.io/owner' => 'greenwood']);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getLeases()->Create(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            // ALREADY_EXISTS is fine: the lease is ours and protects equally.
            if ($status->code !== \Grpc\STATUS_OK && $status->code !== \Grpc\STATUS_ALREADY_EXISTS) {
                throw new ContainerdRpcException(
                    "Failed to create lease: " . $status->details,
                    $status->code
                );
            }
        } catch (\Grpc\Exception\RpcException $e) {
            if ($e->getCode() !== \Grpc\STATUS_ALREADY_EXISTS) {
                throw new ContainerdRpcException(
                    "Failed to create lease: " . $e->getMessage(),
                    $e->getCode(),
                    $e
                );
            }
        } catch (\Exception $e) {
            throw new ContainerdException(
                "Lease create failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Delete a lease (idempotent). Makes leased resources eligible for GC
     * again — call only after the execution's own resources are removed.
     */
    public function delete(string $leaseId): void
    {
        $request = new DeleteRequest();
        $request->setId($leaseId);
        $request->setSync(false);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getLeases()->Delete(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            if ($status->code !== \Grpc\STATUS_OK && $status->code !== \Grpc\STATUS_NOT_FOUND) {
                throw new ContainerdRpcException(
                    "Failed to delete lease: " . $status->details,
                    $status->code
                );
            }
        } catch (\Grpc\Exception\RpcException $e) {
            if ($e->getCode() !== \Grpc\STATUS_NOT_FOUND) {
                throw new ContainerdRpcException(
                    "Failed to delete lease: " . $e->getMessage(),
                    $e->getCode(),
                    $e
                );
            }
        } catch (\Exception $e) {
            throw new ContainerdException(
                "Lease delete failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
