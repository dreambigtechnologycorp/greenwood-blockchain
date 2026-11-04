<?php
declare(strict_types=1);
namespace Greenwood\Compute\Protocol;

use Greenwood\Crypto\ProtocolHash;

/**
 * Compute provider identity, frozen for GREENWOOD_COMPUTE_V1.
 *
 * provider_id = H("GREENWOOD_PROVIDER_V1", raw_ed25519_public_key)
 *
 * The provider ID is bound to an Ed25519 public key using exactly the
 * Greenwood account-address construction, with a distinct domain so
 * provider IDs and account addresses can never collide.
 */
final class ProviderId
{
    public const DOMAIN = 'GREENWOOD_PROVIDER_V1';
    public const LENGTH = 32;

    private function __construct(private readonly string $id)
    {
        if (strlen($id) !== self::LENGTH) {
            throw new \InvalidArgumentException('Provider ID must be 32 bytes');
        }
    }

    public static function fromPublicKey(string $publicKey): self
    {
        if (strlen($publicKey) !== 32) {
            throw new \InvalidArgumentException('Ed25519 public key must be 32 bytes');
        }
        return new self(ProtocolHash::hash(self::DOMAIN, $publicKey));
    }

    public static function fromBytes(string $id): self
    {
        return new self($id);
    }

    /** Verify that $publicKey is the key material behind this provider ID. */
    public function matchesPublicKey(string $publicKey): bool
    {
        return strlen($publicKey) === 32
            && hash_equals($this->id, ProtocolHash::hash(self::DOMAIN, $publicKey));
    }

    public function toBytes(): string
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return bin2hex($this->id);
    }
}
