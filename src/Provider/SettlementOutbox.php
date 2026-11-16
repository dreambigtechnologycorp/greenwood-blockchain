<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Economics\{CanonicalJson,Policy};
use Greenwood\Transaction\Envelope;
use Greenwood\Node\Chain;

/** Durable transport of already signed native actions. No keys, nonce allocation, or payment authority. */
final class SettlementOutbox
{
    private \PDO $db;
    private readonly JournalArchive $archive;
    public function __construct(string $directory,private readonly string $chain,private readonly string $genesis)
    {
        Policy::hex($genesis);if($chain===''||strlen($chain)>128)throw new \InvalidArgumentException('Pinned outbox chain required');
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new \RuntimeException('Outbox directory unavailable');
        if(realpath($directory)!==$directory||is_link($directory)||fileowner($directory)!==posix_geteuid()||(fileperms($directory)&0077)!==0)throw new \RuntimeException('Private outbox directory required');
        $path=$directory.'/settlement-outbox.sqlite';
        foreach(['','-wal','-shm'] as $suffix)if(is_link($path.$suffix)||(file_exists($path.$suffix)&&(!is_file($path.$suffix)||fileowner($path.$suffix)!==posix_geteuid()||(fileperms($path.$suffix)&0077)!==0)))throw new \RuntimeException('Unsafe outbox file');
        $this->archive=new JournalArchive($directory.'/archive');
        $this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);chmod($path,0600);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=1000');
        if($this->db->query('PRAGMA quick_check')->fetchColumn()!=='ok')throw new \RuntimeException('Corrupt outbox requires operator recovery');
        $this->db->exec('CREATE TABLE IF NOT EXISTS archived_outbox(id TEXT PRIMARY KEY,sender TEXT NOT NULL,nonce INTEGER NOT NULL,digest TEXT NOT NULL,UNIQUE(sender,nonce))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS identity(chain TEXT NOT NULL,genesis TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS outbox(id TEXT PRIMARY KEY,sender TEXT NOT NULL,nonce INTEGER NOT NULL,bytes TEXT NOT NULL,state TEXT NOT NULL,attempts INTEGER NOT NULL,next_attempt INTEGER NOT NULL,cursor INTEGER NOT NULL,UNIQUE(sender,nonce))');
        $this->db->exec('BEGIN IMMEDIATE');
        try{$identity=$this->db->query('SELECT * FROM identity')->fetchAll(\PDO::FETCH_ASSOC);
            if(!$identity){$q=$this->db->prepare('INSERT INTO identity VALUES(?,?)');$q->execute([$chain,$genesis]);}
            elseif(count($identity)!==1||$identity[0]!==['chain'=>$chain,'genesis'=>$genesis])throw new \InvalidArgumentException('Outbox chain/genesis changed');
            $this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function enqueue(Envelope $tx): string
    {
        $tx->verify($this->chain);
        if(!in_array($tx->getType(),[13,14],true)||(CanonicalJson::decode($tx->getPayload())['genesis']??null)!==$this->genesis)throw new \InvalidArgumentException('Only genesis-pinned native financial/resource actions supported');
        $id=bin2hex($tx->getHash());$bytes=bin2hex($tx->getCanonicalBytes());$this->db->exec('BEGIN IMMEDIATE');
        try{if($old=$this->archived($id)){if($old['bytes']!==$bytes)throw new \RuntimeException('Archived outbox hash collision');$this->db->exec('COMMIT');return $id;}
            $guard=$this->db->prepare('SELECT id FROM archived_outbox WHERE sender=? AND nonce=?');$guard->execute([bin2hex($tx->getSender()),$tx->getNonce()]);if($guard->fetchColumn()!==false)throw new \InvalidArgumentException('Archived sender nonce already consumed');
            $q=$this->db->prepare('SELECT bytes FROM outbox WHERE id=?');$q->execute([$id]);$existing=$q->fetchColumn();
            if($existing!==false){if($existing!==$bytes)throw new \RuntimeException('Outbox hash collision');}
            else{if((int)$this->db->query('SELECT COUNT(*) FROM outbox')->fetchColumn()>=10000)throw new \RuntimeException('Outbox retention bound reached');
                $q=$this->db->prepare("INSERT INTO outbox VALUES(?,?,?,?,'PENDING',0,0,0)");$q->execute([$id,bin2hex($tx->getSender()),$tx->getNonce(),$bytes]);}
            $this->db->exec('COMMIT');return $id;
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function status(string $id): array
    {
        Policy::hex($id);if($old=$this->archived($id))return array_intersect_key($old,array_flip(['id','sender','nonce','state','attempts','next_attempt','cursor']));$q=$this->db->prepare('SELECT id,sender,nonce,state,attempts,next_attempt,cursor FROM outbox WHERE id=?');$q->execute([$id]);$row=$q->fetch(\PDO::FETCH_ASSOC);if(!$row)throw new \InvalidArgumentException('Unknown outbox item');return $row;
    }
    public function due(int $now,int $limit=16): array
    {
        if($now<0||$limit<1||$limit>64)throw new \InvalidArgumentException('Bounded outbox page required');
        $q=$this->db->prepare("SELECT id FROM outbox WHERE state IN ('PENDING','UNCERTAIN') AND attempts<64 AND next_attempt<=? ORDER BY id LIMIT ?");$q->bindValue(1,$now,\PDO::PARAM_INT);$q->bindValue(2,$limit,\PDO::PARAM_INT);$q->execute();return $q->fetchAll(\PDO::FETCH_COLUMN);
    }
    /** Persist ambiguity BEFORE calling transport. A crash can only retry the identical signed bytes. */
    public function submit(string $id,int $now,\Closure $transport): void
    {
        if($now<0||$now>PHP_INT_MAX-3600)throw new \InvalidArgumentException('Invalid retry clock');
        Policy::hex($id);$this->db->exec('BEGIN IMMEDIATE');
        try{$q=$this->db->prepare('SELECT * FROM outbox WHERE id=?');$q->execute([$id]);$r=$q->fetch(\PDO::FETCH_ASSOC);
            if(!$r||!in_array($r['state'],['PENDING','UNCERTAIN'],true)||(int)$r['next_attempt']>$now||(int)$r['attempts']>=64)throw new \InvalidArgumentException('Outbox not eligible for retry');
            $bytes=hex2bin($r['bytes']);$tx=Envelope::decode($bytes);$tx->verify($this->chain);if(bin2hex($tx->getHash())!==$id)throw new \RuntimeException('Outbox bytes corrupted');
            $attempts=(int)$r['attempts']+1;$delay=min(3600,1<<min(12,$attempts));
            $q=$this->db->prepare("UPDATE outbox SET state='UNCERTAIN',attempts=?,next_attempt=? WHERE id=?");$q->execute([$attempts,$now+$delay,$id]);$this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
        $transport($bytes); // A positive RPC acknowledgement is deliberately NOT finality.
    }
    /** Reconcile only against a locally replay-verified chain, in bounded pages. */
    public function reconcile(string $id,Chain $chain,int $limit=256): array
    {
        if($chain->genesis->getChainId()!==$this->chain||bin2hex($chain->genesis->getGenesisHash())!==$this->genesis||$limit<1||$limit>1000)throw new \InvalidArgumentException('Wrong finalized chain or reconciliation bound');
        $r=$this->status($id);if($r['state']==='FINALIZED')return $r;
        $cursor=(int)$r['cursor'];$end=min($chain->tip->getHeight(),$cursor+$limit);$found=false;
        for($h=$cursor+1;$h<=$end;$h++){$block=$chain->files->loadBlock($h);if(!$block)throw new \RuntimeException('Missing local finalized history');foreach($block->getTransactions() as $tx)if(bin2hex($tx->getHash())===$id)$found=true;}
        $state=$found?'FINALIZED':$r['state'];
        if(!$found&&$end===$chain->tip->getHeight()&&$chain->state->getAccount(hex2bin($r['sender']))->getNonce()>(int)$r['nonce'])$state='NONCE_CONFLICT';
        // A consumed nonce is ambiguous/conflicting; NEVER sign a replacement automatically.
        $q=$this->db->prepare('UPDATE outbox SET state=?,cursor=? WHERE id=? AND cursor=?');$q->execute([$state,$end,$id,$cursor]);return $this->status($id);
    }
    private function archived(string $id): ?array
    {
        Policy::hex($id);$q=$this->db->prepare('SELECT digest FROM archived_outbox WHERE id=?');$q->execute([$id]);$digest=$q->fetchColumn();return $digest===false?null:$this->archive->get($digest)['record'];
    }
    public function archiveTerminal(string $id,string $expectedState): string
    {
        Policy::hex($id);if(!in_array($expectedState,['FINALIZED','NONCE_CONFLICT'],true))throw new \InvalidArgumentException('Only reconciled terminal outbox items may archive');
        $this->db->exec('BEGIN IMMEDIATE');
        try{
            $q=$this->db->prepare('SELECT * FROM outbox WHERE id=?');$q->execute([$id]);$r=$q->fetch(\PDO::FETCH_ASSOC);
            if(!$r)$r=$this->archived($id);
            if(!$r||$r['state']!==$expectedState)throw new \InvalidArgumentException('Terminal outbox state mismatch');
            $digest=$this->archive->put(['version'=>1,'kind'=>'outbox','chain'=>$this->chain,'genesis'=>$this->genesis,'record'=>$r]);
            $q=$this->db->prepare('INSERT OR IGNORE INTO archived_outbox VALUES(?,?,?,?)');$q->execute([$id,$r['sender'],$r['nonce'],$digest]);
            $q=$this->db->prepare('DELETE FROM outbox WHERE id=?');$q->execute([$id]);$this->db->exec('COMMIT');return $digest;
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }

}
