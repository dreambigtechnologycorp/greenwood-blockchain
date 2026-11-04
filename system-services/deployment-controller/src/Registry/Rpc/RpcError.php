<?php

declare(strict_types=1);
namespace Greenwood\DeploymentController\Registry\Rpc;

final class RpcError extends \RuntimeException implements \JsonSerializable
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $rpcCode,
        string $message,
        public readonly array $details,
        public readonly string $endpoint,
        public readonly string $method,
        public readonly string $path,
        public readonly string $retryClass,
        public readonly bool $remote = false,
    ) { parent::__construct($message, $httpStatus); }

    public function jsonSerialize(): array
    {
        return ['httpStatus' => $this->httpStatus, 'code' => $this->rpcCode, 'message' => $this->getMessage(),
            'details' => (object)$this->details, 'endpoint' => $this->endpoint, 'method' => $this->method,
            'path' => $this->path, 'retryClass' => $this->retryClass, 'remote' => $this->remote];
    }
}
