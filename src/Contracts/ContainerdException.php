<?php

namespace Greenwood\Contracts;

/**
 * Base exception for containerd-related errors
 */
class ContainerdException extends \RuntimeException
{
    public const CODE_VALIDATION = 1;
    public const CODE_INVALID_ARGUMENT = 2;
    public const CODE_NOT_FOUND = 3;
    public const CODE_UNAVAILABLE = 4;
    
    public function __construct(
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
    
    public function getContainerdCode(): int
    {
        return $this->getCode();
    }
}
