<?php

namespace Greenwood\Contracts;

/**
 * Exception when containerd service is unavailable
 */
class ContainerdUnavailableException extends ContainerdException
{
    public function __construct(
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
