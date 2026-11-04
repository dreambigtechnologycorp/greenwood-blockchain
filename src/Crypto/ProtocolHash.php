<?php

declare(strict_types=1);

namespace Greenwood\Crypto;

/**
 * ProtocolHash provides domain-separated BLAKE2b-256 hashing for Greenwood.
 * 
 * All Greenwood protocol objects use this single hash primitive to ensure
 * determinism across all implementations (PHP, Rust, Go, etc.).
 * 
 * Hash format: BLAKE2b-256(domain_length || domain || payload)
 */
class ProtocolHash
{
    /**
     * Domain-separated BLAKE2b-256 hash.
     * 
     * @param string $domain The domain separator (ASCII)
     * @param string $payload The data to hash
     * @return string 32-byte BLAKE2b-256 hash
     */
    public static function hash(string $domain, string $payload): string
    {
        // Format: u16_be(strlen(domain)) || UTF8(domain) || payload
        $domainLen = strlen($domain);
        $domainBytes = pack('n', $domainLen) . $domain;
        
        return sodium_crypto_generichash($domainBytes . $payload, '', 32);
    }
    
    /**
     * Hash a single value without domain separation.
     * Only use this for internal intermediate computations.
     * 
     * @param string $data The data to hash
     * @return string 32-byte BLAKE2b-256 hash
     */
    public static function hashValue(string $data): string
    {
        return sodium_crypto_generichash($data, '', 32);
    }
    
    /**
     * Compute the Merkle root of a list of hashes.
     * 
     * @param array $hashes Array of 32-byte hashes
     * @return string 32-byte Merkle root
     */
    public static function merkleRoot(array $hashes): string
    {
        if (empty($hashes)) {
            return self::hash('GREENWOOD_TX_EMPTY_V1', '');
        }
        
        $tree = new MerkleTree(
            'GREENWOOD_TX_LEAF_V1',
            'GREENWOOD_TX_NODE_V1',
            'GREENWOOD_TX_EMPTY_V1'
        );
        
        foreach ($hashes as $hash) {
            $tree->addLeaf($hash);
        }
        
        return $tree->getRoot();
    }
}
