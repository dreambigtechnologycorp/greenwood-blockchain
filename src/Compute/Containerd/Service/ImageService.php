<?php

namespace Greenwood\Compute\Containerd\Service;

use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\ResolvedImage;
use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;
use Containerd\Services\Images\V1\GetImageRequest;
use Containerd\Services\Images\V1\Image;

/**
 * Image service wrapper
 */
class ImageService
{
    private ContainerdClientSet $clients;

    public function __construct(ContainerdClientSet $clients)
    {
        $this->clients = $clients;
    }

    public function getNamespace(): string
    {
        return $this->clients->getConnection()->getNamespace();
    }

    /**
     * Get image information by reference
     * Note: containerd pulls images automatically when creating a task
     */
    public function get(string $reference): Image
    {
        $request = new GetImageRequest();
        $request->setName($reference);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getImages()->Get(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            if ($status->code !== \Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    "Get image failed: " . $status->details,
                    $status->code
                );
            }

            return $response->getImage();
        } catch (\Grpc\Exception\RpcException $e) {
            throw new ContainerdRpcException(
                "Failed to get image: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (ContainerdRpcException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ContainerdException(
                "Image get failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Fetch image metadata by reference
     * Note: containerd automatically pulls images when creating a task.
     * This method only retrieves existing image metadata.
     * 
     * @deprecated Use ImagePreparationService::ensureReady() for pulling via Transfer API
     */
    public function fetchMetadata(string $reference): Image
    {
        return $this->get($reference);
    }

    /**
     * Delete an image (idempotent)
     */
    public function delete(string $reference): void
    {
        $request = new \Containerd\Services\Images\V1\DeleteImageRequest();
        $request->setName($reference);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getImages()->Delete(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();
            // Delete is idempotent
        } catch (\Grpc\Exception\RpcException $e) {
            // If already not found, treat as success
            if ($e->getCode() !== \Grpc\STATUS_NOT_FOUND) {
                throw new ContainerdRpcException(
                    "Failed to delete image: " . $e->getMessage(),
                    $e->getCode(),
                    $e
                );
            }
        } catch (\Exception $e) {
            throw new ContainerdException(
                "Image deletion failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
