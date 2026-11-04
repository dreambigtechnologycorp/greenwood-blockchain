<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

final readonly class ContractEvent
{
    public function __construct(public string $contractId, public string $topic, public string $data, public int $eventIndex) {}
}
