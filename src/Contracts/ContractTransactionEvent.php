<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

final readonly class ContractTransactionEvent
{
    public function __construct(public string $contractId,public string $topic,public string $data,public string $transactionHash,public int $eventIndex) {}
    public function toArray(): array { return ['contract_id'=>bin2hex($this->contractId),'topic'=>bin2hex($this->topic),'data'=>bin2hex($this->data),'transaction_hash'=>bin2hex($this->transactionHash),'event_index'=>$this->eventIndex]; }
    public static function fromArray(array $a): self { return new self(hex2bin($a['contract_id']),hex2bin($a['topic']),hex2bin($a['data']),hex2bin($a['transaction_hash']),$a['event_index']); }
}
