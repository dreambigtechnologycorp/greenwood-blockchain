<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

/** Derived execution data, not a state record or block-header field. */
final readonly class ContractReceipt
{
    public function __construct(public string $status,public int $gasUsed,public string $returnData,public array $events,public string $contractId,public string $transactionHash) {}
    /** Display/index form only; byte strings are hex, integers are exact. */
    public function toArray(): array
    {
        return ['status'=>$this->status,'gas_used'=>$this->gasUsed,'return_data'=>bin2hex($this->returnData),'contract_id'=>bin2hex($this->contractId),'transaction_hash'=>bin2hex($this->transactionHash),
            'events'=>array_map(fn(ContractTransactionEvent $e)=>$e->toArray(),$this->events)];
    }
    public static function fromArray(array $a): self
    {
        return new self($a['status'],$a['gas_used'],hex2bin($a['return_data']),array_map(fn(array $e)=>ContractTransactionEvent::fromArray($e),$a['events']),hex2bin($a['contract_id']),hex2bin($a['transaction_hash']));
    }
}
