<?php

declare(strict_types=1);

namespace Greenwood\Crypto;

/**
 * Ed25519KeyPair provides Ed25519 key pair generation and management.
 */
class Ed25519KeyPair
{
    private string $publicKey;
    private string $privateKey;
    
    /**
     * Generate a new Ed25519 key pair.
     * 
     * @return self
     */
    public static function generate(): self
    {
        $keypair = sodium_crypto_sign_keypair();
        return new self(
            sodium_crypto_sign_publickey($keypair),
            sodium_crypto_sign_secretkey($keypair)
        );
    }
    
    /**
     * Create a key pair from existing keys.
     * 
     * @param string $publicKey 32-byte public key
     * @param string $privateKey 64-byte private key
     */
    public function __construct(string $publicKey, string $privateKey)
    {
        $this->publicKey = $publicKey;
        $this->privateKey = $privateKey;
    }
    
    /**
     * Get the public key.
     * 
     * @return string 32-byte public key
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }
    
    /**
     * Get the private key.
     * 
     * @return string 64-byte private key
     */
    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }
    
    /**
     * Sign a message.
     * 
     * @param string $message
     * @return string 64-byte signature
     */
    public function sign(string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->privateKey);
    }
    
    /**
     * Verify a signature.
     * 
     * @param string $message
     * @param string $signature
     * @param string $publicKey
     * @return bool
     */
    public static function verify(string $message, string $signature, string $publicKey): bool
    {
        return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }
    
    /**
     * Serialize the key pair to bytes.
     * 
     * @return string
     */
    public function toBytes(): string
    {
        return $this->publicKey . $this->privateKey;
    }
    
    /**
     * Create a key pair from bytes.
     * 
     * @param string $bytes
     * @return self
     */
    public static function fromBytes(string $bytes): self
    {
        $publicKey = substr($bytes, 0, 32);
        $privateKey = substr($bytes, 32);
        return new self($publicKey, $privateKey);
    }
}
