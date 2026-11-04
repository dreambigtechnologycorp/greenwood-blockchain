<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

/** Must present a stable, read-only speculative-state view for one execution.
 * Missing storage is null, missing balances are zero. Backend failures throw.
 * All identifiers and keys are raw bytes. There is deliberately no write API.
 */
interface ContractStateViewInterface
{
    public function storage(string $contractId, string $key): ?string;
    public function accountBalance(string $address): int;
    public function contractBalance(string $contractId): int;
}
