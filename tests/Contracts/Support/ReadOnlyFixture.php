<?php
declare(strict_types=1);
namespace Greenwood\Tests\Contracts\Support;
use Greenwood\Contracts\ContractStateViewInterface;
final readonly class ReadOnlyFixture implements ContractStateViewInterface
{
    public function __construct(public int $accounts=100,public int $contracts=20,public bool $fail=false) {}
    public function storage(string $contractId,string $key): ?string
    {
        if ($this->fail) throw new \RuntimeException('Backend unavailable');
        return $key==='x'?'old':null;
    }
    public function accountBalance(string $address): int { return $this->accounts; }
    public function contractBalance(string $contractId): int { return $this->contracts; }
}
