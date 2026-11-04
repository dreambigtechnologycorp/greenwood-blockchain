<?php
declare(strict_types=1);
namespace Greenwood\Compute\Runtime;

/**
 * Immutable resolved image reference.
 * 
 * When an image is pulled or verified, the mutable tag is resolved to an immutable digest.
 */
final readonly class ResolvedImage
{
    private const DIGEST_PREFIX = 'sha256:';
    private const DIGEST_LENGTH = 64;

    /**
     * @throws \InvalidArgumentException if digest format is invalid
     */
    public function __construct(
        private string $requestedReference,
        private string $resolvedDigest,
        private ?string $mediaType = null,
        private ?string $platform = null
    ) {
        if (!\str_starts_with($resolvedDigest, self::DIGEST_PREFIX)) {
            throw new \InvalidArgumentException(
                "Invalid digest format: {$resolvedDigest}. Must start with 'sha256:'"
            );
        }
        
        $hashPart = \substr($resolvedDigest, \strlen(self::DIGEST_PREFIX));
        
        if (!\ctype_xdigit($hashPart) || \strlen($hashPart) !== self::DIGEST_LENGTH) {
            throw new \InvalidArgumentException(
                "Invalid digest hash: {$hashPart}. Must be exactly 64 hex characters"
            );
        }
    }

    public function getRequestedReference(): string
    {
        return $this->requestedReference;
    }

    public function getResolvedDigest(): string
    {
        return $this->resolvedDigest;
    }

    public function getMediaType(): ?string
    {
        return $this->mediaType;
    }

    public function getPlatform(): ?string
    {
        return $this->platform;
    }

    /**
     * Check if the resolved digest matches a given reference.
     * 
     * This compares against a digest reference (not a tag).
     */
    public function matchesDigest(string $digest): bool
    {
        return $this->resolvedDigest === $digest;
    }

    /**
     * Create a ResolvedImage from a digest reference only.
     */
    public static function fromDigest(string $digest, ?string $platform = null): self
    {
        return new self(
            requestedReference: $digest,
            resolvedDigest: $digest,
            platform: $platform
        );
    }

    /**
     * Create a ResolvedImage from a mutable tag reference.
     */
    public static function fromTag(string $tag, string $digest, ?string $platform = null): self
    {
        return new self(
            requestedReference: $tag,
            resolvedDigest: $digest,
            platform: $platform
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requestedReference' => $this->requestedReference,
            'resolvedDigest' => $this->resolvedDigest,
            'mediaType' => $this->mediaType,
            'platform' => $this->platform,
        ];
    }
}
