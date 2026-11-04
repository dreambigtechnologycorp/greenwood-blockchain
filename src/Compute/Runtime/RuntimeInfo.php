<?php
declare(strict_types=1);
namespace Greenwood\Compute\Runtime;

/**
 * Immutable runtime inspection result.
 * 
 * Contains information about the runtime environment and its capabilities.
 */
final readonly class RuntimeInfo
{
    /**
     * @param array<string, bool|int|string> $capabilities
     */
    public function __construct(
        private string $runtimeName,
        private string $runtimeVersion,
        private string $endpoint,
        private string $platform,
        private string $architecture,
        private bool $available,
        private array $capabilities = []
    ) {}

    public function getRuntimeName(): string
    {
        return $this->runtimeName;
    }

    public function getRuntimeVersion(): string
    {
        return $this->runtimeVersion;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function getArchitecture(): string
    {
        return $this->architecture;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * @return array<string, bool|int|string>
     */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    public function hasCapability(string $name): bool
    {
        return isset($this->capabilities[$name]) ? (bool) $this->capabilities[$name] : false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'runtimeName' => $this->runtimeName,
            'runtimeVersion' => $this->runtimeVersion,
            'endpoint' => $this->endpoint,
            'platform' => $this->platform,
            'architecture' => $this->architecture,
            'available' => $this->available,
            'capabilities' => $this->capabilities,
        ];
    }

    /**
     * Create a RuntimeInfo representing an unavailable runtime.
     */
    public static function unavailable(): self
    {
        return new self(
            runtimeName: 'unknown',
            runtimeVersion: 'unknown',
            endpoint: '',
            platform: PHP_OS_FAMILY,
            architecture: PHP_INT_SIZE === 8 ? 'x86_64' : 'unknown',
            available: false,
            capabilities: []
        );
    }

    /**
     * Create a RuntimeInfo for a Linux containerd environment.
     */
    public static function forContainerd(
        string $version,
        string $endpoint = '/run/containerd/containerd.sock',
        string $platform = 'linux',
        string $architecture = 'unknown',
        array $capabilities = []
    ): self {
        return new self(
            runtimeName: 'containerd',
            runtimeVersion: $version,
            endpoint: $endpoint,
            platform: $platform,
            architecture: $architecture,
            available: true,
            capabilities: $capabilities
        );
    }
}
