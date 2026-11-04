<?php
declare(strict_types=1);
namespace Greenwood\Compute\Runtime;

/**
 * Immutable execution specification.
 * 
 * Defines all parameters for a single container execution.
 */
final readonly class ExecutionSpec
{
    public const NETWORK_POLICY_NONE = 'NONE';
    public const NETWORK_POLICY_HOST = 'HOST';
    public const NETWORK_POLICY_CUSTOM = 'CUSTOM';

    private const DEFAULT_TIMEOUT_SECONDS = 60;
    private const DEFAULT_MEMORY_LIMIT_BYTES = 256 * 1024 * 1024; // 256MB
    private const DEFAULT_CPU_LIMIT = 1.0;

    /**
     * @param array<string, string> $environment
     * @param array<string, string> $arguments
     * @param array<Mount> $mounts
     * @throws \InvalidArgumentException if validation fails
     */
    public function __construct(
        private string $executionId,
        private string $imageReference,
        private array $command,
        private array $environment = [],
        private ?string $workingDirectory = null,
        private ?float $cpuLimit = null,
        private ?int $memoryLimitBytes = null,
        private ?int $timeoutSeconds = null,
        private bool $readOnlyRootFilesystem = true,
        private string $networkPolicy = self::NETWORK_POLICY_NONE,
        private array $mounts = []
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        // Validate execution ID
        if ($this->executionId === '') {
            throw new \InvalidArgumentException('Execution ID cannot be empty');
        }
        
        // Validate image reference
        if ($this->imageReference === '') {
            throw new \InvalidArgumentException('Image reference cannot be empty');
        }
        
        // Validate command
        if (empty($this->command)) {
            throw new \InvalidArgumentException('Command cannot be empty');
        }
        
        // Validate timeout
        $timeout = $this->timeoutSeconds ?? self::DEFAULT_TIMEOUT_SECONDS;
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('Timeout must be positive');
        }
        if ($timeout > 86400) { // Max 24 hours
            throw new \InvalidArgumentException('Timeout exceeds maximum of 24 hours');
        }
        
        // Validate memory limit
        $memory = $this->memoryLimitBytes ?? self::DEFAULT_MEMORY_LIMIT_BYTES;
        if ($memory <= 0) {
            throw new \InvalidArgumentException('Memory limit must be positive');
        }
        if ($memory < 1024 * 1024) { // Min 1MB
            throw new \InvalidArgumentException('Memory limit must be at least 1MB');
        }
        if ($memory > 16 * 1024 * 1024 * 1024) { // Max 16GB
            throw new \InvalidArgumentException('Memory limit exceeds maximum of 16GB');
        }
        
        // Validate CPU limit
        $cpu = $this->cpuLimit ?? self::DEFAULT_CPU_LIMIT;
        if ($cpu <= 0) {
            throw new \InvalidArgumentException('CPU limit must be positive');
        }
        if ($cpu > 64) { // Max 64 cores
            throw new \InvalidArgumentException('CPU limit exceeds maximum of 64 cores');
        }
        
        // Validate network policy
        if (!\in_array($this->networkPolicy, [
            self::NETWORK_POLICY_NONE,
            self::NETWORK_POLICY_HOST,
            self::NETWORK_POLICY_CUSTOM
        ], true)) {
            throw new \InvalidArgumentException(
                "Invalid network policy: {$this->networkPolicy}"
            );
        }
        
        // Validate environment keys
        foreach ($this->environment as $key => $value) {
            if ($key === '' || \ctype_space($key)) {
                throw new \InvalidArgumentException('Environment key cannot be empty or whitespace only');
            }
            // Note: We allow empty values for environment variables (some containers expect this)
        }
        
        // Validate command arguments
        foreach ($this->command as $arg) {
            if (!\is_string($arg)) {
                throw new \InvalidArgumentException('All command arguments must be strings');
            }
        }
    }

    public function getExecutionId(): string
    {
        return $this->executionId;
    }

    public function getImageReference(): string
    {
        return $this->imageReference;
    }

    /**
     * @return array<int, string>
     */
    public function getCommand(): array
    {
        return $this->command;
    }

    /**
     * @return array<string, string>
     */
    public function getEnvironment(): array
    {
        return $this->environment;
    }

    public function getWorkingDirectory(): ?string
    {
        return $this->workingDirectory;
    }

    public function getCpuLimit(): ?float
    {
        return $this->cpuLimit;
    }

    public function getMemoryLimitBytes(): ?int
    {
        return $this->memoryLimitBytes;
    }

    public function getTimeoutSeconds(): ?int
    {
        return $this->timeoutSeconds;
    }

    public function isReadOnlyRootFilesystem(): bool
    {
        return $this->readOnlyRootFilesystem;
    }

    public function getNetworkPolicy(): string
    {
        return $this->networkPolicy;
    }

    /**
     * @return array<Mount>
     */
    public function getMounts(): array
    {
        return $this->mounts;
    }

    /**
     * Create a safe default spec with minimal required parameters.
     */
    public static function createDefault(string $executionId, string $image, string $command): self
    {
        return new self(
            executionId: $executionId,
            imageReference: $image,
            command: [$command],
            timeoutSeconds: self::DEFAULT_TIMEOUT_SECONDS,
            memoryLimitBytes: self::DEFAULT_MEMORY_LIMIT_BYTES,
            cpuLimit: self::DEFAULT_CPU_LIMIT,
            readOnlyRootFilesystem: true,
            networkPolicy: self::NETWORK_POLICY_NONE
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'executionId' => $this->executionId,
            'imageReference' => $this->imageReference,
            'command' => $this->command,
            'environment' => $this->environment,
            'workingDirectory' => $this->workingDirectory,
            'cpuLimit' => $this->cpuLimit,
            'memoryLimitBytes' => $this->memoryLimitBytes,
            'timeoutSeconds' => $this->timeoutSeconds,
            'readOnlyRootFilesystem' => $this->readOnlyRootFilesystem,
            'networkPolicy' => $this->networkPolicy,
            'mounts' => \array_map(fn($m) => $m->toArray(), $this->mounts),
        ];
    }
}
