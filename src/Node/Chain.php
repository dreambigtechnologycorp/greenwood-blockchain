<?php
declare(strict_types=1);
namespace Greenwood\Node;
use Greenwood\Block\{Block,Header};
use Greenwood\Genesis\Genesis;
use Greenwood\State\State;
use Greenwood\Persistence\{BlockFilesystem,StateSqlite};
use Greenwood\Crypto\{ProtocolHash,MerkleTree};

/** Shared independent execution path for proposal validation, finality, and replay. */
final class Chain
{
    public State $state;
    public Block $tip;
    public function __construct(public readonly Genesis $genesis, public readonly BlockFilesystem $files, public readonly StateSqlite $index, private readonly ?\Closure $verifyCertificate = null)
    {
        $this->state = $genesis->initialState(); $this->tip = $genesis->createGenesisBlock();
        // Startup holds the node's exclusive data-directory lock. Validate the
        // history once, rather than rescanning every filename for every block.
        $replayHeight = $files->getHighestHeight();
        if ($replayHeight === -1) $files->saveBlock($this->tip);
        if ($files->loadBlock(0)->toBytes() !== $this->tip->toBytes()) throw new \RuntimeException('Trusted genesis mismatch');
        for ($h = 1; $h <= $replayHeight; $h++) {
            $block = $files->loadBlock($h);
            if ($verifyCertificate !== null) $verifyCertificate($block, $files->loadCertificate($h));
            $this->state = $this->execute($block); $this->tip = $block;
        }
        // Files are authoritative: always reconstruct; a stale or absent index cannot invent state.
        $index->rebuild($this->state,$this->tip,$genesis->getValidators());
        $index->synchronizeTransactionIndex($files, $this->tip);
    }
    public function build(array $transactions, int $round, string $proposer): Block
    {
        if (count($transactions)>1000) throw new \InvalidArgumentException('Too many transactions');
        $state = clone $this->state;
        $tree = new MerkleTree('GREENWOOD_TX_LEAF_V1','GREENWOOD_TX_NODE_V1','GREENWOOD_TX_EMPTY_V1');
        $candidateHeight = $this->tip->getHeight() + 1;
        foreach ($transactions as $tx) { $state->applyTransaction($tx,$this->genesis->getChainId(),$candidateHeight); $tree->addLeaf($tx->getHash()); }
        $block = new Block(new Header(1,$this->genesis->getChainId(),$this->tip->getHeight()+1,$round,$this->tip->getHash(),$this->state->getStateRoot(),$tree->getRoot(),$state->getStateRoot(),$proposer,$this->genesis->getGenesisHash(),0),$transactions);
        if (strlen($block->toBytes())>1048576) throw new \InvalidArgumentException('Block too large');
        return $block;
    }
    public function execute(Block $block): State
    {
        $h = $block->getHeader();
        if ($h->getChainId() !== $this->genesis->getChainId() || $h->getGenesisHash() !== $this->genesis->getGenesisHash()
            || $h->getHeight() !== $this->tip->getHeight()+1 || $h->getParentHash() !== $this->tip->getHash()
            || $h->getPreviousStateRoot() !== $this->state->getStateRoot() || $h->getTransactionsRoot() !== $block->computeTransactionsRoot()
            || $h->getTimestamp() !== 0 || count($block->getTransactions())>1000 || strlen($block->toBytes())>1048576) throw new \InvalidArgumentException('Invalid block linkage or commitment');
        $validators = $this->genesis->getValidators(); ksort($validators,SORT_STRING);
        $v = array_values($validators)[($h->getHeight()+$h->getRound()) % count($validators)];
        if ($h->getProposer() !== ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$v['publicKey'])) throw new \InvalidArgumentException('Wrong block proposer');
        $state = clone $this->state;
        foreach ($block->getTransactions() as $tx) $state->applyTransaction($tx,$this->genesis->getChainId(),$h->getHeight());
        if ($state->getStateRoot() !== $h->getStateRoot()) throw new \InvalidArgumentException('Invalid state root');
        return $state;
    }
    public function finalize(Block $block, ?string $certificate = null): void
    {
        if ($this->verifyCertificate !== null) ($this->verifyCertificate)($block,$certificate);
        $state = $this->execute($block);
        // Certificate first; block rename is the history commit point. Orphan certificates are harmless.
        if ($certificate !== null) $this->files->persistCertificate($block->getHeight(),$block->getHash(),$certificate);
        $this->files->saveBlock($block);
        $this->index->commitState($state,$block,$this->genesis->getValidators());
        $this->state = $state; $this->tip = $block;
    }

    /**
     * Assignment history for one request, reconstructed from finalized block
     * history (Pass 4E section 38). Consensus state stores only the current
     * assignment; prior generations remain replayable from history and are
     * never overwritten. Returns entries in generation order:
     * [['generation' => int, 'provider_id' => hex, 'height' => int], ...]
     */
    public function assignmentHistory(string $requestId): array
    {
        if (strlen($requestId) !== 32) throw new \InvalidArgumentException('Invalid request ID');
        $history = [];
        for ($height = 1; $height <= $this->tip->getHeight(); $height++) {
            $block = $this->files->getBlockByHeight($height);
            if ($block === null) throw new \RuntimeException('Missing finalized block at height '.$height);
            foreach ($block->getTransactions() as $tx) {
                if ($tx->getType() !== \Greenwood\Transaction\Envelope::TYPE_COMPUTE_ASSIGN) continue;
                $assignment = \Greenwood\Compute\Protocol\ComputeAssign::decode($tx->getPayload());
                if ($assignment->requestId !== $requestId) continue;
                $history[] = ['generation' => $assignment->generation,
                    'provider_id' => bin2hex($assignment->providerId), 'height' => $height];
            }
        }
        usort($history, fn ($a, $b) => $a['generation'] <=> $b['generation']);
        return $history;
    }
}
