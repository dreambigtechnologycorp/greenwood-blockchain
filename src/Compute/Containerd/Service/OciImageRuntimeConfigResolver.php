<?php

declare(strict_types=1);

namespace Greenwood\Compute\Containerd\Service;

use Containerd\Services\Content\V1\ReadContentRequest;
use Containerd\Services\Images\V1\Image;
use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\Model\OciPlatform;
use Greenwood\Compute\Containerd\OciImageRuntimeConfig;
use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;

final class OciImageRuntimeConfigResolver
{
    public function __construct(
        private ContainerdClientSet $clients
    ) {
    }

    public function resolve(
        Image $image,
        ?OciPlatform $platform = null
    ): OciImageRuntimeConfig {
        $platform ??= OciPlatform::fromHost();

        $target = $image->getTarget();

        if ($target === null) {
            throw new ContainerdException(
                'Image has no target descriptor',
                ContainerdException::CODE_VALIDATION
            );
        }

        $manifestDigest = $target->getDigest();

        $this->assertDigest(
            $manifestDigest,
            'image manifest'
        );

        $manifest =
            $this->decodeDocument(
                $this->fetchContent(
                    $manifestDigest
                ),
                'image manifest'
            );

        $actualManifestDigest =
            $this->selectManifestDigest(
                $manifest,
                $manifestDigest,
                $platform
            );

        $actualManifest =
            $actualManifestDigest === $manifestDigest
                ? $manifest
                : $this->decodeDocument(
                    $this->fetchContent(
                        $actualManifestDigest
                    ),
                    'platform image manifest'
                );

        $configDescriptor =
            $actualManifest['config'] ?? null;

        if (
            !is_array($configDescriptor)
            || !isset($configDescriptor['digest'])
            || !is_string(
                $configDescriptor['digest']
            )
        ) {
            throw new ContainerdException(
                'Manifest has no config descriptor',
                ContainerdException::CODE_INVALID_ARGUMENT
            );
        }

        $configDigest =
            $configDescriptor['digest'];

        $this->assertDigest(
            $configDigest,
            'image config'
        );

        $configDocument =
            $this->decodeDocument(
                $this->fetchContent(
                    $configDigest
                ),
                'image config'
            );

        return OciImageRuntimeConfig::fromDocument(
            $configDocument
        );
    }

    private function selectManifestDigest(
        array $manifest,
        string $defaultDigest,
        OciPlatform $platform
    ): string {
        $manifests =
            $manifest['manifests'] ?? null;

        if ($manifests === null) {
            return $defaultDigest;
        }

        if (
            !is_array($manifests)
            || !array_is_list($manifests)
        ) {
            throw new ContainerdException(
                'Invalid OCI manifest index',
                ContainerdException::CODE_INVALID_ARGUMENT
            );
        }

        foreach ($manifests as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $mediaType =
                $candidate['mediaType'] ?? '';

            if (
                $mediaType
                    !== 'application/vnd.oci.image.manifest.v1+json'
                && $mediaType
                    !== 'application/vnd.docker.image.manifest.v2+json'
            ) {
                continue;
            }

            $platformData =
                $candidate['platform'] ?? null;

            if (
                !is_array($platformData)
                || !$platform->matches(
                    $platformData
                )
            ) {
                continue;
            }

            $digest =
                $candidate['digest'] ?? null;

            if (!is_string($digest)) {
                throw new ContainerdException(
                    'Platform manifest has no digest',
                    ContainerdException::CODE_INVALID_ARGUMENT
                );
            }

            $this->assertDigest(
                $digest,
                'platform manifest'
            );

            return $digest;
        }

        throw new ContainerdException(
            'No compatible image manifest for platform '
                . $platform,
            ContainerdException::CODE_INVALID_ARGUMENT
        );
    }

    private function fetchContent(
        string $digest
    ): string {
        $request =
            new ReadContentRequest();

        $request->setDigest($digest);

        try {
            $stream =
                $this->clients
                    ->getContent()
                    ->Read(
                        $request,
                        $this->clients
                            ->getConnection()
                            ->getMetadata(),
                        [
                            'timeout' =>
                                30_000_000,
                        ]
                    );

            $data = '';

            foreach (
                $stream->responses()
                as $response
            ) {
                $data .=
                    $response->getData();
            }

            $status =
                $stream->getStatus();

            if (
                $status->code
                !== \Grpc\STATUS_OK
            ) {
                throw new ContainerdRpcException(
                    'Failed to read OCI content: '
                        . $status->details,
                    $status->code
                );
            }

            return $data;
        } catch (
            \Grpc\Exception\RpcException $e
        ) {
            throw new ContainerdRpcException(
                'Failed to read OCI content: '
                    . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    private function decodeDocument(
        string $json,
        string $description
    ): array {
        try {
            $document =
                json_decode(
                    $json,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
        } catch (\JsonException $e) {
            throw new ContainerdException(
                'Invalid '
                    . $description
                    . ' JSON: '
                    . $e->getMessage(),
                ContainerdException::CODE_INVALID_ARGUMENT,
                $e
            );
        }

        if (!is_array($document)) {
            throw new ContainerdException(
                'Invalid '
                    . $description
                    . ' document',
                ContainerdException::CODE_INVALID_ARGUMENT
            );
        }

        return $document;
    }

    private function assertDigest(
        string $digest,
        string $description
    ): void {
        if (
            !preg_match(
                '/^sha256:[a-f0-9]{64}$/D',
                $digest
            )
        ) {
            throw new ContainerdException(
                'Invalid '
                    . $description
                    . ' digest',
                ContainerdException::CODE_VALIDATION
            );
        }
    }
}
