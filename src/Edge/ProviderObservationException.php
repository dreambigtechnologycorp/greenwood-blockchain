<?php

declare(strict_types=1);
namespace Greenwood\Edge;
final class ProviderObservationException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Provider observation rejected: ' . $reason);
    }
}
