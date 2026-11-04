<?php

declare(strict_types=1);

namespace Greenwood\State;

use Greenwood\Transaction\Envelope;

use Greenwood\Crypto\ProtocolHash;
use Greenwood\Crypto\MerkleTree;

/**
 * State represents the current state of the Greenwood blockchain.
 * 
 * This is the speculative state that changes as blocks are processed.
 * The SQLite database is a derived index that can be rebuilt from blocks.
 */
class State
{
    private array $accounts = [];
    private array $contractCode = [];
    private array $contractStorage = [];
    private array $contractBalances = [];
    private array $validators = [];
    private array $receipts = [];
    private \Greenwood\Compute\Protocol\ComputeState $compute;
    private \Greenwood\Compute\Verification\GenesisProviderEligibility $providerEligibility;
    private int $height = 0;
    private string $stateRoot;
    
    /**
     * Create a new empty state.
     */
    public function __construct()
    {
        $this->compute = new \Greenwood\Compute\Protocol\ComputeState();
        $this->providerEligibility = new \Greenwood\Compute\Verification\GenesisProviderEligibility();
        $this->stateRoot = $this->computeStateRoot();
    }
    
    /**
     * Get or create an account.
     * 
     * @param string $address 32-byte address
     * @return Account
     */
    public function getAccount(string $address): Account
    {
        if (!isset($this->accounts[$address])) {
            $this->accounts[$address] = new Account($address, 0, 0);
        }
        return $this->accounts[$address];
    }
    
    /**
     * Check if an account exists.
     * 
     * @param string $address 32-byte address
     * @return bool
     */
    public function hasAccount(string $address): bool
    {
        return isset($this->accounts[$address]);
    }
    
    /**
     * Set an account balance.
     * 
     * @param string $address 32-byte address
     * @param int $balance
     * @return self
     */
    public function setAccountBalance(string $address, int $balance): self
    {
        $account = $this->getAccount($address);
        $account->setBalance($balance);
        return $this;
    }
    
    /**
     * Set an account nonce.
     * 
     * @param string $address 32-byte address
     * @param int $nonce
     * @return self
     */
    public function setAccountNonce(string $address, int $nonce): self
    {
        $account = $this->getAccount($address);
        $account->setNonce($nonce);
        return $this;
    }
    
    /**
     * Get all accounts.
     * 
     * @return Account[]
     */
    public function getAccounts(): array
    {
        return $this->accounts;
    }
    
    /**
     * Set contract code.
     * 
     * @param string $contractId 32-byte contract ID
     * @param string $wasm WASM bytecode
     * @param string $wasmHash Hash of WASM
     * @return self
     */
    public function setContractCode(string $contractId, string $wasm, string $wasmHash): self
    {
        if (strlen($contractId)!==32 || $wasmHash!==$contractId || \Greenwood\Contracts\ContractId::fromWasm($wasm)->bytes!==$contractId
            || $wasm==='' || strlen($wasm)>\Greenwood\Contracts\ContractTransactionLimits::MAX_WASM_BYTES) throw new \InvalidArgumentException('Invalid contract code');
        if (isset($this->contractCode[$contractId])) throw new \InvalidArgumentException('Contract code is immutable');
        $this->contractCode[$contractId] = ['wasm'=>$wasm,'wasm_hash'=>$wasmHash,'abi_version'=>1];
        $this->contractBalances[$contractId]=0;
        return $this;
    }
    
    /**
     * Get contract code.
     * 
     * @param string $contractId 32-byte contract ID
     * @return array|null
     */
    public function getContractCode(string $contractId): ?array
    {
        return $this->contractCode[$contractId] ?? null;
    }
    
    /**
     * Set contract storage.
     * 
     * @param string $contractId 32-byte contract ID
     * @param string $key Storage key
     * @param string $value Storage value
     * @return self
     */
    public function setContractStorage(string $contractId, string $key, string $value): self
    {
        $this->validateStorageKey($contractId,$key);
        if (strlen($value)>\Greenwood\Contracts\ContractLimits::MAX_STORAGE_VALUE_BYTES) throw new \InvalidArgumentException('Storage value too large');
        if (!isset($this->contractStorage[$contractId])) {
            $this->contractStorage[$contractId] = [];
        }
        $this->contractStorage[$contractId]["\0".$key] = $value;
        return $this;
    }
    
    /**
     * Get contract storage.
     * 
     * @param string $contractId 32-byte contract ID
     * @param string $key Storage key
     * @return string|null
     */
    public function getContractStorage(string $contractId, string $key): ?string
    {
        return $this->contractStorage[$contractId]["\0".$key] ?? null;
    }
    
    /**
     * Delete contract storage.
     * 
     * @param string $contractId 32-byte contract ID
     * @param string $key Storage key
     * @return self
     */
    public function deleteContractStorage(string $contractId, string $key): self
    {
        $this->validateStorageKey($contractId,$key);
        if (isset($this->contractStorage[$contractId]["\0".$key])) {
            unset($this->contractStorage[$contractId]["\0".$key]);
        }
        return $this;
    }
    
    /**
     * Set contract balance.
     * 
     * @param string $contractId 32-byte contract ID
     * @param int $balance
     * @return self
     */
    public function setContractBalance(string $contractId, int $balance): self
    {
        if (!isset($this->contractCode[$contractId]) || $balance<0) throw new \InvalidArgumentException('Invalid contract balance');
        $this->contractBalances[$contractId] = $balance;
        return $this;
    }
    
    /**
     * Get contract balance.
     * 
     * @param string $contractId 32-byte contract ID
     * @return int
     */
    public function getContractBalance(string $contractId): int
    {
        return $this->contractBalances[$contractId] ?? 0;
    }
    
    private function validateStorageKey(string $id,string $key): void
    {
        if (!isset($this->contractCode[$id]) || $key==='' || strlen($key)>\Greenwood\Contracts\ContractLimits::MAX_STORAGE_KEY_BYTES) throw new \InvalidArgumentException('Invalid contract storage key');
    }
    public function getContractCodes(): array { return $this->contractCode; }
    public function getContractBalances(): array { return $this->contractBalances; }
    public function getContractStorageRecords(): array
    {
        $records=[];
        foreach ($this->contractStorage as $id=>$entries) foreach ($entries as $key=>$value) $records[]=[$id,substr($key,1),$value];
        return $records;
    }
    public function getContractReceipts(): array { return $this->receipts; }
    public function getContractReceipt(string $hash): ?\Greenwood\Contracts\ContractReceipt { return $this->receipts[$hash]??null; }
    public function indexContractReceipt(\Greenwood\Contracts\ContractReceipt $receipt): void { $this->receipts[$receipt->transactionHash]=$receipt; }

    /**
     * Transfer tokens between accounts.
     * 
     * @param string $from
     * @param string $to
     * @param int $amount
     * @return bool
     */
    public function transfer(string $from, string $to, int $amount): bool
    {
        $fromAccount = $this->getAccount($from);
        if ($fromAccount->getBalance() < $amount) {
            return false;
        }
        
        $fromAccount->setBalance($fromAccount->getBalance() - $amount);
        $toAccount = $this->getAccount($to);
        $toAccount->setBalance($toAccount->getBalance() + $amount);
        
        return true;
    }
    
    /**
     * Increment account nonce.
     * 
     * @param string $address 32-byte address
     * @return self
     */
    public function incrementNonce(string $address): self
    {
        $account = $this->getAccount($address);
        $account->incrementNonce();
        return $this;
    }
    
    /**
     * Set the chain height.
     * 
     * @param int $height
     * @return self
     */
    public function setChainHeight(int $height): self
    {
        $this->height = $height;
        return $this;
    }
    
    /**
     * Apply a transaction to the state.
     * This updates account balances, nonces, and contract state.
     *
     * @param Envelope $tx The transaction to apply
     * @return self
     */
    public function applyTransaction(Envelope $tx, ?string $chainId = null, ?int $blockHeight = null): self
    {
        if ($chainId === null) throw new \InvalidArgumentException('Expected chain required');
        $tx->verify($chainId);
        $sender = $tx->getSender();
        $account = $this->accounts[$sender] ?? new Account($sender, 0, 0);
        if ($tx->getNonce()!==$account->getNonce() || $account->getNonce()===0xffffffff) throw new \InvalidArgumentException('Invalid/exhausted nonce');
        if (in_array($tx->getType(), [Envelope::TYPE_COMPUTE_REQUEST, Envelope::TYPE_COMPUTE_ASSIGN,
                Envelope::TYPE_COMPUTE_RECEIPT, Envelope::TYPE_PROVIDER_CAPABILITY], true)) {
            if ($blockHeight === null || $blockHeight < 1) throw new \InvalidArgumentException('Explicit candidate inclusion height required');
            $next = clone $this;
            $next->compute = $this->compute->apply($tx, $blockHeight, $this->providerEligibility);
            $next->incrementNonce($sender)->recomputeStateRoot();
            return $this->copyFrom($next);
        }
        if (in_array($tx->getType(),[Envelope::TYPE_DEPLOY_CONTRACT,Envelope::TYPE_CALL_CONTRACT],true)) {
            $next=clone $this;
            $receipt=\Greenwood\Contracts\ContractTransactionExecutor::apply($next,$tx);
            $next->incrementNonce($sender)->recomputeStateRoot();
            $next->indexContractReceipt($receipt);
            return $this->copyFrom($next);
        }
        $result = \Greenwood\Transaction\Transfer::validate($tx, $sender, $account->getNonce(), $account->getBalance(), $chainId);
        if (!$result['valid'] || $account->getNonce() === 0xffffffff) throw new \InvalidArgumentException($result['error'] ?? 'Nonce exhausted');
        $recipient = $result['recipient']; $amount = $result['amount'];
        $balance = ($this->accounts[$recipient] ?? new Account($recipient))->getBalance();
        if ($recipient !== $sender && $balance > PHP_INT_MAX - $amount) throw new \InvalidArgumentException('Balance overflow');
        $this->transfer($sender, $recipient, $amount);
        $this->incrementNonce($sender);
        return $this->recomputeStateRoot();
    }

    public function __clone()
    {
        foreach ($this->accounts as $address => $account) $this->accounts[$address] = clone $account;
    }

    public function setValidators(array $validators): self { $this->validators = $validators; return $this; }

    public function setProviderEligibility(\Greenwood\Compute\Verification\GenesisProviderEligibility $eligibility): self
    {
        $this->providerEligibility = $eligibility;
        return $this;
    }
    public function getProviderEligibility(): \Greenwood\Compute\Verification\GenesisProviderEligibility { return $this->providerEligibility; }
    public function getComputeState(): \Greenwood\Compute\Protocol\ComputeState { return $this->compute; }
    /** Only used to inspect a derived index; never used by block execution/replay. */
    public function restoreDerivedComputeRecords(array $records): self
    {
        $this->compute = \Greenwood\Compute\Protocol\ComputeState::fromDerivedRecords($records, $this->providerEligibility);
        return $this;
    }

    /**
     * Reset the state to initial values.
     * Used when initializing genesis state.
     * 
     * @return self
     */
    public function reset(): self
    {
        $this->accounts = [];
        $this->contractCode = [];
        $this->contractStorage = [];
        $this->contractBalances = [];
        $this->validators = [];
        $this->receipts = [];
        $this->compute = new \Greenwood\Compute\Protocol\ComputeState();
        $this->providerEligibility = new \Greenwood\Compute\Verification\GenesisProviderEligibility();
        $this->height = 0;
        $this->stateRoot = '';
        return $this;
    }
    
    /**
     * Get the current state root.
     * 
     * @return string 32-byte hash
     */
    public function getStateRoot(): string
    {
        return $this->stateRoot;
    }
    
    /**
     * Recompute and update the state root.
     * 
     * @return self
     */
    public function recomputeStateRoot(): self
    {
        $this->stateRoot = $this->computeStateRoot();
        return $this;
    }
    
    /**
     * Compute the state root from current state.
     * 
     * @return string 32-byte Merkle root
     */
    public function logicalRecords(): array
    {
        $records = [];
        $c = \Greenwood\Codec\Canonical::class;
        foreach ($this->accounts as $address => $account) {
            $records[] = ['accounts', $address, $c::encodeU64BE($account->getBalance()) . $c::encodeU32BE($account->getNonce())];
        }
        foreach ($this->validators as $id => $validator) {
            $records[] = ['validators', $id, $validator['publicKey'] . $c::encodeU32BE($validator['votingPower'])];
        }
        foreach ($this->contractCode as $id => $code) $records[] = ['contract_code', $id, $c::encodeU32BE($code['abi_version']).$code['wasm_hash']];
        foreach ($this->contractStorage as $id => $storage) foreach ($storage as $key => $value) $records[] = ['contract_storage', $id . $c::encodeBytes(substr($key,1)), $value];
        foreach ($this->contractBalances as $id => $balance) $records[] = ['contract_balances', $id, $c::encodeU64BE($balance)];
        array_push($records, ...$this->compute->logicalRecords());
        usort($records, fn($a, $b) => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));
        return $records;
    }
    private function computeStateRoot(): string
    {
        $c=\Greenwood\Codec\Canonical::class;
        $records=$this->logicalRecords();
        $tree = new MerkleTree('GREENWOOD_STATE_LEAF_V1', 'GREENWOOD_STATE_NODE_V1', 'GREENWOOD_STATE_EMPTY_V1');
        foreach ($records as [$namespace, $key, $value]) $tree->addLeaf($c::encodeBytes($namespace) . $c::encodeBytes($key) . $c::encodeBytes($value));
        return $tree->getRoot();
    }

    /**
     * Copy state from another state object.
     * 
     * @param self $other
     * @return self
     */
    public function copyFrom(self $other): self
    {
        $copy = clone $other;
        $this->accounts = $copy->accounts;
        $this->validators = $other->validators;
        $this->contractCode = $other->contractCode;
        $this->contractStorage = $other->contractStorage;
        $this->contractBalances = $other->contractBalances;
        $this->receipts = $other->receipts;
        $this->compute = $other->compute;
        $this->providerEligibility = $other->providerEligibility;
        $this->height = $other->height;
        $this->stateRoot = $other->stateRoot;
        return $this;
    }
    
}
