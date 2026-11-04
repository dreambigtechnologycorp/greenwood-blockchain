<?php

namespace Greenwood\Compute\Containerd;

/**
 * Represents an image that has been resolved to its immutable digest
 */
class ResolvedImage
{
    private string $requestedReference;
    private string $resolvedName;
    private string $digest;
    private string $mediaType;
    private string $platform;

    public function __construct(
        string $requestedReference,
        string $resolvedName,
        string $digest,
        string $mediaType,
        string $platform
    ) {
        // Validate digest format: sha256:<64 lowercase hex>
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $digest)) {
            throw new \InvalidArgumentException(
                "Invalid digest format: {$digest}. Expected sha256:<64 lowercase hex>"
            );
        }
        
        $this->requestedReference = $requestedReference;
        $this->resolvedName = $resolvedName;
        $this->digest = $digest;
        $this->mediaType = $mediaType;
        $this->platform = $platform;
    }

    public function getRequestedReference(): string
    {
        return $this->requestedReference;
    }

    public function getResolvedName(): string
    {
        return $this->resolvedName;
    }

    public function getDigest(): string
    {
        return $this->digest;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    /**
     * Get the full image reference with immutable digest
     */
    public function getFullReference(): string
    {
        return explode("@", $this->resolvedName, 2)[0] . "@" . $this->digest;
    }
}
