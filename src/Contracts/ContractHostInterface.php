<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

interface ContractHostInterface
{
    public function invoke(string $name,array $arguments,GuestMemoryInterface $memory): int;
}
