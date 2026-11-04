<?php

declare(strict_types=1);

namespace Greenwood\State;

/**
 * Account state representation.
 */
class Account
{
    private string $address;
    private int $balance;
    private int $nonce;
    
    /**
     * Create a new account state.
     * 
     * @param string $address 32-byte address
     * @param int $balance Initial balance
     * @param int $nonce Initial nonce
     */
    public function __construct(string $address, int $balance = 0, int $nonce = 0)
    {
        if (strlen($address) !== 32) throw new \InvalidArgumentException('Invalid address');
        $this->address = $address;
        if ($balance < 0) throw new \InvalidArgumentException('Negative balance');
        $this->balance = $balance;
        \Greenwood\Codec\Canonical::encodeU32BE($nonce);
        $this->nonce = $nonce;
    }
    
    /**
     * Get the address.
     * 
     * @return string 32-byte address
     */
    public function getAddress(): string
    {
        return $this->address;
    }
    
    /**
     * Get the balance.
     * 
     * @return int
     */
    public function getBalance(): int
    {
        return $this->balance;
    }
    
    /**
     * Set the balance.
     * 
     * @param int $balance
     * @return self
     */
    public function setBalance(int $balance): self
    {
        if ($balance < 0) throw new \InvalidArgumentException('Negative balance');
        $this->balance = $balance;
        return $this;
    }
    
    /**
     * Get the nonce.
     * 
     * @return int
     */
    public function getNonce(): int
    {
        return $this->nonce;
    }
    
    /**
     * Set the nonce.
     * 
     * @param int $nonce
     * @return self
     */
    public function setNonce(int $nonce): self
    {
        \Greenwood\Codec\Canonical::encodeU32BE($nonce);
        $this->nonce = $nonce;
        return $this;
    }
    
    /**
     * Increment the nonce.
     * 
     * @return self
     */
    public function incrementNonce(): self
    {
        $this->setNonce($this->nonce + 1);
        return $this;
    }
    
    /**
     * Deduct from balance.
     * 
     * @param int $amount
     * @return self
     */
    public function deduct(int $amount): self
    {
        $this->balance -= $amount;
        return $this;
    }
    
    /**
     * Add to balance.
     * 
     * @param int $amount
     * @return self
     */
    public function add(int $amount): self
    {
        $this->balance += $amount;
        return $this;
    }
    
    /**
     * Check if this account has sufficient balance.
     * 
     * @param int $amount
     * @return bool
     */
    public function hasBalance(int $amount): bool
    {
        return $this->balance >= $amount;
    }
    
    /**
     * Convert to associative array for serialization.
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'address' => bin2hex($this->address),
            'balance' => $this->balance,
            'nonce' => $this->nonce,
        ];
    }
    
    /**
     * Create from associative array.
     * 
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            hex2bin($data['address']),
            $data['balance'] ?? 0,
            $data['nonce'] ?? 0
        );
    }
}
