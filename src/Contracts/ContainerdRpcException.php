<?php

namespace Greenwood\Contracts;

/**
 * Exception for containerd gRPC RPC errors
 */
class ContainerdRpcException extends ContainerdException
{
    public const RPC_TIMEOUT = 4;
    public const RPC_UNAVAILABLE = 14;
    public const RPC_INVALID_ARGUMENT = 3;
    public const RPC_NOT_FOUND = 5;
    public const RPC_PERMISSION_DENIED = 7;
    public const RPC_FAILED_PRECONDITION = 9;
    public const RPC_INTERNAL = 13;
    
    public function __construct(
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
