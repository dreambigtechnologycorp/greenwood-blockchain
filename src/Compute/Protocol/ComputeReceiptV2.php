<?php
declare(strict_types=1);
namespace Greenwood\Compute\Protocol;

use Greenwood\Codec\Canonical as C;
use Greenwood\Compute\Codec\ComputeReceiptCodec;
use Greenwood\Crypto\{Ed25519KeyPair, ProtocolHash};

/** V2 owns its signature; the frozen V1 codec supplies shared field validation. */
final class ComputeReceiptV2
{
    public const ID_DOMAIN = 'GREENWOOD_COMPUTE_RECEIPT_V2';
    public const SIGN_DOMAIN = 'GREENWOOD_RECEIPT_SIGN_V2';
    private readonly string $bodyBytes;
    private string $signature = '';

    public function __construct(ComputeReceipt $body, public readonly int $generation)
    {
        if ($generation < 1 || $generation > 0xffffffff) throw new \InvalidArgumentException('Invalid receipt generation');
        $this->bodyBytes = $body->toUnsignedBytes();
    }

    /** A fresh value prevents callers from modifying stored receipt fields. */
    public function body(): ComputeReceipt { return ComputeReceiptCodec::decodeUnsigned($this->bodyBytes); }
    public function toUnsignedBytes(): string
    {
        return C::encodeU16BE(2) . substr($this->bodyBytes, 2, 32)
            . C::encodeU32BE($this->generation) . substr($this->bodyBytes, 34);
    }
    public function getId(): string { return ProtocolHash::hash(self::ID_DOMAIN, $this->toUnsignedBytes()); }
    public function getSigningDigest(): string { return ProtocolHash::hash(self::SIGN_DOMAIN, $this->toUnsignedBytes()); }
    public function sign(Ed25519KeyPair $key): self { return $this->setSignature($key->sign($this->getSigningDigest())); }
    public function setSignature(string $signature): self
    {
        if (strlen($signature) !== 64) throw new \InvalidArgumentException('Invalid V2 signature length');
        $this->signature = $signature;
        return $this;
    }
    public function getSignature(): string { return $this->signature; }
    public function verifySignature(string $publicKey): bool
    {
        return strlen($publicKey) === 32 && strlen($this->signature) === 64
            && ProviderId::fromPublicKey($publicKey)->toBytes() === $this->body()->getProviderId()
            && Ed25519KeyPair::verify($this->getSigningDigest(), $this->signature, $publicKey);
    }
    public function toSignedBytes(): string
    {
        if (strlen($this->signature) !== 64) throw new \InvalidArgumentException('Unsigned V2 receipt');
        return $this->toUnsignedBytes() . C::encodeU16BE(64) . $this->signature;
    }
}
