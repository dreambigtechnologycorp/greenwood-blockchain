<?php
declare(strict_types=1);

namespace Greenwood\Compute\Containerd\Service;

use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\Model\OciPlatform;
use Greenwood\Compute\Containerd\ResolvedImage;
use Greenwood\Compute\Containerd\RegistryTransferAuth;
use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;
use Containerd\Services\Transfer\V1\TransferRequest;
use Containerd\Types\Transfer\OCIRegistry;
use Containerd\Types\Transfer\RegistryResolver;
use Containerd\Types\Transfer\ImageStore;
use Containerd\Types\Transfer\UnpackConfiguration;
use Containerd\Types\Platform;
use Google\Protobuf\Any;

class ImagePreparationService
{
    private ContainerdClientSet $clients;
    private ImageService $imageService;
    private ImageRootfsResolver $rootfsResolver;

    public function __construct(ContainerdClientSet $clients)
    {
        $this->clients = $clients;
        $this->imageService = new ImageService($clients);
        $this->rootfsResolver = new ImageRootfsResolver($clients);
    }

    /**
     * Ensure the image is pulled and unpacked
     *
     * Uses the native gRPC Transfer service, then verifies the committed rootfs.
     *
     * @param string $imageRef OCI image reference (e.g., docker.io/library/alpine:latest)
     * @return ResolvedImage The resolved image with its digest and platform
     * @throws ContainerdException if image preparation fails
     */
    public function ensureReady(
        string $imageRef,
        ?RegistryTransferAuth $registryAuth = null
    ): ResolvedImage
    {
        $hostPlatform = OciPlatform::fromHost();

        // Local-first: imported/prewarmed images with a committed rootfs for the
        // configured snapshotter are already ready. Seed/system services must
        // not require the registry in order to bootstrap the registry itself.
        try {
            return $this->resolveLocal($imageRef, $hostPlatform);
        } catch (ContainerdRpcException $e) {
            // Image metadata genuinely absent from local containerd state.
            if ($e->getCode() !== ContainerdRpcException::RPC_NOT_FOUND) {
                throw $e;
            }
        } catch (ContainerdException $e) {
            // Image metadata exists, but its committed rootfs is not present
            // for the configured snapshotter.
            if ($e->getCode() !== ContainerdException::CODE_NOT_FOUND) {
                throw $e;
            }
        }

        // Genuine local miss only: use the registry transfer path, including
        // unpack into the provider-configured snapshotter.
        $this->ensureReadyWithTransfer(
            $imageRef,
            $hostPlatform,
            $registryAuth
        );

        // Re-read and re-validate the transferred image through the same local
        // readiness path. Transfer success alone is not proof of usable rootfs.
        return $this->resolveLocal($imageRef, $hostPlatform);
    }

    /**
     * Resolve an image entirely from local containerd state.
     *
     * Requires both image/content metadata and a COMMITTED rootfs snapshot for
     * the configured snapshotter. This method never invokes the Transfer API.
     */
    private function resolveLocal(string $imageRef, OciPlatform $hostPlatform): ResolvedImage
    {
        $image = $this->imageService->get($imageRef);

        $target = $image->getTarget();
        if (!$target) {
            throw new ContainerdException(
                "Image has no target descriptor",
                ContainerdException::CODE_VALIDATION
            );
        }

        $digest = $target->getDigest();
        if (!preg_match('/^sha256:[a-f0-9]{64}$/D', $digest)) {
            throw new ContainerdException(
                "Image manifest has no valid digest",
                ContainerdException::CODE_VALIDATION
            );
        }

        $rootfsParent = $this->rootfsResolver->resolve($image, $hostPlatform);
        if (empty($rootfsParent)) {
            throw new ContainerdException(
                "Failed to resolve rootfs parent for image",
                ContainerdException::CODE_INVALID_ARGUMENT
            );
        }

        return new ResolvedImage(
            $imageRef,
            $image->getName(),
            $digest,
            $target->getMediaType(),
            $hostPlatform->__toString()
        );
    }

    /**
     * Ensure the image is pulled via Transfer API
     *
     * @param string $imageRef OCI image reference
     * @param OciPlatform $hostPlatform The host platform
     * @return void
     * @throws ContainerdException if Transfer fails
     */
    private function ensureReadyWithTransfer(
        string $imageRef,
        OciPlatform $hostPlatform,
        ?RegistryTransferAuth $registryAuth = null
    ): void
    {
        // Construct the source endpoint: OCIRegistry pointing to the image reference
        $registry = new OCIRegistry();
        $registry->setReference($imageRef);

        if ($registryAuth !== null) {
            $resolver = new RegistryResolver();
            $resolver->setHeaders([
                'Authorization' =>
                    $registryAuth->authorizationHeader(),
            ]);
            $resolver->setDefaultScheme(
                $registryAuth->scheme()
            );
            $registry->setResolver($resolver);
        }

        // Pack the source endpoint into Any
        $sourceAny = new Any();
        $sourceAny->pack($registry);
        // containerd/typeurl's transfer registry uses bare protobuf full names.
        // PHP's default type.googleapis.com/ prefix misses that exact lookup.
        $sourceAny->setTypeUrl('containerd.types.transfer.OCIRegistry');

        // Construct the destination endpoint: ImageStore
        $imageStore = new ImageStore();
        $imageStore->setName($imageRef);
        
        // Set platforms filter - use host platform
        $platform = new Platform();
        $platform->setOs($hostPlatform->os);
        $platform->setArchitecture($hostPlatform->architecture);
        if ($hostPlatform->variant !== null) {
            $platform->setVariant($hostPlatform->variant);
        }
        $imageStore->setPlatforms([$platform]);
        
        // Set unpack configuration
        $unpackConfig = new UnpackConfiguration();
        $unpackConfig->setPlatform($platform);
        $unpackConfig->setSnapshotter($this->clients->getSnapshotter());
        $imageStore->setUnpacks([$unpackConfig]);
        
        // Pack the destination endpoint into Any
        $destinationAny = new Any();
        $destinationAny->pack($imageStore);
        $destinationAny->setTypeUrl('containerd.types.transfer.ImageStore');

        // Construct the TransferRequest
        $request = new TransferRequest();
        $request->setSource($sourceAny);
        $request->setDestination($destinationAny);

        // Call the Transfer service
        $transferClient = $this->clients->getTransfer();
        $metadata = $this->clients->getConnection()->getMetadata();

        try {
            [$response, $status] = $transferClient->Transfer(
                $request,
                $metadata,
                ["timeout" => 300_000_000]  // 5 minute timeout
            )->wait();

            if ($status->code !== \Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    "Transfer failed: " . $status->details,
                    $status->code
                );
            }
        } catch (ContainerdRpcException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ContainerdException(
                "Transfer RPC failed: " . $e->getMessage(),
                ContainerdException::CODE_UNAVAILABLE,
                $e
            );
        }

    }
}
