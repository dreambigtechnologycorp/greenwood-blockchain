<?php

declare(strict_types=1);

namespace Greenwood\Block;

use Greenwood\Crypto\ProtocolHash;

/**
 * BlockHeader represents the header portion of a block.
 */
class Header
{
    public const VERSION = 1;
    
    private int $version;
    private string $chainId;
    private int $height;
    private int $round;
    private string $parentHash;
    private string $previousStateRoot;
    private string $transactionsRoot;
    private string $stateRoot;
    private string $proposer;
    private string $genesisHash;
    private int $timestamp;
    
    /**
     * Create a new block header.
     * 
     * @param int $version
     * @param string $chainId
     * @param int $height
     * @param int $round
     * @param string $parentHash 32-byte hash
     * @param string $previousStateRoot 32-byte hash
     * @param string $transactionsRoot 32-byte Merkle root
     * @param string $stateRoot 32-byte state root
     * @param string $proposer 32-byte validator address
     * @param int $timestamp Unix timestamp
     */
    public function __construct(
        int $version,
        string $chainId,
        int $height,
        int $round,
        string $parentHash,
        string $previousStateRoot,
        string $transactionsRoot,
        string $stateRoot,
        string $proposer,
        string $genesisHash,
        int $timestamp
    ) {
        if ($version !== 1 || $chainId === '' || strlen($chainId) > 128) throw new \InvalidArgumentException('Invalid block header');
        foreach ([$parentHash, $previousStateRoot, $transactionsRoot, $stateRoot, $proposer, $genesisHash] as $hash) if (strlen($hash) !== 32) throw new \InvalidArgumentException('Invalid hash length');
        foreach ([$height, $round, $timestamp] as $n) \Greenwood\Codec\Canonical::encodeU32BE($n);
        $this->version = $version;
        $this->chainId = $chainId;
        $this->height = $height;
        $this->round = $round;
        $this->parentHash = $parentHash;
        $this->previousStateRoot = $previousStateRoot;
        $this->transactionsRoot = $transactionsRoot;
        $this->stateRoot = $stateRoot;
        $this->proposer = $proposer;
        $this->genesisHash = $genesisHash;
        $this->timestamp = $timestamp;
    }
    
    /**
     * Get the version.
     * 
     * @return int
     */
    public function getVersion(): int
    {
        return $this->version;
    }
    
    /**
     * Get the chain ID.
     * 
     * @return string
     */
    public function getChainId(): string
    {
        return $this->chainId;
    }
    
    /**
     * Get the block height.
     * 
     * @return int
     */
    public function getHeight(): int
    {
        return $this->height;
    }
    
    /**
     * Get the round.
     * 
     * @return int
     */
    public function getRound(): int
    {
        return $this->round;
    }
    
    /**
     * Get the parent block hash.
     * 
     * @return string 32-byte hash
     */
    public function getParentHash(): string
    {
        return $this->parentHash;
    }
    
    /**
     * Get the previous state root.
     * 
     * @return string 32-byte hash
     */
    public function getPreviousStateRoot(): string
    {
        return $this->previousStateRoot;
    }
    
    /**
     * Get the transactions Merkle root.
     * 
     * @return string 32-byte hash
     */
    public function getTransactionsRoot(): string
    {
        return $this->transactionsRoot;
    }
    
    /**
     * Get the state root.
     * 
     * @return string 32-byte hash
     */
    public function getStateRoot(): string
    {
        return $this->stateRoot;
    }
    
    /**
     * Get the proposer address.
     * 
     * @return string 32-byte address
     */
    public function getProposer(): string
    {
        return $this->proposer;
    }
    
    /**
     * Get the genesis hash.
     *
     * @return string 32-byte hash
     */
    public function getGenesisHash(): string
    {
        return $this->genesisHash;
    }
    
    /**
     * Get the timestamp.
     * 
     * @return int
     */
    public function getTimestamp(): int
    {
        return $this->timestamp;
    }
    
    /**
     * Compute the block hash.
     * 
     * @return string 32-byte hash
     */
    public function getHash(): string
    {
        $headerBytes = $this->toBytes();
        return ProtocolHash::hash('GREENWOOD_BLOCK_V1', $headerBytes);
    }
    
    /**
     * Convert header to bytes.
     * 
     * @return string
     */
    public function toBytes(): string
    {
        $bytes = '';
        $bytes .= pack('C', $this->version);
        $bytes .= pack('n', strlen($this->chainId)) . $this->chainId;
        $bytes .= pack('N', $this->height);
        $bytes .= pack('N', $this->round);
        $bytes .= $this->parentHash;
        $bytes .= $this->previousStateRoot;
        $bytes .= $this->transactionsRoot;
        $bytes .= $this->stateRoot;
        $bytes .= $this->proposer;
        $bytes .= $this->genesisHash;
        $bytes .= pack('N', $this->timestamp);
        return $bytes;
    }
    
    /**
     * Decode bytes to a header.
     * 
     * @param string $bytes
     * @return self
     */
    public static function read(\Greenwood\Codec\Reader $r): self
    {
        $version = $r->u8(); $n = $r->u16();
        if ($n > 128) throw new \InvalidArgumentException('Chain ID too large');
        return new self($version, $r->take($n), $r->u32(), $r->u32(), $r->take(32), $r->take(32), $r->take(32), $r->take(32), $r->take(32), $r->take(32), $r->u32());
    }
    public static function fromBytes(string $bytes): self
    {
        $r = new \Greenwood\Codec\Reader($bytes); $h = self::read($r); $r->end(); return $h;
    }
}
