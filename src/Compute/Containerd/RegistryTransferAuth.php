<?php

declare(strict_types=1);

namespace Greenwood\Compute\Containerd;

final readonly class RegistryTransferAuth
{
    public function __construct(
        private string $bearerToken,
        private string $scheme = 'https'
    ) {
        if (
            $this->bearerToken === ''
            || strlen($this->bearerToken) > 8192
            || str_contains($this->bearerToken, "\r")
            || str_contains($this->bearerToken, "\n")
        ) {
            throw new \InvalidArgumentException(
                'Invalid registry bearer token'
            );
        }

        if (!in_array($this->scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException(
                'Registry scheme must be http or https'
            );
        }
    }

    public function authorizationHeader(): string
    {
        return 'Bearer ' . $this->bearerToken;
    }

    public function scheme(): string
    {
        return $this->scheme;
    }
}
