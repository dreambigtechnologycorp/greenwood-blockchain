<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

use Greenwood\State\State;
/** Detached snapshot: even the embedding block executor cannot change guest reads. */
final readonly class ContractStateView implements ContractStateViewInterface
{
    private State $snapshot;
    public function __construct(State $state) { $this->snapshot=clone $state; }
    public function storage(string $contractId,string $key): ?string { return $this->snapshot->getContractStorage($contractId,$key); }
    public function accountBalance(string $address): int { return $this->snapshot->hasAccount($address)?$this->snapshot->getAccount($address)->getBalance():0; }
    public function contractBalance(string $contractId): int { return $this->snapshot->getContractBalance($contractId); }
}
