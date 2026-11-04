<?php

namespace Greenwood\Compute\Containerd\Service;

use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\ResolvedImage;
use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;
use Greenwood\Contracts\ContainerdUnavailableException;
use Containerd\Services\Containers\V1\CreateContainerRequest;
use Containerd\Services\Containers\V1\GetContainerRequest;
use Containerd\Services\Containers\V1\DeleteContainerRequest;
use Containerd\Services\Containers\V1\Container;
use Containerd\Services\Containers\V1\Container\Runtime;
use Google\Protobuf\Any;

/**
 * Container service wrapper for containerd
 */
class ContainerService
{
    private ContainerdClientSet $clients;
    private const PREFIX = "gw-exec-";
    private const DEFAULT_RUNTIME = "io.containerd.runc.v2";

    public function __construct(ContainerdClientSet $clients)
    {
        $this->clients = $clients;
    }

    public function getNamespace(): string
    {
        return $this->clients->getConnection()->getNamespace();
    }

    /**
     * Generate a container ID
     * @param string|null $executionId Optional execution ID to include in the container ID
     */
    public function generateContainerId(?string $executionId = null): string
    {
        if ($executionId) {
            return self::PREFIX . $executionId;
        }
        return self::PREFIX . bin2hex(random_bytes(8));
    }

    /**
     * Get the configured snapshotter from containerd
     */
    public function getSnapshotter(): string
    {
        return $this->clients->getSnapshotter();
    }

    /**
     * Create a container
     */
    public function create(Container $container): void
    {
        $request = new CreateContainerRequest();
        $request->setContainer($container);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getContainers()->Create(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            if ($status->code !== \Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    "Create container failed: " . $status->details,
                    $status->code
                );
            }
        } catch (\Grpc\Exception\RpcException $e) {
            throw new ContainerdRpcException(
                "Failed to create container: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (\Exception $e) {
            throw new ContainerdException(
                "Container creation failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get a container by ID
     */
    public function get(string $id): Container
    {
        $request = new GetContainerRequest();
        $request->setId($id);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getContainers()->Get(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            if ($status->code !== \Grpc\STATUS_OK) {
                if ($status->code === \Grpc\STATUS_NOT_FOUND) {
                    throw new ContainerdException(
                        "Container not found: " . $id,
                        \Grpc\STATUS_NOT_FOUND
                    );
                }
                throw new ContainerdRpcException(
                    "Get container failed: " . $status->details,
                    $status->code
                );
            }

            return $response->getContainer();
        } catch (\Grpc\Exception\RpcException $e) {
            throw new ContainerdRpcException(
                "Failed to get container: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (\Exception $e) {
            throw new ContainerdException(
                "Container get failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Delete a container (idempotent)
     */
    public function delete(string $id): void
    {
        $request = new DeleteContainerRequest();
        $request->setId($id);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getContainers()->Delete(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            // Delete is idempotent - success even if container doesn't exist
        } catch (\Grpc\Exception\RpcException $e) {
            // If already not found, treat as success
            if ($e->getCode() !== \Grpc\STATUS_NOT_FOUND) {
                throw new ContainerdRpcException(
                    "Failed to delete container: " . $e->getMessage(),
                    $e->getCode(),
                    $e
                );
            }
        } catch (\Exception $e) {
            throw new ContainerdException(
                "Container deletion failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Create a Container protobuf from image and snapshot info
     */
    public function buildContainer(
        string $id,
        ResolvedImage $image,
        string $snapshotKey,
        Any $spec
    ): Container
    {
        $container = new Container();
        $container->setId($id);
        $container->setImage($image->getFullReference());
        $container->setSnapshotKey($snapshotKey);
        $container->setSnapshotter($this->getSnapshotter());
        $container->setSpec($spec);

        $runtime = new Runtime();
        $runtime->setName(self::DEFAULT_RUNTIME);
        $container->setRuntime($runtime);

        return $container;
    }
}
