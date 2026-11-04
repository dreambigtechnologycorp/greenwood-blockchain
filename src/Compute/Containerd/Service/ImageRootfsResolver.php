<?php

namespace Greenwood\Compute\Containerd\Service;

use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\Model\OciPlatform;
use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;
use Containerd\Services\Images\V1\Image;
use Containerd\Services\Snapshots\V1\Info;
use Containerd\Services\Snapshots\V1\Kind;

/**
 * Resolves the image rootfs parent snapshot key from an image reference
 *
 * This resolver follows the OCI/containerd chain-ID derivation:
 * 1. Get image manifest via target digest
 * 2. Get image config descriptor from manifest
 * 3. Fetch and decode image config
 * 4. Extract rootfs.diff_ids
 * 5. Derive chain ID from diff IDs
 * 6. Find committed snapshot with matching key
 */
class ImageRootfsResolver
{
    private ContainerdClientSet $clients;

    public function __construct(ContainerdClientSet $clients)
    {
        $this->clients = $clients;
    }

    /**
     * Resolve the image rootfs parent snapshot key
     *
     * @param Image $image The image to resolve
     * @param OciPlatform $platform The desired platform (default: host platform)
     * @return string The snapshot key of the committed image rootfs
     * @throws ContainerdException if the image is not unpacked or resolution fails
     */
    public function resolve(Image $image, ?OciPlatform $platform = null): string
    {
        if ($platform === null) {
            $platform = OciPlatform::fromHost();
        }

        $target = $image->getTarget();
        if (!$target) {
            throw new ContainerdException(
                "Image has no target descriptor",
                ContainerdException::CODE_VALIDATION
            );
        }

        $manifestDigest = $target->getDigest();
        if (empty($manifestDigest)) {
            throw new ContainerdException(
                "Image manifest has no digest",
                ContainerdException::CODE_VALIDATION
            );
        }

        // Fetch the manifest content
        $manifestContent = $this->fetchContent($manifestDigest);
        
        // Parse the manifest JSON
        $manifest = json_decode($manifestContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ContainerdException(
                "Failed to parse manifest JSON: " . json_last_error_msg(),
                ContainerdException::CODE_INVALID_ARGUMENT
            );
        }

        // Handle manifest list (index manifest) for multi-arch images
        $manifestDigestToUse = $manifestDigest;
        if (isset($manifest['manifests']) && is_array($manifest['manifests'])) {
            $selected = $this->selectPlatformManifest($manifest['manifests'], $platform);
            if ($selected === null) {
                throw new ContainerdException(
                    'No compatible image manifest for platform ' . $platform
                );
            }
            $manifestDigestToUse = $selected['digest'];
        }

        // Fetch the actual manifest content (either direct or child from index)
        $actualManifestContent = $this->fetchContent($manifestDigestToUse);
        
        // Parse the actual manifest JSON
        $actualManifest = json_decode($actualManifestContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ContainerdException(
                "Failed to parse actual manifest JSON: " . json_last_error_msg(),
                ContainerdException::CODE_INVALID_ARGUMENT
            );
        }

        // Get the config descriptor from the manifest
        $config = $actualManifest['config'] ?? null;
        if (!$config || empty($config['digest'])) {
            throw new ContainerdException(
                "Manifest has no config descriptor",
                ContainerdException::CODE_INVALID_ARGUMENT
            );
        }

        // Fetch the config content
        $configDigest = $config['digest'];
        $configContent = $this->fetchContent($configDigest);
        
        // Parse the config JSON
        $configData = json_decode($configContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ContainerdException(
                "Failed to parse config JSON: " . json_last_error_msg(),
                ContainerdException::CODE_INVALID_ARGUMENT
            );
        }

        // Extract rootfs diff_ids
        $rootfs = $configData['rootfs'] ?? null;
        if (!$rootfs || empty($rootfs['diff_ids'])) {
            throw new ContainerdException(
                "Config has no rootfs.diff_ids",
                ContainerdException::CODE_INVALID_ARGUMENT
            );
        }

        $diffIds = $rootfs['diff_ids'];
        if (count($diffIds) === 0) {
            throw new ContainerdException(
                "Cannot derive chain ID from empty diff IDs",
                ContainerdException::CODE_INVALID_ARGUMENT
            );
        }

        // Derive chain ID from diff IDs
        $chainId = $this->deriveChainId($diffIds);

        // Find committed snapshot using the chain ID
        $snapshotKey = $this->findSnapshotByKey($chainId);
        if (empty($snapshotKey)) {
            throw new ContainerdException(
                "Image rootfs snapshot not found for chain ID " . $chainId . "; image may not be unpacked for snapshotter overlayfs",
                ContainerdException::CODE_NOT_FOUND
            );
        }

        return $snapshotKey;
    }

    /**
     * Select the manifest that matches the desired platform
     *
     * @param array $manifests List of manifests from index
     * @param OciPlatform $platform Desired platform
     * @return array|null Matching manifest or null if none found
     */
    private function selectPlatformManifest(array $manifests, OciPlatform $platform): ?array
    {
        foreach ($manifests as $manifest) {
            $mediaType = $manifest['mediaType'] ?? '';
            $platformInfo = $manifest['platform'] ?? null;

            // Must be an image manifest (not index or attestation)
            $isImageManifest = $mediaType === 'application/vnd.oci.image.manifest.v1+json' ||
                               $mediaType === 'application/vnd.docker.image.manifest.v2+json';
            
            if (!$isImageManifest) {
                continue;
            }

            // Check platform match
            if ($platform->matches($platformInfo)) {
                return $manifest;
            }
        }

        return null;
    }

    /**
     * Fetch content from containerd content store
     */
    private function fetchContent(string $digest): string
    {
        $contentClient = $this->clients->getContent();
        
        $request = new \Containerd\Services\Content\V1\ReadContentRequest();
        $request->setDigest($digest);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            $stream = $contentClient->Read(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            );
            
            // Accumulate all response data from the stream
            $data = "";
            foreach ($stream->responses() as $response) {
                $data .= $response->getData();
            }
            
            // Get the final status
            $status = $stream->getStatus();

            if ($status->code !== \Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    "Failed to read content: " . $status->details,
                    $status->code
                );
            }

            return $data;
        } catch (\Grpc\Exception\RpcException $e) {
            throw new ContainerdRpcException(
                "Failed to fetch content: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Derive OCI chain ID from diff IDs
     *
     * For a single layer: chainID = diffID
     * For multiple layers: chainID = sha256(chainID + " " + diffID)
     * (containerd chain ID derivation)
     */
    private function deriveChainId(array $diffIds): string
    {
        if (count($diffIds) === 0) {
            throw new ContainerdException(
                "Cannot derive chain ID from empty diff IDs",
                ContainerdException::CODE_INVALID_ARGUMENT
            );
        }

        if (count($diffIds) === 1) {
            return $diffIds[0];
        }

        // Containerd chain ID derivation:
        // chainID[0] = diffID[0]
        // chainID[n] = sha256(chainID[n-1] + " " + diffID[n])
        $chainId = $diffIds[0];
        
        for ($i = 1; $i < count($diffIds); $i++) {
            $combined = $chainId . " " . $diffIds[$i];
            $chainId = "sha256:" . hash('sha256', $combined);
        }

        return $chainId;
    }

    /**
     * Find a committed snapshot by its key using stat
     */
    private function findSnapshotByKey(string $key): string
    {
        $snapshotService = new SnapshotService($this->clients);
        try {
            $info = $snapshotService->stat($key);
            if ($info->getKind() === Kind::COMMITTED) {
                return $key;
            }
        } catch (\Exception $e) {
            // Snapshot not found or error
        }

        return "";
    }
}
