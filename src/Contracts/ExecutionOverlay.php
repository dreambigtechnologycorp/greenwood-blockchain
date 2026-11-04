<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

/** Single-contract effects. Never holds a mutable canonical State or a commit API.
 * Prefix array keys with NUL so PHP cannot coerce numeric byte strings to integers.
 */
final class ExecutionOverlay
{
    private array $storageWrites = [];
    private array $storageDeletes = [];
    private array $accountBalanceDeltas = [];
    private array $contractBalanceDeltas = [];
    private array $events = [];
    private string $returnValue = '';
    private int $transfers = 0;
    private bool $closed = false;
    private bool $discarded = false;
    private bool $attached = false;
    private array $baseAccounts=[];
    private ?int $baseContract=null;

    public function __construct(public readonly ContractExecutionContext $context, private readonly ContractStateViewInterface $state) {}
    private function writable(): void { if ($this->closed) throw new \LogicException('Overlay is closed'); }
    private function key(string $key): string
    {
        if ($key === '' || strlen($key) > ContractLimits::MAX_STORAGE_KEY_BYTES) throw new GuestArgumentException('Invalid storage key', ContractStatus::INVALID_ARGUMENT);
        return "\0".$key;
    }
    public function storageGet(string $key): ?string
    {
        $k=$this->key($key);
        if (isset($this->storageDeletes[$k])) return null;
        if (array_key_exists($k,$this->storageWrites)) return $this->storageWrites[$k];
        try { $value=$this->state->storage($this->context->contractId,$key); }
        catch (\Throwable $e) { throw new HostCallException('Storage backend failure',0,$e); }
        if ($value !== null && strlen($value)>ContractLimits::MAX_STORAGE_VALUE_BYTES) throw new HostCallException('Invalid backend storage value');
        return $value;
    }
    private function storageSlot(string $k): void
    {
        if (!array_key_exists($k,$this->storageWrites) && !isset($this->storageDeletes[$k])
            && count($this->storageWrites)+count($this->storageDeletes)>=ContractLimits::MAX_STORAGE_KEYS_PER_CALL) {
            throw new GuestArgumentException('Storage key limit', ContractStatus::LIMIT_EXCEEDED);
        }
    }
    public function storageSet(string $key,string $value): void
    {
        $this->writable(); $k=$this->key($key);
        if (strlen($value)>ContractLimits::MAX_STORAGE_VALUE_BYTES) throw new GuestArgumentException('Value limit',ContractStatus::INVALID_ARGUMENT);
        $this->storageSlot($k); unset($this->storageDeletes[$k]); $this->storageWrites[$k]=$value;
    }
    public function storageDelete(string $key): void
    {
        $this->writable(); $k=$this->key($key); $this->storageSlot($k);
        unset($this->storageWrites[$k]); $this->storageDeletes[$k]=true;
    }
    private static function checked(int $balance,int $delta): int
    {
        if ($balance<0) throw new HostCallException('Negative backend balance');
        if ($delta>0 && $balance>PHP_INT_MAX-$delta) throw new GuestArgumentException('Balance overflow',ContractStatus::OVERFLOW);
        if ($delta<0 && $delta<-$balance) throw new GuestArgumentException('Insufficient balance',ContractStatus::INSUFFICIENT_BALANCE);
        return $balance+$delta;
    }
    private function baseAccountBalance(string $address): int
    {
        $key="\0".$address;
        if (!array_key_exists($key,$this->baseAccounts)) {
            try { $balance=$this->state->accountBalance($address); }
            catch (\Throwable $e) { throw new HostCallException('Account backend failure',0,$e); }
            if ($balance<0) throw new HostCallException('Negative backend account balance');
            $this->baseAccounts[$key]=$balance;
        }
        return $this->baseAccounts[$key];
    }
    private function baseContractBalance(): int
    {
        if ($this->baseContract===null) {
            try { $balance=$this->state->contractBalance($this->context->contractId); }
            catch (\Throwable $e) { throw new HostCallException('Contract backend failure',0,$e); }
            if ($balance<0) throw new HostCallException('Negative backend contract balance');
            $this->baseContract=$balance;
        }
        return $this->baseContract;
    }
    public function accountBalance(string $address): int
    {
        if (strlen($address)!==32) throw new GuestArgumentException('Invalid address',ContractStatus::INVALID_ARGUMENT);
        return self::checked($this->baseAccountBalance($address),$this->accountBalanceDeltas["\0".$address]??0);
    }
    public function contractBalance(): int
    {
        $id=$this->context->contractId;
        return self::checked($this->baseContractBalance(),$this->contractBalanceDeltas["\0".$id]??0);
    }
    /** Compute both sides before recording either side. Deltas stay within +/-PHP_INT_MAX. */
    public function attachValue(): void
    {
        $this->writable();
        if ($this->attached || $this->accountBalanceDeltas!==[] || $this->contractBalanceDeltas!==[]) throw new \LogicException('Attached value must be applied first, once');
        $amount=$this->context->attachedValue;
        self::checked($this->accountBalance($this->context->caller),-$amount);
        self::checked($this->contractBalance(),$amount);
        $this->attached=true;
        if ($amount===0) return;
        $this->accountBalanceDeltas["\0".$this->context->caller]=-$amount;
        $this->contractBalanceDeltas["\0".$this->context->contractId]=$amount;
    }
    public function transfer(string $recipient,int $amount): void
    {
        $this->writable();
        if (strlen($recipient)!==32 || $amount<=0) throw new GuestArgumentException('Invalid transfer',ContractStatus::INVALID_ARGUMENT);
        if ($this->transfers>=ContractLimits::MAX_HOST_TRANSFER_OPERATIONS) throw new GuestArgumentException('Transfer limit',ContractStatus::LIMIT_EXCEEDED);
        $contract=self::checked($this->contractBalance(),-$amount);
        $account=self::checked($this->accountBalance($recipient),$amount);
        // Subtracting two nonnegative supported balances cannot overflow.
        $contractDelta=$contract-$this->baseContractBalance();
        $accountDelta=$account-$this->baseAccountBalance($recipient);
        $this->contractBalanceDeltas["\0".$this->context->contractId]=$contractDelta;
        $this->accountBalanceDeltas["\0".$recipient]=$accountDelta;
        $this->transfers++;
    }
    public function emitEvent(string $topic,string $data): void
    {
        $this->writable();
        if (strlen($topic)>ContractLimits::MAX_EVENT_TOPIC_BYTES || strlen($data)>ContractLimits::MAX_EVENT_DATA_BYTES) throw new GuestArgumentException('Event size',ContractStatus::INVALID_ARGUMENT);
        if (count($this->events)>=ContractLimits::MAX_EVENTS_PER_CALL) throw new GuestArgumentException('Event limit',ContractStatus::LIMIT_EXCEEDED);
        $this->events[]=new ContractEvent($this->context->contractId,$topic,$data,count($this->events));
    }
    public function setReturnValue(string $bytes): void
    {
        $this->writable();
        if (strlen($bytes)>ContractLimits::MAX_RETURN_BYTES) throw new GuestArgumentException('Return size',ContractStatus::INVALID_ARGUMENT);
        $this->returnValue=$bytes;
    }
    public function effects(): array
    {
        return ['storageWrites'=>$this->storageWrites,'storageDeletes'=>$this->storageDeletes,
            'accountBalanceDeltas'=>$this->accountBalanceDeltas,'contractBalanceDeltas'=>$this->contractBalanceDeltas,
            'events'=>$this->events,'returnValue'=>$this->returnValue];
    }
    public function isSealed(): bool { return $this->closed && !$this->discarded; }
    public function seal(): void { $this->closed=true; }
    public function discard(): void
    {
        $this->storageWrites=$this->storageDeletes=$this->accountBalanceDeltas=$this->contractBalanceDeltas=$this->events=[];
        $this->returnValue=''; $this->closed=true; $this->discarded=true;
    }
}
