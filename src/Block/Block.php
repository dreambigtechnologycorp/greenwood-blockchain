<?php

declare(strict_types=1);

namespace Greenwood\Block;

use Greenwood\Transaction\Envelope;
use Greenwood\Crypto\ProtocolHash;
use Greenwood\Crypto\MerkleTree;

/**
 * Block represents a complete block with header and body.
 */
class Block
{
    private Header $header;
    private array $transactions;
    private ?string $hash = null;
    
    /**
     * Create a new block.
     * 
     * @param Header $header
     * @param Envelope[] $transactions
     */
    public function __construct(Header $header, array $transactions = [])
    {
        $this->header = $header;
        $this->transactions = $transactions;
    }
    
    /**
     * Get the header.
     * 
     * @return Header
     */
    public function getHeader(): Header
    {
        return $this->header;
    }
    
    /**
     * Get the transactions.
     * 
     * @return Envelope[]
     */
    public function getTransactions(): array
    {
        return $this->transactions;
    }

    /**
     * Get the state root from the header.
     *
     * @return string 32-byte hash
     */
    public function getStateRoot(): string
    {
        return $this->header->getStateRoot();
    }

    /**
     * Get the genesis hash from the header.
     *
     * @return string 32-byte hash
     */
    public function getGenesisHash(): string
    {
        return $this->header->getGenesisHash();
    }
    
    /**
     * Get the block height.
     *
     * @return int
     */
    public function getHeight(): int
    {
        return $this->header->getHeight();
    }
    
    /**
     * Add a transaction.
     * 
     * @param Envelope $tx
     * @return self
     */
    public function addTransaction(Envelope $tx): self
    {
        $this->transactions[] = $tx;
        $this->hash = null;
        return $this;
    }
    
    /**
     * Get the block hash.
     * 
     * @return string 32-byte hash
     */
    public function getHash(): string
    {
        if ($this->hash === null) {
            $this->hash = $this->header->getHash();
        }
        return $this->hash;
    }
    
    /**
     * Compute the transactions Merkle root.
     * 
     * @return string 32-byte hash
     */
    public function computeTransactionsRoot(): string
    {
        if (empty($this->transactions)) {
            return ProtocolHash::hash('GREENWOOD_TX_EMPTY_V1', '');
        }
        
        $tree = new MerkleTree(
            'GREENWOOD_TX_LEAF_V1',
            'GREENWOOD_TX_NODE_V1',
            'GREENWOOD_TX_EMPTY_V1'
        );
        
        foreach ($this->transactions as $tx) {
            $tree->addLeaf($tx->getHash());
        }
        
        return $tree->getRoot();
    }
    
    /**
     * Create a new block from a header and transactions.
     * 
     * @param Header $header
     * @param Envelope[] $transactions
     * @return self
     */
    public static function create(Header $header, array $transactions = []): self
    {
        return new self($header, $transactions);
    }
    
    /**
     * Create a genesis block.
     * 
     * @param string $chainId
     * @param string $stateRoot Initial state root
     * @param string $proposer Validator address
     * @return self
     */
    public static function createGenesis(string $chainId, string $stateRoot, string $proposer): self
    {
        $header = new Header(
            Header::VERSION,
            $chainId,
            0, // height
            0, // round
            str_repeat("\x00", 32), // parent hash (null for genesis)
            str_repeat("\x00", 32), // previous state root
            ProtocolHash::hash('GREENWOOD_TX_EMPTY_V1', ''), // transactions root
            $stateRoot,
            $proposer,
            str_repeat("\x00", 32),  // genesisHash
            0                // deterministic genesis timestamp
        );
        
        return new self($header, []);
    }
    
    /**
     * Serialize the block to bytes.
     * 
     * @return string
     */
    public function toBytes(): string
    {
        $bytes = '';
        $bytes .= $this->header->toBytes();
        $bytes .= pack('N', count($this->transactions));
        foreach ($this->transactions as $tx) {
            $bytes .= $tx->getCanonicalBytes();
        }
        return $bytes;
    }
    
    /**
     * Decode bytes to a block.
     * 
     * @param string $bytes
     * @return self
     */
    public static function fromBytes(string $bytes): self
    {
        $r = new \Greenwood\Codec\Reader($bytes);
        $header = Header::read($r); $count = $r->u32();
        if ($count > 1000) throw new \InvalidArgumentException('Too many transactions');
        $transactions = [];
        for ($i = 0; $i < $count; $i++) $transactions[] = Envelope::read($r);
        $r->end();
        return new self($header, $transactions);
    }

}
