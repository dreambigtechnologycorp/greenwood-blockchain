<?php
declare(strict_types=1);
namespace Greenwood\Compute\Runtime;

/**
 * Immutable execution result.
 * 
 * Contains all information about a completed execution.
 */
class ExecutionResult
{
    public const DEFAULT_STDOUT_HASH_ALGO = 'sha256';
    public const DEFAULT_STDERR_HASH_ALGO = 'sha256';

    private string $executionId;
    private string $resolvedImageDigest;
    private ExecutionStatus $status;
    private int $exitCode;
    private string $stdout;
    private string $stderr;
    private string $stdoutHash;
    private string $stderrHash;
    private \DateTimeImmutable $startedAt;
    private \DateTimeImmutable $finishedAt;
    private float $duration;
    private ?ResourceUsage $resourceUsage = null;
    private ?string $errorMessage = null;

    /**
     * @throws \InvalidArgumentException if validation fails
     */
    public function __construct(
        string $executionId,
        string $resolvedImageDigest,
        ExecutionStatus $status,
        int $exitCode,
        string $stdout,
        string $stderr,
        ?string $stdoutHash = null,
        ?string $stderrHash = null,
        ?\DateTimeImmutable $startedAt = null,
        ?\DateTimeImmutable $finishedAt = null,
        float $duration = 0.0,
        ?ResourceUsage $resourceUsage = null,
        ?string $errorMessage = null,
        private bool $stdoutTruncated = false,
        private bool $stderrTruncated = false,
        private ?int $stdoutBytesObserved = null,
        private ?int $stderrBytesObserved = null,
        private ?string $fullStdoutHash = null,
        private ?string $fullStderrHash = null,
        private ?int $durationMs = null
    ) {
        if ($durationMs !== null && $durationMs < 0) throw new \InvalidArgumentException('Negative duration milliseconds');
        if ($executionId === '') {
            throw new \InvalidArgumentException('Execution ID cannot be empty');
        }
        
        if ($resolvedImageDigest === '') {
            throw new \InvalidArgumentException('Resolved image digest cannot be empty');
        }
        
        if ($duration < 0) {
            throw new \InvalidArgumentException('Duration cannot be negative');
        }
        if (($fullStdoutHash === null) !== ($fullStderrHash === null)) throw new \InvalidArgumentException('Both full-stream hashes required');
        if ($fullStdoutHash !== null) {
            if ($stdoutBytesObserved === null || $stderrBytesObserved === null) throw new \InvalidArgumentException('Full-stream counters required');
            OutputCommitment::digest($stdoutBytesObserved, $fullStdoutHash, $stderrBytesObserved, $fullStderrHash);
        }
        
        // Set base properties
        $this->executionId = $executionId;
        $this->resolvedImageDigest = $resolvedImageDigest;
        $this->status = $status;
        $this->exitCode = $exitCode;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->startedAt = $startedAt ?? new \DateTimeImmutable();
        $this->finishedAt = $finishedAt ?? new \DateTimeImmutable();
        $this->duration = $duration;
        $this->resourceUsage = $resourceUsage;
        $this->errorMessage = $errorMessage;
        
        // Hash generation if not provided
        $this->stdoutHash = $stdoutHash !== null ? $stdoutHash : hash(self::DEFAULT_STDOUT_HASH_ALGO, $stdout);
        $this->stderrHash = $stderrHash !== null ? $stderrHash : hash(self::DEFAULT_STDERR_HASH_ALGO, $stderr);
        
        // Validate hash format (should be hex string)
        if (!\ctype_xdigit($this->stdoutHash)) {
            throw new \InvalidArgumentException('stdoutHash must be a hex string');
        }
        if (!\ctype_xdigit($this->stderrHash)) {
            throw new \InvalidArgumentException('stderrHash must be a hex string');
        }
    }

    public function getExecutionId(): string
    {
        return $this->executionId;
    }

    public function getResolvedImageDigest(): string
    {
        return $this->resolvedImageDigest;
    }

    public function getStatus(): ExecutionStatus
    {
        return $this->status;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getStdout(): string
    {
        return $this->stdout;
    }

    public function getStderr(): string
    {
        return $this->stderr;
    }

    public function getStdoutHash(): string
    {
        return $this->stdoutHash;
    }

    public function getStderrHash(): string
    {
        return $this->stderrHash;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): \DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }
    public function getDurationMs(): ?int { return $this->durationMs; }

    public function getResourceUsage(): ?ResourceUsage
    {
        return $this->resourceUsage;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /** True when retained stdout hit the configured limit and bytes were discarded. */
    public function isStdoutTruncated(): bool
    {
        return $this->stdoutTruncated;
    }

    /** True when retained stderr hit the configured limit and bytes were discarded. */
    public function isStderrTruncated(): bool
    {
        return $this->stderrTruncated;
    }

    /** Total stdout bytes observed, including discarded overflow (null if untracked). */
    public function getStdoutBytesObserved(): ?int
    {
        return $this->stdoutBytesObserved;
    }

    /** Total stderr bytes observed, including discarded overflow (null if untracked). */
    public function getStderrBytesObserved(): ?int
    {
        return $this->stderrBytesObserved;
    }

    public function getFullStdoutHash(): ?string { return $this->fullStdoutHash; }
    public function getFullStderrHash(): ?string { return $this->fullStderrHash; }
    public function getOutputDigest(): ?string
    {
        if ($this->fullStdoutHash === null) return null; // Never substitute retained-prefix hashes.
        return OutputCommitment::digest($this->stdoutBytesObserved, $this->fullStdoutHash, $this->stderrBytesObserved, $this->fullStderrHash);
    }

    public function isSuccess(): bool
    {
        return $this->status->isCompleted();
    }

    public function isFailed(): bool
    {
        return $this->status->isFailed() || $this->status->isError();
    }

    public function isTimedOut(): bool
    {
        return $this->status->isTimedOut();
    }

    public function isTerminated(): bool
    {
        return $this->status->isTerminated();
    }

    public function isError(): bool
    {
        return $this->status->isError();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'executionId' => $this->executionId,
            'resolvedImageDigest' => $this->resolvedImageDigest,
            'status' => $this->status->getValue(),
            'exitCode' => $this->exitCode,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'stdoutHash' => $this->stdoutHash,
            'stderrHash' => $this->stderrHash,
            'startedAt' => $this->startedAt->format(\DateTimeImmutable::RFC3339),
            'finishedAt' => $this->finishedAt->format(\DateTimeImmutable::RFC3339),
            'duration' => $this->duration,
            'resourceUsage' => $this->resourceUsage?->toArray(),
            'errorMessage' => $this->errorMessage,
        ];
    }

    /**
     * Create a successful execution result.
     */
    public static function success(
        string $executionId,
        string $resolvedImageDigest,
        int $exitCode,
        string $stdout,
        string $stderr,
        ?\DateTimeImmutable $startedAt = null,
        ?\DateTimeImmutable $finishedAt = null,
        float $duration = 0.0,
        ?ResourceUsage $resourceUsage = null,
        ?string $stdoutHash = null,
        ?string $stderrHash = null
    ): self {
        return new self(
            executionId: $executionId,
            resolvedImageDigest: $resolvedImageDigest,
            status: ExecutionStatus::completed(),
            exitCode: $exitCode,
            stdout: $stdout,
            stderr: $stderr,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            duration: $duration,
            resourceUsage: $resourceUsage,
            stdoutHash: $stdoutHash,
            stderrHash: $stderrHash
        );
    }

    /**
     * Create a failed execution result.
     */
    public static function failed(
        string $executionId,
        string $resolvedImageDigest,
        int $exitCode,
        string $stdout,
        string $stderr,
        ?\DateTimeImmutable $startedAt = null,
        ?\DateTimeImmutable $finishedAt = null,
        float $duration = 0.0,
        ?string $errorMessage = null,
        ?string $stdoutHash = null,
        ?string $stderrHash = null
    ): self {
        return new self(
            executionId: $executionId,
            resolvedImageDigest: $resolvedImageDigest,
            status: ExecutionStatus::failed(),
            exitCode: $exitCode,
            stdout: $stdout,
            stderr: $stderr,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            duration: $duration,
            errorMessage: $errorMessage,
            stdoutHash: $stdoutHash,
            stderrHash: $stderrHash
        );
    }

    /**
     * Create a timeout execution result.
     */
    public static function timedOut(
        string $executionId,
        string $resolvedImageDigest,
        string $stdout,
        string $stderr,
        ?\DateTimeImmutable $startedAt = null,
        ?\DateTimeImmutable $finishedAt = null,
        float $duration = 0.0,
        ?string $errorMessage = null,
        ?string $stdoutHash = null,
        ?string $stderrHash = null
    ): self {
        return new self(
            executionId: $executionId,
            resolvedImageDigest: $resolvedImageDigest,
            status: ExecutionStatus::timedOut(),
            exitCode: 137, // 128 + 9 (SIGKILL)
            stdout: $stdout,
            stderr: $stderr,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            duration: $duration,
            errorMessage: $errorMessage ?? 'Execution timed out',
            stdoutHash: $stdoutHash,
            stderrHash: $stderrHash
        );
    }

    /**
     * Create a terminated execution result.
     */
    public static function terminated(
        string $executionId,
        string $resolvedImageDigest,
        string $stdout,
        string $stderr,
        ?\DateTimeImmutable $startedAt = null,
        ?\DateTimeImmutable $finishedAt = null,
        float $duration = 0.0,
        ?string $errorMessage = null,
        ?string $stdoutHash = null,
        ?string $stderrHash = null
    ): self {
        return new self(
            executionId: $executionId,
            resolvedImageDigest: $resolvedImageDigest,
            status: ExecutionStatus::terminated(),
            exitCode: 137, // 128 + 9 (SIGKILL)
            stdout: $stdout,
            stderr: $stderr,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            duration: $duration,
            errorMessage: $errorMessage ?? 'Execution was terminated',
            stdoutHash: $stdoutHash,
            stderrHash: $stderrHash
        );
    }

    /**
     * Create a runtime error execution result.
     */
    public static function runtimeError(
        string $executionId,
        string $resolvedImageDigest,
        string $errorMessage,
        ?string $stdout = null,
        ?string $stderr = null,
        ?\DateTimeImmutable $startedAt = null,
        ?\DateTimeImmutable $finishedAt = null,
        ?float $duration = null,
        ?string $stdoutHash = null,
        ?string $stderrHash = null,
        ?string $fullStdoutHash = null,
        ?string $fullStderrHash = null,
        ?int $stdoutBytesObserved = null,
        ?int $stderrBytesObserved = null,
        ?ResourceUsage $resourceUsage = null,
        ?int $durationMs = null
    ): self {
        return new self(
            executionId: $executionId,
            resolvedImageDigest: $resolvedImageDigest,
            status: ExecutionStatus::runtimeError(),
            exitCode: 255,
            stdout: $stdout ?? '',
            stderr: $stderr ?? '',
            startedAt: $startedAt ?? new \DateTimeImmutable(),
            finishedAt: $finishedAt ?? new \DateTimeImmutable(),
            duration: $duration ?? 0.0,
            errorMessage: $errorMessage,
            stdoutHash: $stdoutHash,
            stderrHash: $stderrHash,
            stdoutBytesObserved: $stdoutBytesObserved,
            stderrBytesObserved: $stderrBytesObserved,
            fullStdoutHash: $fullStdoutHash,
            fullStderrHash: $fullStderrHash,
            resourceUsage: $resourceUsage,
            durationMs: $durationMs
        );
    }
}
