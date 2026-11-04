<?php
declare(strict_types=1);
namespace Greenwood\Compute\Protocol;

use Greenwood\Codec\{Canonical as C, Reader};

final readonly class ComputeAssign
{
    public function __construct(
        public string $requestId,
        public string $providerId,
        public string $providerPublicKey,
        public int $generation,
        public int $deadline,
    ) {
        if (strlen($requestId) !== 32 || strlen($providerPublicKey) !== 32
            || ProviderId::fromPublicKey($providerPublicKey)->toBytes() !== $providerId
            || $generation < 1 || $generation > 0xffffffff || $deadline < 0) {
            throw new \InvalidArgumentException('Invalid compute assignment');
        }
    }

    public function toBytes(): string
    {
        return C::encodeU16BE(1) . $this->requestId . $this->providerId
            . $this->providerPublicKey . C::encodeU32BE($this->generation) . C::encodeU64BE($this->deadline);
    }

    public static function decode(string $bytes): self
    {
        $r = new Reader($bytes, 110);
        if ($r->u16() !== 1) throw new \InvalidArgumentException('Unknown assignment version');
        $value = new self($r->take(32), $r->take(32), $r->take(32), $r->u32(), $r->u64());
        $r->end();
        return $value;
    }

    public static function nextGeneration(int $previous): int
    {
        if ($previous < 0 || $previous >= 0xffffffff) throw new \InvalidArgumentException('Assignment generation exhausted');
        return $previous + 1;
    }
}
