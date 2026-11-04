<?php

declare(strict_types=1);

namespace Greenwood\Crypto;

/**
 * MerkleTree provides a generic binary Merkle tree implementation
 * using BLAKE2b-256 for Greenwood protocol objects.
 * 
 * This implementation handles:
 * - Deterministic tree construction
 * - Odd-node duplication
 * - Empty tree handling
 */
class MerkleTree
{
    private array $leaves = [];
    private array $levels = [];
    private string $leafDomain;
    private string $nodeDomain;
    private string $emptyDomain;
    
    /**
     * Create a new Merkle tree.
     * 
     * @param string $leafDomain Domain for leaf hashing
     * @param string $nodeDomain Domain for internal node hashing
     * @param string $emptyDomain Domain for empty tree hash
     */
    public function __construct(string $leafDomain, string $nodeDomain, string $emptyDomain)
    {
        $this->leafDomain = $leafDomain;
        $this->nodeDomain = $nodeDomain;
        $this->emptyDomain = $emptyDomain;
    }
    
    /**
     * Add a leaf to the tree.
     * 
     * @param string $leafData The leaf data (already hashed or raw)
     * @return self
     */
    public function addLeaf(string $leafData): self
    {
        $this->leaves[] = $leafData;
        $this->levels = []; // Reset cached levels
        return $this;
    }
    
    /**
     * Add multiple leaves from an array of hashes.
     * 
     * @param array $leaves Array of leaf hashes
     * @return self
     */
    public function addLeaves(array $leaves): self
    {
        foreach ($leaves as $leaf) {
            $this->leaves[] = $leaf;
        }
        $this->levels = []; // Reset cached levels
        return $this;
    }
    
    /**
     * Compute the root hash of the tree.
     * 
     * @return string 32-byte BLAKE2b-256 root hash
     */
    public function getRoot(): string
    {
        if (empty($this->leaves)) {
            // Empty tree
            return ProtocolHash::hash($this->emptyDomain, '');
        }
        
        // Build levels bottom-up
        $currentLevel = $this->buildLeafLevel();
        $this->levels[0] = $currentLevel;
        
        while (count($currentLevel) > 1) {
            $currentLevel = $this->buildParentLevel($currentLevel);
            $this->levels[] = $currentLevel;
        }
        
        return $currentLevel[0];
    }
    
    /**
     * Get the leaf hashes.
     * 
     * @return array Array of 32-byte leaf hashes
     */
    public function getLeaves(): array
    {
        return $this->leaves;
    }
    
    /**
     * Get the number of leaves.
     * 
     * @return int
     */
    public function count(): int
    {
        return count($this->leaves);
    }
    
    /**
     * Build the initial leaf level.
     * 
     * @return array Array of leaf hashes
     */
    private function buildLeafLevel(): array
    {
        $level = [];
        foreach ($this->leaves as $leaf) {
            $level[] = ProtocolHash::hash($this->leafDomain, $leaf);
        }
        return $level;
    }
    
    /**
     * Build a parent level from the current level.
     * 
     * @param array $currentLevel Current level hashes
     * @return array Parent level hashes
     */
    private function buildParentLevel(array $currentLevel): array
    {
        $parentLevel = [];
        $count = count($currentLevel);
        
        // If odd number, duplicate the last element
        if ($count % 2 === 1) {
            $currentLevel[] = $currentLevel[$count - 1];
        }
        
        // Hash pairs
        for ($i = 0; $i < count($currentLevel); $i += 2) {
            $left = $currentLevel[$i];
            $right = $currentLevel[$i + 1];
            $parentLevel[] = ProtocolHash::hash($this->nodeDomain, $left . $right);
        }
        
        return $parentLevel;
    }
}
