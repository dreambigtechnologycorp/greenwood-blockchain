<?php

declare(strict_types=1);

namespace Greenwood\Genesis;

use Greenwood\Crypto\ProtocolHash;
use Greenwood\Block\Block;

class Genesis
{
    public const DEFAULT_CHAIN_ID = "greenwood-local-v1";
    public const PROTOCOL_VERSION = 1;
    
    private array $validators = [];
    private array $initialAccounts = [];
    private string $chainId;
    private ?string $genesisHash = null;
    
    public function __construct(string $chainId = self::DEFAULT_CHAIN_ID)
    {
        $this->chainId = $chainId;
    }
    
    public function addValidator(string $validatorId, $keyPair, int $votingPower = 1): self
    {
        if (strlen($validatorId ?? $accountId ?? '') < 1 || strlen($validatorId ?? $accountId ?? '') > 32) throw new \InvalidArgumentException('Invalid genesis label');
        $this->genesisHash = null;
        $publicKey = $keyPair->getPublicKey();
        if (strlen($publicKey) !== 32) throw new \InvalidArgumentException('Invalid public key');
        if ($votingPower !== 1) throw new \InvalidArgumentException('Fixed equal power required');
        $this->validators[$validatorId] = [
            'publicKey' => $publicKey,
            'votingPower' => $votingPower
        ];
        return $this;
    }
    
    public function addInitialAccount(string $accountId, $keyPair, int $balance = 0): self
    {
        if (strlen($validatorId ?? $accountId ?? '') < 1 || strlen($validatorId ?? $accountId ?? '') > 32) throw new \InvalidArgumentException('Invalid genesis label');
        $this->genesisHash = null;
        $publicKey = $keyPair->getPublicKey();
        if (strlen($publicKey) !== 32) throw new \InvalidArgumentException('Invalid public key');
        if ($balance < 0) throw new \InvalidArgumentException('Invalid initial balance');
        $address = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $publicKey);
        $this->initialAccounts[$accountId] = [
            'address' => $address,
            'publicKey' => $publicKey,
            'balance' => $balance
        ];
        return $this;
    }
    
    public function getValidators(): array { return $this->validators; }
    public function getInitialAccounts(): array { return $this->initialAccounts; }
    public function getChainId(): string { return $this->chainId; }
    
    public function getGenesisHash(): string
    {
        if ($this->genesisHash === null) {
            $this->genesisHash = $this->computeGenesisHash();
        }
        return $this->genesisHash;
    }
    
    public function toJson(): string
    {
        $validators=[]; $accounts=[];
        foreach ($this->validators as $id=>$v) $validators[$id]=['public_key'=>bin2hex($v['publicKey']),'power'=>$v['votingPower']];
        foreach ($this->initialAccounts as $id=>$a) $accounts[$id]=['public_key'=>bin2hex($a['publicKey']),'balance'=>$a['balance']];
        return json_encode(['chain_id'=>$this->chainId,'protocol_version'=>1,'wasm_abi_version'=>1,'compute_version'=>1,'validators'=>$validators,'accounts'=>$accounts],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
    }
    
    public function getCanonicalBytes(): string
    {
        $bytes = '';
        
        // Protocol version (4 bytes, big-endian)
        $bytes .= pack('NNN', self::PROTOCOL_VERSION, 1, 1);
        
        // Chain ID (2 bytes length + content)
        $bytes .= pack('n', strlen($this->chainId)) . $this->chainId;
        
        // Number of validators (4 bytes)
        $bytes .= pack('N', count($this->validators));
        
        // Validators in sorted order by ID for determinism
        $sortedValidators = $this->validators;
        ksort($sortedValidators, SORT_STRING);
        foreach ($sortedValidators as $id => $v) {
            // Validator ID (1 byte length + content)
            $bytes .= pack('C', strlen($id)) . $id;
            // Public key (32 bytes)
            $bytes .= $v['publicKey'];
            // Voting power (4 bytes)
            $bytes .= pack('N', $v['votingPower']);
        }
        
        // Number of initial accounts (4 bytes)
        $bytes .= pack('N', count($this->initialAccounts));
        
        // Initial accounts in sorted order by ID for determinism
        $sortedAccounts = $this->initialAccounts;
        ksort($sortedAccounts, SORT_STRING);
        foreach ($sortedAccounts as $id => $a) {
            // Account ID (1 byte length + content)
            $bytes .= pack('C', strlen($id)) . $id;
            // Address (32 bytes)
            $bytes .= $a['address'];
            // Balance (8 bytes, big-endian)
            $bytes .= \Greenwood\Codec\Canonical::encodeU64BE($a['balance']);
        }
        
        return $bytes;
    }
    
    public function createGenesisBlock(): Block
    {
        if (empty($this->validators)) {
            throw new \RuntimeException('At least one validator must be configured');
        }
        
        // Use the first validator as the proposer
        $validators = $this->validators; ksort($validators, SORT_STRING);
        $firstValidator = reset($validators);
        $proposer = $firstValidator['publicKey'];
        $proposerAddress = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $proposer);
        
        // Compute initial state root (simple hash of initial accounts)
        $stateRoot = $this->computeStateRoot();
        
        // Create genesis block
        return new Block(new \Greenwood\Block\Header(1, $this->chainId, 0, 0, str_repeat("\0",32), str_repeat("\0",32), ProtocolHash::hash('GREENWOOD_TX_EMPTY_V1',''), $stateRoot, $proposerAddress, $this->getGenesisHash(), 0));
    }
    
    private function computeGenesisHash(): string
    {
        $canonicalBytes = $this->getCanonicalBytes();
        return ProtocolHash::hash('GREENWOOD_GENESIS_V1', $canonicalBytes);
    }
    
    public function initialState(): \Greenwood\State\State
    {
        $state = new \Greenwood\State\State();
        foreach ($this->initialAccounts as $account) $state->setAccountBalance($account['address'], $account['balance']);
        // Explicit initial provider pools; later validator mutations do not alter eligibility.
        $keys = array_column($this->validators, 'publicKey');
        $state->setProviderEligibility(new \Greenwood\Compute\Verification\GenesisProviderEligibility($keys, $keys));
        return $state->setValidators($this->validators)->recomputeStateRoot();
    }
    private function computeStateRoot(): string { return $this->initialState()->getStateRoot(); }
}
