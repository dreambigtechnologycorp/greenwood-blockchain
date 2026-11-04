<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

final class ContractStatus
{
    public const OK = 0;
    public const NOT_FOUND = -1;
    public const BUFFER_TOO_SMALL = -2;
    public const INVALID_ARGUMENT = -3;
    public const INSUFFICIENT_BALANCE = -4;
    public const OVERFLOW = -5;
    public const LIMIT_EXCEEDED = -6;
}
