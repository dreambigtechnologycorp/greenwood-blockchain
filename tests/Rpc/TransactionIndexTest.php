<?php
declare(strict_types=1);
namespace Greenwood\Tests\Rpc;

use Greenwood\Crypto\{Ed25519KeyPair, ProtocolHash};
use Greenwood\Genesis\Genesis;
use Greenwood\Node\Chain;
use Greenwood\Persistence\{BlockFilesystem, StateSqlite};
use Greenwood\Rpc\HttpApi;
use Greenwood\Transaction\Transfer;
use PHPUnit\Framework\TestCase;

final class TransactionIndexTest extends TestCase
{
    private string $dir;
    private Genesis $genesis;
    private Chain $chain;
    private Ed25519KeyPair $key;
    private string $sender;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/gw-index-'.bin2hex(random_bytes(6));
        $this->key = Ed25519KeyPair::generate();
        $this->sender = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $this->key->getPublicKey());
        $this->genesis = new Genesis('index-test');
        $this->genesis->addValidator('A', $this->key, 1)->addInitialAccount('sender', $this->key, 1000);
        $this->chain = $this->reload();
    }

    private function reload(): Chain
    {
        return new Chain($this->genesis, new BlockFilesystem($this->dir.'/blocks'), new StateSqlite($this->dir.'/state.sqlite'));
    }

    private function db(): \PDO
    {
        $p = new \PDO('sqlite:'.$this->dir.'/state.sqlite');
        $p->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $p;
    }

    private function tx(): \Greenwood\Transaction\Envelope
    {
        return (new Transfer(str_repeat('r', 32), 1))->createEnvelope('index-test', $this->sender, $this->chain->state->getAccount($this->sender)->getNonce(), $this->key);
    }

    private function add(array $txs = []): \Greenwood\Block\Block
    {
        $b = $this->chain->build($txs, 0, $this->sender);
        $this->chain->finalize($b);
        return $b;
    }

    private function api(?string $pending = null): HttpApi
    {
        return new HttpApi($this->chain, fn() => throw new \LogicException('No submissions in this test'),
            fn($hash) => $hash === $pending, fn() => $pending === null ? 0 : 1,
            fn() => 'full_node', fn() => true, fn() => 0);
    }

    protected function tearDown(): void
    {
        $this->chain->index->close();
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        rmdir($this->dir);
    }

    public function testLegacyUpgradeBackfillsHeight13AndRestartIsIdempotent(): void
    {
        for ($i=1; $i<13; $i++) $this->add();
        $tx = $this->tx(); $this->add([$tx]); $this->add();
        $root = $this->chain->state->getStateRoot(); $hash = $this->chain->tip->getHash();
        $p = $this->db();
        $p->exec('DROP TABLE transaction_index_progress; DELETE FROM transactions');
        // Simulate the untrusted first-pass table with no coverage metadata.
        $q=$p->prepare('INSERT INTO transactions VALUES (?,14,?,99)'); $q->execute([$tx->getHash(), 'bad']);
        $this->chain->index->close(); $this->chain = $this->reload();
        $row = $this->chain->index->getTransactionByHash($tx->getHash());
        self::assertSame(13, (int)$row['height']); self::assertSame(0, (int)$row['position']);
        self::assertTrue($this->chain->index->transactionIndexComplete(14, $hash));
        self::assertSame($root, $this->chain->state->getStateRoot());
        self::assertSame($hash, $this->chain->tip->getHash());
        $this->chain->index->close(); $this->chain = $this->reload();
        self::assertSame($row, $this->chain->index->getTransactionByHash($tx->getHash()));
        self::assertSame(200, $this->api()->handle('GET','/v1/transactions/'.bin2hex($tx->getHash()),'')->status);
    }

    public function testUnknownAndPendingLookupsNeverReadBlockHistory(): void
    {
        $this->add([$this->tx()]); $this->add();
        $pending = str_repeat('a',64); $unknown = str_repeat('0',64);
        // Even if the entire block directory is unavailable, a complete miss
        // must not touch it. Timing thresholds alone would not prove this.
        rename($this->dir.'/blocks', $this->dir.'/held-blocks');
        try {
            $api=$this->api($pending);
            $r=$api->handle('GET','/v1/transactions/'.$unknown,'');
            self::assertSame(404,$r->status); self::assertSame('TRANSACTION_NOT_FOUND',json_decode($r->body,true)['error']['code']);
            foreach (['','/status'] as $suffix) {
                $r=$api->handle('GET','/v1/transactions/'.$pending.$suffix,'');
                self::assertSame(200,$r->status); self::assertSame('PENDING',json_decode($r->body,true)['status']);
            }
            $r=$api->handle('GET','/v1/transactions/'.$unknown.'/status','');
            self::assertSame(200,$r->status); self::assertSame('UNKNOWN',json_decode($r->body,true)['status']);
            self::assertSame(400,$api->handle('GET','/v1/transactions/not-hex','')->status);
        } finally { rename($this->dir.'/held-blocks',$this->dir.'/blocks'); }
    }

    public function testInterruptedMigrationResumesAndEmptyBlocksAdvanceCoverage(): void
    {
        $this->add(); $tx=$this->tx(); $this->add([$tx]); $this->add(); $this->add();
        $p=$this->db(); $p->exec('DELETE FROM transaction_index_progress; DELETE FROM transactions');
        $p->exec("CREATE TRIGGER interrupt_backfill BEFORE UPDATE ON transaction_index_progress WHEN NEW.height=3 BEGIN SELECT RAISE(ABORT,'interrupt'); END");
        try { $this->chain->index->synchronizeTransactionIndex($this->chain->files,$this->chain->tip); self::fail(); }
        catch (\PDOException) { self::assertSame(2,(int)$this->chain->index->transactionIndexProgress()['height']); }
        self::assertNotNull($this->chain->index->getTransactionByHash($tx->getHash()));
        $p->exec('DROP TRIGGER interrupt_backfill');
        // Block1 is empty and already covered: resume must not revisit it.
        $file=$this->dir.'/blocks/0000000000000001-block.bin'; rename($file,$file.'.held');
        try { $this->chain->index->synchronizeTransactionIndex($this->chain->files,$this->chain->tip); }
        finally { rename($file.'.held',$file); }
        self::assertTrue($this->chain->index->transactionIndexComplete(4,$this->chain->tip->getHash()));
        self::assertSame(1,(int)$this->chain->index->transactionIndexProgress()['transaction_count']);
    }

    public function testFailedFinalizationIndexTransactionRollsBackAndRestartCatchesUp(): void
    {
        $tx=$this->tx(); $block=$this->chain->build([$tx],0,$this->sender);
        $p=$this->db(); $p->exec("CREATE TRIGGER interrupt_watermark BEFORE UPDATE ON transaction_index_progress BEGIN SELECT RAISE(ABORT,'interrupt'); END");
        try { $this->chain->finalize($block); self::fail(); }
        catch (\PDOException) {
            self::assertSame(0,$this->chain->index->getChainHeight());
            self::assertSame(0,(int)$this->chain->index->transactionIndexProgress()['height']);
            self::assertNull($this->chain->index->getTransactionByHash($tx->getHash()));
            self::assertTrue($this->chain->files->hasBlock(1));
        }
        $p->exec('DROP TRIGGER interrupt_watermark');
        $this->chain->index->close(); $this->chain=$this->reload();
        self::assertSame($block->getHash(),$this->chain->tip->getHash());
        self::assertSame(1,$this->chain->index->getChainHeight());
        self::assertTrue($this->chain->index->transactionIndexComplete(1,$block->getHash()));
        self::assertNotNull($this->chain->index->getTransactionByHash($tx->getHash()));
    }

    public function testIncompleteIndexFailsClosedForReadinessAndTransactionReads(): void
    {
        $tx=$this->tx(); $this->add([$tx]);
        $p=$this->db(); $p->exec('UPDATE transaction_index_progress SET height=0');
        foreach (['/v1/ready','/v1/transactions/'.str_repeat('0',64),'/v1/transactions/'.str_repeat('0',64).'/status','/v1/transactions/'.bin2hex($tx->getHash())] as $path) {
            $r=$this->api()->handle('GET',$path,'');
            self::assertSame(503,$r->status); self::assertSame('NOT_READY',json_decode($r->body,true)['error']['code']);
        }
    }

    public function testCorruptLocatorFailsClosedThenReconstructsOnRestart(): void
    {
        $tx=$this->tx(); $this->add([$tx]);
        foreach (["position=99", "block_hash='wrong'", 'height=90'] as $damage) {
            $p=$this->db(); $p->exec('UPDATE transactions SET '.$damage);
            self::assertSame(500,$this->api()->handle('GET','/v1/transactions/'.bin2hex($tx->getHash()),'')->status);
            $this->chain->index->close(); $this->chain=$this->reload();
            self::assertSame(200,$this->api()->handle('GET','/v1/transactions/'.bin2hex($tx->getHash()),'')->status);
        }
        $p->exec('DELETE FROM transactions');
        $this->chain->index->close(); $this->chain=$this->reload();
        self::assertNotNull($this->chain->index->getTransactionByHash($tx->getHash()));
    }

    public function testPositionOneAndDamagedCoverageAreReconstructed(): void
    {
        $first=$this->tx();
        $second=(new Transfer(str_repeat('s',32),2))->createEnvelope('index-test',$this->sender,1,$this->key);
        $this->add([$first,$second]); $this->add();
        self::assertSame(1,(int)$this->chain->index->getTransactionByHash($second->getHash())['position']);
        $r=$this->api()->handle('GET','/v1/transactions/'.bin2hex($second->getHash()),'');
        self::assertSame(200,$r->status); self::assertSame(1,json_decode($r->body,true)['nonce']);
        foreach (["genesis_hash='wrong'", "block_hash='wrong'", 'height=99', 'transaction_count=99'] as $damage) {
            $this->db()->exec('UPDATE transaction_index_progress SET '.$damage);
            $this->chain->index->close(); $this->chain=$this->reload();
            self::assertTrue($this->chain->index->transactionIndexComplete(2,$this->chain->tip->getHash()));
            self::assertSame(1,(int)$this->chain->index->getTransactionByHash($second->getHash())['position']);
        }
    }

    public function testLostOrCorruptDatabaseRebuildPreservesCanonicalTruth(): void
    {
        $tx=$this->tx(); $this->add([$tx]); $this->add();
        $hash=$this->chain->tip->getHash(); $root=$this->chain->state->getStateRoot();
        foreach ([null, 'not a sqlite database'] as $damage) {
            $this->chain->index->close(); unlink($this->dir.'/state.sqlite');
            if ($damage!==null) file_put_contents($this->dir.'/state.sqlite',$damage);
            $this->chain=$this->reload();
            self::assertSame($hash,$this->chain->tip->getHash()); self::assertSame($root,$this->chain->state->getStateRoot());
            self::assertTrue($this->chain->index->transactionIndexComplete(2,$hash));
            self::assertNotNull($this->chain->index->getTransactionByHash($tx->getHash()));
        }
    }
}
