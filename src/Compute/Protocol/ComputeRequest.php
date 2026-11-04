<?php
declare(strict_types=1);
namespace Greenwood\Compute\Protocol;

use Greenwood\Codec\Canonical as C;
use Greenwood\Crypto\ProtocolHash;

/**
 * Canonical GREENWOOD_COMPUTE_V1 compute request.
 *
 * The request body is unsigned. When submitted to the chain it is carried
 * as the payload of the existing signed transaction envelope, which
 * authenticates the requester — no duplicate signature machinery exists
 * here (Pass 4B section 9).
 *
 * Canonical field order (frozen):
 *   u16 protocol_version = 1
 *   requester[32]              account address (H(GREENWOOD_ACCOUNT_V1, pubkey))
 *   u32 request_nonce
 *   workload_digest[32]        authoritative workload identity
 *   bytes(workload_ref)        retrieval metadata, may be empty, <= 256 bytes
 *   input_digest[32]           authoritative input identity
 *   bytes(input_ref)           retrieval metadata, may be empty, <= 256 bytes
 *   u8  trust_class            0=OPEN 1=TRUSTED 2=SOVEREIGN
 *   u32 cpu_limit_millis
 *   u64 memory_limit_bytes
 *   u32 pid_limit
 *   u64 execution_timeout_ms
 *   u32 output_retention_bytes
 *   u64 max_payment            native-chain base units
 *   u64 deadline_height        chain height after which the request lapses
 *
 * ComputeRequestId = H("GREENWOOD_COMPUTE_REQUEST_V1", canonical_bytes).
 */
final class ComputeRequest
{
    public const PROTOCOL_VERSION = 1;
    public const DOMAIN = 'GREENWOOD_COMPUTE_REQUEST_V1';
    public const MAX_REF_BYTES = 256;

    public function __construct(
        private readonly string $requester,        // 32-byte account address
        private readonly int $requestNonce,
        private readonly string $workloadDigest,   // 32 bytes
        private readonly string $workloadRef,      // may be ''
        private readonly string $inputDigest,      // 32 bytes
        private readonly string $inputRef,         // may be ''
        private readonly int $trustClass,
        private readonly int $cpuLimitMillis,
        private readonly int $memoryLimitBytes,
        private readonly int $pidLimit,
        private readonly int $executionTimeoutMs,
        private readonly int $outputRetentionBytes,
        private readonly int $maxPayment,
        private readonly int $deadlineHeight,
    ) {
        if (strlen($this->requester) !== 32) {
            throw new \InvalidArgumentException('Requester must be a 32-byte account address');
        }
        if (strlen($this->workloadDigest) !== 32) {
            throw new \InvalidArgumentException('Workload digest must be 32 bytes');
        }
        if (strlen($this->inputDigest) !== 32) {
            throw new \InvalidArgumentException('Input digest must be 32 bytes');
        }
        foreach (['workload_ref' => $this->workloadRef, 'input_ref' => $this->inputRef] as $name => $ref) {
            if (strlen($ref) > self::MAX_REF_BYTES) {
                throw new \InvalidArgumentException("$name exceeds ".self::MAX_REF_BYTES.' bytes');
            }
        }
        TrustClass::check($this->trustClass);
        foreach (['request_nonce' => $this->requestNonce, 'cpu_limit_millis' => $this->cpuLimitMillis,
                  'pid_limit' => $this->pidLimit, 'output_retention_bytes' => $this->outputRetentionBytes] as $name => $v) {
            if ($v < 0 || $v > 0xFFFFFFFF) {
                throw new \InvalidArgumentException("$name out of u32 range");
            }
        }
        foreach (['memory_limit_bytes' => $this->memoryLimitBytes, 'execution_timeout_ms' => $this->executionTimeoutMs,
                  'max_payment' => $this->maxPayment, 'deadline_height' => $this->deadlineHeight] as $name => $v) {
            if ($v < 0) {
                throw new \InvalidArgumentException("$name must be non-negative");
            }
        }
    }

    /** Canonical unsigned request bytes, frozen field order. */
    public function toBytes(): string
    {
        return C::encodeU16BE(self::PROTOCOL_VERSION)
            . $this->requester
            . C::encodeU32BE($this->requestNonce)
            . $this->workloadDigest
            . C::encodeBytes($this->workloadRef)
            . $this->inputDigest
            . C::encodeBytes($this->inputRef)
            . \chr($this->trustClass)
            . C::encodeU32BE($this->cpuLimitMillis)
            . C::encodeU64BE($this->memoryLimitBytes)
            . C::encodeU32BE($this->pidLimit)
            . C::encodeU64BE($this->executionTimeoutMs)
            . C::encodeU32BE($this->outputRetentionBytes)
            . C::encodeU64BE($this->maxPayment)
            . C::encodeU64BE($this->deadlineHeight);
    }

    /** ComputeRequestId = H("GREENWOOD_COMPUTE_REQUEST_V1", canonical_bytes). */
    public function getId(): string
    {
        return ProtocolHash::hash(self::DOMAIN, $this->toBytes());
    }

    public function getRequester(): string { return $this->requester; }
    public function getRequestNonce(): int { return $this->requestNonce; }
    public function getWorkloadDigest(): string { return $this->workloadDigest; }
    public function getWorkloadRef(): string { return $this->workloadRef; }
    public function getInputDigest(): string { return $this->inputDigest; }
    public function getInputRef(): string { return $this->inputRef; }
    public function getTrustClass(): int { return $this->trustClass; }
    public function getCpuLimitMillis(): int { return $this->cpuLimitMillis; }
    public function getMemoryLimitBytes(): int { return $this->memoryLimitBytes; }
    public function getPidLimit(): int { return $this->pidLimit; }
    public function getExecutionTimeoutMs(): int { return $this->executionTimeoutMs; }
    public function getOutputRetentionBytes(): int { return $this->outputRetentionBytes; }
    public function getMaxPayment(): int { return $this->maxPayment; }
    public function getDeadlineHeight(): int { return $this->deadlineHeight; }
}
