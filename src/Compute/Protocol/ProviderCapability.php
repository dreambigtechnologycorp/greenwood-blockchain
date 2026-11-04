<?php
declare(strict_types=1);
namespace Greenwood\Compute\Protocol;

use Greenwood\Codec\{Canonical as C, Reader};
use Greenwood\Crypto\ProtocolHash;

/**
 * Canonical provider schedulable-capability record (Pass 4D).
 *
 * Trust classes are deliberately absent: they come from finalized
 * ProviderEligibility only, so a capability update can never promote a
 * provider (OPEN→TRUSTED/SOVEREIGN) or alter sovereign membership.
 *
 * Canonical field order (frozen):
 *   u16 protocol_version = 1
 *   provider_id[32]               H(GREENWOOD_PROVIDER_V1, pubkey)
 *   provider_public_key[32]       Ed25519 key, binds the provider_id
 *   u8  status                    0=ACTIVE 1=DRAINING 2=SUSPENDED
 *   u32 capability_nonce          initial=1, every update=previous+1
 *   u16 architecture_count
 *   bytes(architecture)*          OCI platforms, e.g. linux/arm64/v8
 *   u32 cpu_capacity_millis
 *   u64 memory_capacity_bytes
 *   u32 pid_capacity
 *   u32 max_concurrent_jobs
 */
final class ProviderCapability
{
    public const PROTOCOL_VERSION = 1;
    public const DOMAIN = 'GREENWOOD_PROVIDER_CAPABILITY_V1';

    public const STATUS_ACTIVE = 0;
    public const STATUS_DRAINING = 1;
    public const STATUS_SUSPENDED = 2;

    /** Bounded canonical architecture vocabulary for launch. */
    public const ARCHITECTURES = ['linux/arm64/v8', 'linux/amd64'];

    public function __construct(
        public readonly string $providerId,
        public readonly string $providerPublicKey,
        public readonly int $status,
        public readonly int $nonce,
        public readonly array $architectures,
        public readonly int $cpuCapacityMillis,
        public readonly int $memoryCapacityBytes,
        public readonly int $pidCapacity,
        public readonly int $maxConcurrentJobs,
    ) {
        if (strlen($this->providerId) !== 32 || strlen($this->providerPublicKey) !== 32
            || ProviderId::fromPublicKey($this->providerPublicKey)->toBytes() !== $this->providerId) {
            throw new \InvalidArgumentException('Invalid provider binding');
        }
        if ($this->status < 0 || $this->status > 2) {
            throw new \InvalidArgumentException('Invalid provider status');
        }
        if ($this->nonce < 1 || $this->nonce > 0xFFFFFFFF) {
            throw new \InvalidArgumentException('Invalid capability nonce');
        }
        if ($this->architectures === [] || count($this->architectures) > 0xFFFF) {
            throw new \InvalidArgumentException('Invalid architecture list');
        }
        foreach ($this->architectures as $arch) {
            if (!\in_array($arch, self::ARCHITECTURES, true)) {
                throw new \InvalidArgumentException('Unknown architecture: '.$arch);
            }
        }
        if (count($this->architectures) !== count(array_unique($this->architectures))) {
            throw new \InvalidArgumentException('Duplicate architectures are not canonical');
        }
        foreach (['cpu_capacity_millis' => $this->cpuCapacityMillis, 'pid_capacity' => $this->pidCapacity,
                  'max_concurrent_jobs' => $this->maxConcurrentJobs] as $name => $v) {
            if ($v < 1 || $v > 0xFFFFFFFF) throw new \InvalidArgumentException("$name out of u32 range");
        }
        if ($this->memoryCapacityBytes < 1) throw new \InvalidArgumentException('memory_capacity_bytes out of u64 range');
    }

    public function toBytes(): string
    {
        $out = C::encodeU16BE(self::PROTOCOL_VERSION) . $this->providerId . $this->providerPublicKey
            . \chr($this->status) . C::encodeU32BE($this->nonce) . C::encodeU16BE(count($this->architectures));
        foreach ($this->architectures as $arch) $out .= C::encodeBytes($arch);
        return $out . C::encodeU32BE($this->cpuCapacityMillis) . C::encodeU64BE($this->memoryCapacityBytes)
            . C::encodeU32BE($this->pidCapacity) . C::encodeU32BE($this->maxConcurrentJobs);
    }

    public static function decode(string $bytes): self
    {
        $r = new Reader($bytes, 4096);
        if ($r->u16() !== self::PROTOCOL_VERSION) throw new \InvalidArgumentException('Unknown capability version');
        $id = $r->take(32);
        $key = $r->take(32);
        $status = $r->u8();
        $nonce = $r->u32();
        $count = $r->u16();
        if ($count === 0) throw new \InvalidArgumentException('Architecture list required');
        $arch = [];
        for ($i = 0; $i < $count; $i++) $arch[] = $r->bytes(32);
        $value = new self($id, $key, $status, $nonce, $arch, $r->u32(), $r->u64(), $r->u32(), $r->u32());
        $r->end();
        return $value;
    }

    public function getId(): string
    {
        return ProtocolHash::hash(self::DOMAIN, $this->toBytes());
    }

    /** Next capability version; mirrors ComputeAssign::nextGeneration semantics. */
    public static function nextNonce(int $previous): int
    {
        if ($previous < 0 || $previous >= 0xFFFFFFFF) throw new \InvalidArgumentException('Capability nonce exhausted');
        return $previous + 1;
    }
}
