<?php

declare(strict_types=1);

namespace Greenwood\Compute\Containerd;

final readonly class SystemServiceDefinition
{
    private const DEFAULT_PATH =
        '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    public function __construct(
        public string $id,
        public string $imageRef,
        public array $command,
        public array $environment = [],
        public array $mounts = [],
        public string $user = '0:0',
        public bool $hostNetwork = true,
        public string $workingDirectory = '/'
    ) {
        if (!preg_match(
            '/^[a-z0-9][a-z0-9._-]{0,62}$/D',
            $this->id
        )) {
            throw new \InvalidArgumentException(
                'Invalid system service ID'
            );
        }

        if ($this->imageRef === '') {
            throw new \InvalidArgumentException(
                'System service image reference is required'
            );
        }

        if (
            $this->workingDirectory === ''
            || !str_starts_with(
                $this->workingDirectory,
                '/'
            )
            || str_contains(
                $this->workingDirectory,
                "\0"
            )
        ) {
            throw new \InvalidArgumentException(
                'System service working directory must be an absolute path'
            );
        }

        if (
            $this->command === []
            || !array_is_list($this->command)
        ) {
            throw new \InvalidArgumentException(
                'System service command must be a non-empty list'
            );
        }

        foreach ($this->command as $argument) {
            if (
                !is_string($argument)
                || $argument === ''
                || str_contains($argument, "\0")
            ) {
                throw new \InvalidArgumentException(
                    'System service command arguments must be non-empty strings without NUL'
                );
            }
        }

        if (!preg_match('/^[0-9]+:[0-9]+$/D', $this->user)) {
            throw new \InvalidArgumentException(
                'System service user must use uid:gid format'
            );
        }

        foreach ($this->environment as $name => $value) {
            if (
                !is_string($name)
                || !preg_match(
                    '/^[A-Za-z_][A-Za-z0-9_]*$/D',
                    $name
                )
                || !is_string($value)
            ) {
                throw new \InvalidArgumentException(
                    'Invalid system service environment'
                );
            }
        }

        foreach ($this->mounts as $mount) {
            if (!is_array($mount)) {
                throw new \InvalidArgumentException(
                    'System service mounts must be arrays'
                );
            }

            foreach (
                ['destination', 'type', 'source', 'options']
                as $required
            ) {
                if (!array_key_exists($required, $mount)) {
                    throw new \InvalidArgumentException(
                        'Incomplete system service mount'
                    );
                }
            }

            if (
                !is_string($mount['destination'])
                || !str_starts_with(
                    $mount['destination'],
                    '/'
                )
                || !is_string($mount['source'])
                || !str_starts_with(
                    $mount['source'],
                    '/'
                )
                || !is_string($mount['type'])
                || $mount['type'] === ''
                || !is_array($mount['options'])
            ) {
                throw new \InvalidArgumentException(
                    'Invalid system service mount'
                );
            }

            foreach ($mount['options'] as $option) {
                if (!is_string($option)) {
                    throw new \InvalidArgumentException(
                        'Mount options must be strings'
                    );
                }
            }
        }
    }

    public function containerId(): string
    {
        return $this->id;
    }

    public function viewKey(): string
    {
        return 'gw-system-view-' . $this->id;
    }

    public function leaseId(): string
    {
        return 'gw-system-lease-' . $this->id;
    }

    public function environmentList(): array
    {
        $environment = [
            'PATH' => self::DEFAULT_PATH,
        ];

        foreach ($this->environment as $name => $value) {
            $environment[$name] = $value;
        }

        $result = [];

        foreach ($environment as $name => $value) {
            $result[] = $name . '=' . $value;
        }

        return $result;
    }
}
