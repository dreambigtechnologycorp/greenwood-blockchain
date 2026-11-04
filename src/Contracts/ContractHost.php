<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

/** No native handles or canonical State: only context and overlay. */
final class ContractHost implements ContractHostInterface
{
    private int $calls=0;
    public function __construct(private readonly ContractExecutionContext $context,private readonly ExecutionOverlay $overlay) {}
    public function invoke(string $name,array $arguments,GuestMemoryInterface $memory): int
    {
        if (++$this->calls>ContractLimits::MAX_HOST_CALLS) throw new HostCallException('Host call budget exceeded');
        try {
            return match ($name) {
                'input_size'=>strlen($this->context->input),
                'input_read'=>$this->inputRead($memory,...$arguments),
                'caller'=>$this->writeIdentity($memory,$arguments[0],$this->context->caller),
                'contract_id'=>$this->writeIdentity($memory,$arguments[0],$this->context->contractId),
                'storage_get'=>$this->storageGet($memory,...$arguments),
                'storage_set'=>$this->storageSet($memory,...$arguments),
                'storage_delete'=>$this->storageDelete($memory,...$arguments),
                'attached_value'=>$this->context->attachedValue,
                'contract_balance'=>$this->overlay->contractBalance(),
                'transfer'=>$this->transfer($memory,...$arguments),
                'emit_event'=>$this->emitEvent($memory,...$arguments),
                'return_value'=>$this->returnValue($memory,...$arguments),
                default=>throw new HostCallException('Unregistered host function'),
            };
        } catch (GuestArgumentException $e) { return $e->getCode(); }
    }
    private function inputRead(GuestMemoryInterface $memory,int $ptr,int $length): int
    {
        $memory->checkRange($ptr,$length);
        if ($length>strlen($this->context->input)) return ContractStatus::INVALID_ARGUMENT;
        $memory->writeBytes($ptr,substr($this->context->input,0,$length));
        return ContractStatus::OK;
    }

    private function writeIdentity(GuestMemoryInterface $memory,int $ptr,string $bytes): int
    {
        $memory->writeBytes($ptr,$bytes);
        return ContractStatus::OK;
    }


    private function readLimited(GuestMemoryInterface $memory,int $ptr,int $length,int $limit,bool $nonempty=false): string
    {
        if ($ptr<0 || $length<0) throw new HostCallException('Negative guest memory range');
        if ($length>$limit || ($nonempty && $length===0)) throw new GuestArgumentException('Invalid byte length',ContractStatus::INVALID_ARGUMENT);
        return $memory->readBytes($ptr,$length);
    }
    private function storageGet(GuestMemoryInterface $memory,int $keyPtr,int $keyLen,int $outPtr,int $capacity): int
    {
        $key=$this->readLimited($memory,$keyPtr,$keyLen,ContractLimits::MAX_STORAGE_KEY_BYTES,true);
        $memory->checkRange($outPtr,$capacity);
        $value=$this->overlay->storageGet($key);
        if ($value===null) return ContractStatus::NOT_FOUND;
        if (strlen($value)>$capacity) return ContractStatus::BUFFER_TOO_SMALL;
        $memory->writeBytes($outPtr,$value);
        return strlen($value);
    }

    private function storageSet(GuestMemoryInterface $memory,int $keyPtr,int $keyLen,int $valuePtr,int $valueLen): int
    {
        $key=$this->readLimited($memory,$keyPtr,$keyLen,ContractLimits::MAX_STORAGE_KEY_BYTES,true);
        $value=$this->readLimited($memory,$valuePtr,$valueLen,ContractLimits::MAX_STORAGE_VALUE_BYTES);
        $this->overlay->storageSet($key,$value);
        return ContractStatus::OK;
    }

    private function storageDelete(GuestMemoryInterface $memory,int $keyPtr,int $keyLen): int
    {
        $this->overlay->storageDelete($this->readLimited($memory,$keyPtr,$keyLen,ContractLimits::MAX_STORAGE_KEY_BYTES,true));
        return ContractStatus::OK;
    }



    private function transfer(GuestMemoryInterface $memory,int $recipientPtr,int $amount): int
    {
        $recipient=$memory->readBytes($recipientPtr,32);
        $this->overlay->transfer($recipient,$amount);
        return ContractStatus::OK;
    }

    private function emitEvent(GuestMemoryInterface $memory,int $topicPtr,int $topicLen,int $dataPtr,int $dataLen): int
    {
        $topic=$this->readLimited($memory,$topicPtr,$topicLen,ContractLimits::MAX_EVENT_TOPIC_BYTES);
        $data=$this->readLimited($memory,$dataPtr,$dataLen,ContractLimits::MAX_EVENT_DATA_BYTES);
        $this->overlay->emitEvent($topic,$data);
        return ContractStatus::OK;
    }

    private function returnValue(GuestMemoryInterface $memory,int $ptr,int $length): int
    {
        $this->overlay->setReturnValue($this->readLimited($memory,$ptr,$length,ContractLimits::MAX_RETURN_BYTES));
        return ContractStatus::OK;
    }

}
