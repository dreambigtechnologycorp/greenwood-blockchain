<?php
declare(strict_types=1);
namespace Greenwood\Compute\Protocol;

use Greenwood\Codec\Canonical as C;
use Greenwood\Crypto\Ed25519KeyPair;
use Greenwood\Crypto\ProtocolHash;

/**
 * Provider-issued execution receipt for GREENWOOD_COMPUTE_V1.
 *
 * Canonical UNSIGNED receipt fields (frozen order):
 *   u16 protocol_version = 1
 *   request_id[32] / provider_id[32] / execution_id[32]
 *   workload_digest[32] / input_digest[32] / output_digest[32]
 *   bytes(output_ref)        may be empty, <= 256 bytes
 *   u8 status                frozen ComputeStatus encoding
 *   u8 exit_code_present     0 or 1 (null means absent only)
 *   u32 exit_code            only when present
 *   u64 cpu_time_ms / u64 memory_peak_bytes / u64 runtime_duration_ms
 *   u8 fuel_used_present     0 or 1 (contract workloads only)
 *   u64 fuel_used            only when present
 *   u64 output_bytes_observed
 *   u8 stdout_truncated / u8 stderr_truncated (0 or 1)
 *
 * Signed receipt = unsigned bytes | u16(64) | signature[64], mirroring the
 * transaction envelope convention. Provider signature is Ed25519 over
 * H("GREENWOOD_RECEIPT_SIGN_V1", unsigned_bytes).
 *
 * ComputeReceiptId = H("GREENWOOD_COMPUTE_RECEIPT_V1", unsigned_bytes).
 */
final class ComputeReceipt
{
    public const PROTOCOL_VERSION = 1;
    public const ID_DOMAIN = 'GREENWOOD_COMPUTE_RECEIPT_V1';
    public const SIGN_DOMAIN = 'GREENWOOD_RECEIPT_SIGN_V1';
    public const MAX_REF_BYTES = 256;

    private string $signature = '';

    public function __construct(
        private readonly string $requestId,
        private readonly string $providerId,
        private readonly string $executionId,
        private readonly string $workloadDigest,
        private readonly string $inputDigest,
        private readonly string $outputDigest,
        private readonly string $outputRef,
        private readonly int $status,
        private readonly ?int $exitCode,
        private readonly int $cpuTimeMs,
        private readonly int $memoryPeakBytes,
        private readonly int $runtimeDurationMs,
        private readonly ?int $fuelUsed,
        private readonly int $outputBytesObserved,
        private readonly bool $stdoutTruncated,
        private readonly bool $stderrTruncated,
    ) {
        foreach (['request_id' => $this->requestId, 'provider_id' => $this->providerId,
                  'execution_id' => $this->executionId, 'workload_digest' => $this->workloadDigest,
                  'input_digest' => $this->inputDigest, 'output_digest' => $this->outputDigest] as $name => $v) {
            if (strlen($v) !== 32) {
                throw new \InvalidArgumentException("$name must be 32 bytes");
            }
        }
        if (strlen($this->outputRef) > self::MAX_REF_BYTES) {
            throw new \InvalidArgumentException('output_ref exceeds '.self::MAX_REF_BYTES.' bytes');
        }
        ComputeStatus::check($this->status);
        if (ComputeStatus::requiresExitCode($this->status) && $this->exitCode === null) {
            throw new \InvalidArgumentException('Status requires an exit code');
        }
        if (ComputeStatus::forbidsExitCode($this->status) && $this->exitCode !== null) {
            throw new \InvalidArgumentException('RUNTIME_ERROR must not carry an exit code');
        }
        if ($this->exitCode !== null && ($this->exitCode < 0 || $this->exitCode > 0xFFFFFFFF)) {
            throw new \InvalidArgumentException('exit_code out of u32 range');
        }
        if ($this->fuelUsed !== null && $this->fuelUsed < 0) {
            throw new \InvalidArgumentException('fuel_used must be non-negative');
        }
        foreach (['cpu_time_ms' => $this->cpuTimeMs, 'memory_peak_bytes' => $this->memoryPeakBytes,
                  'runtime_duration_ms' => $this->runtimeDurationMs,
                  'output_bytes_observed' => $this->outputBytesObserved] as $name => $v) {
            if ($v < 0) {
                throw new \InvalidArgumentException("$name must be non-negative");
            }
        }
    }

    /** Canonical unsigned receipt bytes. Never JSON, never display text. */
    public function toUnsignedBytes(): string
    {
        $bytes = C::encodeU16BE(self::PROTOCOL_VERSION)
            . $this->requestId
            . $this->providerId
            . $this->executionId
            . $this->workloadDigest
            . $this->inputDigest
            . $this->outputDigest
            . C::encodeBytes($this->outputRef)
            . \chr($this->status)
            . \chr($this->exitCode === null ? 0 : 1);
        if ($this->exitCode !== null) {
            $bytes .= C::encodeU32BE($this->exitCode);
        }
        $bytes .= C::encodeU64BE($this->cpuTimeMs)
            . C::encodeU64BE($this->memoryPeakBytes)
            . C::encodeU64BE($this->runtimeDurationMs)
            . \chr($this->fuelUsed === null ? 0 : 1);
        if ($this->fuelUsed !== null) {
            $bytes .= C::encodeU64BE($this->fuelUsed);
        }
        $bytes .= C::encodeU64BE($this->outputBytesObserved)
            . C::encodeBool($this->stdoutTruncated)
            . C::encodeBool($this->stderrTruncated);
        return $bytes;
    }

    /** ComputeReceiptId = H("GREENWOOD_COMPUTE_RECEIPT_V1", unsigned_bytes). */
    public function getId(): string
    {
        return ProtocolHash::hash(self::ID_DOMAIN, $this->toUnsignedBytes());
    }

    /** Signing digest, mirroring the GREENWOOD_TX_SIGN_V1 convention. */
    public function getSigningDigest(): string
    {
        return ProtocolHash::hash(self::SIGN_DOMAIN, $this->toUnsignedBytes());
    }

    public function sign(Ed25519KeyPair $providerKey): self
    {
        $this->signature = $providerKey->sign($this->getSigningDigest());
        return $this;
    }

    public function setSignature(string $signature): self
    {
        if (strlen($signature) !== 64) {
            throw new \InvalidArgumentException('Invalid signature length');
        }
        $this->signature = $signature;
        return $this;
    }

    /** Signed canonical bytes: unsigned | u16(64) | signature[64]. */
    public function toSignedBytes(): string
    {
        if (strlen($this->signature) !== 64) {
            throw new \InvalidArgumentException('Unsigned receipt');
        }
        return $this->toUnsignedBytes() . C::encodeU16BE(64) . $this->signature;
    }

    /**
     * Cryptographic verification only. Confirms the provider public key is
     * behind provider_id and the Ed25519 signature covers the canonical
     * unsigned bytes. Request matching and lifecycle rules belong to the
     * ComputeReceiptVerifier.
     */
    public function verifySignature(string $providerPublicKey): bool
    {
        if (strlen($this->signature) !== 64 || strlen($providerPublicKey) !== 32) {
            return false;
        }
        if (!hash_equals($this->providerId, ProtocolHash::hash(ProviderId::DOMAIN, $providerPublicKey))) {
            return false;
        }
        return Ed25519KeyPair::verify($this->getSigningDigest(), $this->signature, $providerPublicKey);
    }

    public function getSignature(): string { return $this->signature; }
    public function getRequestId(): string { return $this->requestId; }
    public function getProviderId(): string { return $this->providerId; }
    public function getExecutionId(): string { return $this->executionId; }
    public function getWorkloadDigest(): string { return $this->workloadDigest; }
    public function getInputDigest(): string { return $this->inputDigest; }
    public function getOutputDigest(): string { return $this->outputDigest; }
    public function getOutputRef(): string { return $this->outputRef; }
    public function getStatus(): int { return $this->status; }
    public function getExitCode(): ?int { return $this->exitCode; }
    public function getCpuTimeMs(): int { return $this->cpuTimeMs; }
    public function getMemoryPeakBytes(): int { return $this->memoryPeakBytes; }
    public function getRuntimeDurationMs(): int { return $this->runtimeDurationMs; }
    public function getFuelUsed(): ?int { return $this->fuelUsed; }
    public function getOutputBytesObserved(): int { return $this->outputBytesObserved; }
    public function isStdoutTruncated(): bool { return $this->stdoutTruncated; }
    public function isStderrTruncated(): bool { return $this->stderrTruncated; }
}