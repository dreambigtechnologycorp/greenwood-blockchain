<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\State;
use Greenwood\DeploymentController\Auth\DeploymentIdentity;
final class SqliteOperationStore implements OperationStore {
    public readonly \PDO $db;
    public function __construct(private readonly string $path) {
        if(is_link($path)||!is_dir(dirname($path))||(fileperms(dirname($path))&0077)!==0)throw new \RuntimeException('Private operation directory required');
        $this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);chmod($path,0600);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS operations_v2(id TEXT PRIMARY KEY, account TEXT NOT NULL, idem TEXT NOT NULL, intent TEXT NOT NULL, state TEXT NOT NULL, evidence TEXT NOT NULL, version INTEGER NOT NULL, UNIQUE(account,idem))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS journal_v2(id TEXT NOT NULL, version INTEGER NOT NULL, state TEXT NOT NULL, evidence TEXT NOT NULL, at INTEGER NOT NULL, PRIMARY KEY(id,version))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS auth_nonce(nonce TEXT PRIMARY KEY, expires INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS authenticated_intents(challenge_id TEXT PRIMARY KEY, account TEXT NOT NULL, intent_hash TEXT NOT NULL, verified_at INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS challenge(id TEXT PRIMARY KEY, body TEXT NOT NULL, expires INTEGER NOT NULL)');
    }
    public function accept(string $account,array $intent):array {
        DeploymentIdentity::validate($intent);
        ksort($intent);$json=json_encode($intent,JSON_THROW_ON_ERROR);$id=bin2hex(random_bytes(16));
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $old=$this->byKey($account,$intent['idempotency_key']);
            if($old!==null){if($old['intent']!==$json)throw new \RuntimeException('IDEMPOTENCY_CONFLICT');$this->db->exec('COMMIT');return $old;}
            $q=$this->db->prepare("INSERT INTO operations_v2 VALUES(?,?,?,?,'ACCEPTED','{}',1)");$q->execute([$id,$account,$intent['idempotency_key'],$json]);
            $q=$this->db->prepare("INSERT INTO journal_v2 VALUES(?,1,'ACCEPTED','{}',?)");$q->execute([$id,time()]);$this->db->exec('COMMIT');return $this->get($id);
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function get(string $id):array {$q=$this->db->prepare('SELECT * FROM operations_v2 WHERE id=?');$q->execute([$id]);return $q->fetch(\PDO::FETCH_ASSOC)?:throw new \RuntimeException('NOT_FOUND');}
    public function byKey(string $account,string $key):?array {$q=$this->db->prepare('SELECT * FROM operations_v2 WHERE account=? AND idem=?');$q->execute([$account,$key]);return $q->fetch(\PDO::FETCH_ASSOC)?:null;}
    public function save(array $op,string $state,array $evidence=[]):array {
        $json=json_encode($evidence,JSON_THROW_ON_ERROR);$this->db->exec('BEGIN IMMEDIATE');
        try{
            $q=$this->db->prepare('UPDATE operations_v2 SET state=?,evidence=?,version=version+1 WHERE id=? AND version=?');$q->execute([$state,$json,$op['id'],$op['version']]);
            if($q->rowCount()!==1)throw new \RuntimeException('STATE_CONFLICT');
            $q=$this->db->prepare('INSERT INTO journal_v2 VALUES(?,?,?,?,?)');$q->execute([$op['id'],$op['version']+1,$state,$json,time()]);
            $this->db->exec('COMMIT');return $this->get($op['id']);
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function exclusive(string $id,\Closure $work):mixed {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Invalid operation ID');
        // Global worker lock also serializes competing releases for one application.
        $path=dirname($this->path).'/worker.lock';if(is_link($path))throw new \RuntimeException('Unsafe lock');
        $f=fopen($path,'c');chmod($path,0600);if(!$f||!flock($f,LOCK_EX|LOCK_NB))throw new \RuntimeException('WORKER_BUSY');
        try{return $work($this->get($id));}finally{flock($f,LOCK_UN);fclose($f);}
    }
}
