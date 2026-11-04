<?php
declare(strict_types=1);
namespace Greenwood\Compute\Runtime;

/**
 * Immutable mount specification for container execution.
 * 
 * Defines a bind mount or volume mount into the container.
 */
final readonly class Mount
{
    public const TYPE_BIND = 'bind';
    public const TYPE_VOLUME = 'volume';
    public const TYPE_TMPFS = 'tmpfs';

    /**
     * @throws \InvalidArgumentException if validation fails
     */
    public function __construct(
        private string $type,
        private string $source,
        private string $destination,
        private bool $readOnly = false,
        private array $options = []
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if (!\in_array($this->type, [self::TYPE_BIND, self::TYPE_VOLUME, self::TYPE_TMPFS], true)) {
            throw new \InvalidArgumentException("Invalid mount type: {$this->type}");
        }
        
        if ($this->destination === '') {
            throw new \InvalidArgumentException('Mount destination cannot be empty');
        }
        
        // Validate destination is an absolute path
        if (!\str_starts_with($this->destination, '/')) {
            throw new \InvalidArgumentException("Mount destination must be an absolute path: {$this->destination}");
        }
        
        // For tmpfs, source is ignored (can be empty)
        if ($this->type !== self::TYPE_TMPFS && $this->source === '') {
            throw new \InvalidArgumentException('Mount source cannot be empty');
        }
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getDestination(): string
    {
        return $this->destination;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Create a read-write bind mount.
     */
    public static function bind(string $source, string $destination, array $options = []): self
    {
        return new self(self::TYPE_BIND, $source, $destination, false, $options);
    }

    /**
     * Create a read-only bind mount.
     */
    public static function bindReadOnly(string $source, string $destination, array $options = []): self
    {
        return new self(self::TYPE_BIND, $source, $destination, true, $options);
    }

    /**
     * Create a tmpfs mount.
     */
    public static function tmpfs(string $destination, array $options = []): self
    {
        return new self(self::TYPE_TMPFS, '', $destination, false, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'source' => $this->source,
            'destination' => $this->destination,
            'readOnly' => $this->readOnly,
            'options' => $this->options,
        ];
    }
}
