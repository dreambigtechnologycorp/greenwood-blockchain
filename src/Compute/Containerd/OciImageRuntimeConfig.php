<?php

declare(strict_types=1);

namespace Greenwood\Compute\Containerd;

final readonly class OciImageRuntimeConfig
{
    public function __construct(
        public array $command,
        public array $environment,
        public string $workingDirectory,
        public array $exposedPorts
    ) {
        if (
            $this->command === []
            || !array_is_list($this->command)
        ) {
            throw new \InvalidArgumentException(
                'OCI image command is required'
            );
        }

        foreach ($this->command as $argument) {
            if (
                !is_string($argument)
                || $argument === ''
                || str_contains($argument, "\0")
            ) {
                throw new \InvalidArgumentException(
                    'Invalid OCI image command'
                );
            }
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
                'Invalid OCI working directory'
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
                || str_contains($value, "\0")
            ) {
                throw new \InvalidArgumentException(
                    'Invalid OCI environment'
                );
            }
        }

        foreach ($this->exposedPorts as $port) {
            if (
                !is_string($port)
                || !preg_match(
                    '/^[1-9][0-9]{0,4}\/(?:tcp|udp)$/D',
                    $port
                )
            ) {
                throw new \InvalidArgumentException(
                    'Invalid OCI exposed port'
                );
            }

            [$number] = explode('/', $port, 2);

            if ((int) $number > 65535) {
                throw new \InvalidArgumentException(
                    'Invalid OCI exposed port'
                );
            }
        }
    }

    public static function fromDocument(
        array $document
    ): self {
        $config = $document['config'] ?? [];

        if (!is_array($config)) {
            throw new \InvalidArgumentException(
                'OCI config section must be an object'
            );
        }

        $entrypoint =
            self::stringList(
                $config['Entrypoint'] ?? null,
                'Entrypoint'
            );

        $cmd =
            self::stringList(
                $config['Cmd'] ?? null,
                'Cmd'
            );

        $command = [
            ...$entrypoint,
            ...$cmd,
        ];

        $environment = [];

        foreach (
            self::stringList(
                $config['Env'] ?? null,
                'Env'
            )
            as $entry
        ) {
            $separator = strpos($entry, '=');

            if ($separator === false || $separator === 0) {
                throw new \InvalidArgumentException(
                    'Invalid OCI Env entry'
                );
            }

            $name =
                substr(
                    $entry,
                    0,
                    $separator
                );

            $value =
                substr(
                    $entry,
                    $separator + 1
                );

            $environment[$name] = $value;
        }

        $workingDirectory =
            $config['WorkingDir'] ?? '/';

        if ($workingDirectory === '') {
            $workingDirectory = '/';
        }

        if (!is_string($workingDirectory)) {
            throw new \InvalidArgumentException(
                'Invalid OCI WorkingDir'
            );
        }

        $ports = [];

        $exposed =
            $config['ExposedPorts'] ?? [];

        if (!is_array($exposed)) {
            throw new \InvalidArgumentException(
                'Invalid OCI ExposedPorts'
            );
        }

        foreach (array_keys($exposed) as $port) {
            $ports[] = $port;
        }

        sort($ports, SORT_STRING);

        return new self(
            $command,
            $environment,
            $workingDirectory,
            $ports
        );
    }

    private static function stringList(
        mixed $value,
        string $field
    ): array {
        if ($value === null) {
            return [];
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException(
                "OCI {$field} must be a list"
            );
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException(
                    "OCI {$field} must contain strings"
                );
            }
        }

        return $value;
    }
}
