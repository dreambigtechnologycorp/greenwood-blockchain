<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Economics\{CanonicalJson,Policy};
use Greenwood\Compute\Containerd\SystemServiceHandle;

/** Local installation ownership only. Never a resource reservation or payment journal. */
final class SystemServiceInstallJournal
{
    private \PDO $db;
    private const EDGES=[
        'STAGED'=>['STARTING'], 'STARTING'=>['RUNNING','UNCERTAIN'],
        'RUNNING'=>['READY','STOPPING','UNCERTAIN'], 'READY'=>['DRAINING','STOPPING','UNCERTAIN'],
        'DRAINING'=>['STOPPING','UNCERTAIN'], 'STOPPING'=>['STOPPED','UNCERTAIN'],
        'UNCERTAIN'=>[], 'STOPPED'=>[],
    ];
    public function __construct(string $directory,string $chain,string $genesis)
    {
        Policy::hex($genesis);if($chain===''||strlen($chain)>128)throw new \InvalidArgumentException('Pinned service chain required');
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new \RuntimeException('Service journal unavailable');
        if(realpath($directory)!==$directory||is_link($directory)||fileowner($directory)!==posix_geteuid()||(fileperms($directory)&0077)!==0)throw new \RuntimeException('Private service journal required');
        $path=$directory.'/system-service-installs.sqlite';
        foreach(['','-wal','-shm'] as $suffix)if(is_link($path.$suffix)||(file_exists($path.$suffix)&&(!is_file($path.$suffix)||fileowner($path.$suffix)!==posix_geteuid()||(fileperms($path.$suffix)&0077)!==0)))throw new \RuntimeException('Unsafe service journal file');
        $this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);chmod($path,0600);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=1000');
        if($this->db->query('PRAGMA quick_check')->fetchColumn()!=='ok')throw new \RuntimeException('Service journal corrupt');
        $this->db->exec('CREATE TABLE IF NOT EXISTS identity(chain TEXT NOT NULL,genesis TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS installs(policy_hash TEXT PRIMARY KEY,container_id TEXT UNIQUE NOT NULL,body TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS runtime_pin(body TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS audit(policy_hash TEXT NOT NULL,sequence INTEGER NOT NULL,body TEXT NOT NULL,digest TEXT NOT NULL,PRIMARY KEY(policy_hash,sequence))');
        $this->db->exec('BEGIN IMMEDIATE');
        try{$rows=$this->db->query('SELECT * FROM identity')->fetchAll(\PDO::FETCH_ASSOC);
            if(!$rows){$q=$this->db->prepare('INSERT INTO identity VALUES(?,?)');$q->execute([$chain,$genesis]);}
            elseif($rows!==[['chain'=>$chain,'genesis'=>$genesis]])throw new \InvalidArgumentException('Service journal chain/genesis mismatch');
            $this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function get(string $hash): ?array
    {
        Policy::hex($hash);$q=$this->db->prepare('SELECT body FROM installs WHERE policy_hash=?');$q->execute([$hash]);$raw=$q->fetchColumn();if($raw===false)return null;$r=CanonicalJson::decode($raw);
        if(($r['policy_hash']??null)!==$hash||\Greenwood\Network\ResourcePolicyState::hash($r['manifest']??[])!==$hash||!isset(self::EDGES[$r['state']??''])||!is_int($r['height']??null)||$r['height']<1)throw new \RuntimeException('Corrupt installation record');
        if(($r['handle']??null)!==null)$this->validateHandle($r,new SystemServiceHandle(...$r['handle']));return $r;
    }
    public function assertIdentity(string $chain,string $genesis): void
    {
        if($this->db->query('SELECT * FROM identity')->fetchAll(\PDO::FETCH_ASSOC)!==[['chain'=>$chain,'genesis'=>$genesis]])throw new \InvalidArgumentException('Installation journal identity mismatch');
    }
    public function pinRuntime(string $namespace,string $socket,string $snapshotter): void
    {
        if(!preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/D',$namespace)||!str_starts_with($socket,'/')||str_contains($socket,"\0")||!in_array($snapshotter,['erofs','overlayfs'],true))throw new \InvalidArgumentException('Invalid local runtime pin');
        $body=CanonicalJson::encode(['namespace'=>$namespace,'socket'=>$socket,'snapshotter'=>$snapshotter]);$this->db->exec('BEGIN IMMEDIATE');
        try{$rows=$this->db->query('SELECT body FROM runtime_pin')->fetchAll(\PDO::FETCH_COLUMN);if(!$rows){$q=$this->db->prepare('INSERT INTO runtime_pin VALUES(?)');$q->execute([$body]);}elseif($rows!==[$body])throw new \InvalidArgumentException('Owned system-service runtime cannot be changed implicitly');$this->db->exec('COMMIT');}
        catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function stage(string $hash,array $manifest,string $containerId,int $height): array
    {
        Policy::hex($hash);if($height<1||!preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/D',$containerId))throw new \InvalidArgumentException('Invalid staged service identity');
        if(\Greenwood\Network\ResourcePolicyState::hash($manifest)!==$hash)throw new \InvalidArgumentException('Manifest commitment mismatch');
        $this->db->exec('BEGIN IMMEDIATE');
        try{$old=$this->get($hash);if($old){if($old['container_id']!==$containerId||CanonicalJson::encode($old['manifest'])!==CanonicalJson::encode($manifest))throw new \InvalidArgumentException('Conflicting staged service');$this->db->exec('COMMIT');return $old;}
            if((int)$this->db->query('SELECT COUNT(*) FROM installs')->fetchColumn()>=10000)throw new \RuntimeException('Installation audit retention bound reached');
            $record=['policy_hash'=>$hash,'container_id'=>$containerId,'manifest'=>$manifest,'state'=>'STAGED','height'=>$height,'handle'=>null,'drain_after'=>null,'sequence'=>0];
            $q=$this->db->prepare('INSERT INTO installs VALUES(?,?,?)');$q->execute([$hash,$containerId,CanonicalJson::encode($record)]);$this->appendAudit($record);$this->db->exec('COMMIT');return $record;
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function transition(string $hash,string $from,string $to,int $height,?SystemServiceHandle $handle=null,?int $drainAfter=null,?string $replacementHash=null): array
    {
        if(!in_array($to,self::EDGES[$from]??[],true)||$height<1)throw new \InvalidArgumentException('Forbidden service lifecycle transition');
        $this->db->exec('BEGIN IMMEDIATE');
        try{$r=$this->get($hash);if(!$r||$r['state']!==$from||$height<$r['height'])throw new \InvalidArgumentException('Service lifecycle changed or finalized view regressed');
            if($to==='RUNNING'){
                if(!$handle)throw new \InvalidArgumentException('Runtime handle required');$this->validateHandle($r,$handle);
                $r['handle']=get_object_vars($handle);
            }
            if($to==='DRAINING'){if($drainAfter===null||$drainAfter<$height)throw new \InvalidArgumentException('Explicit drain deadline required');Policy::hex($replacementHash);$r['drain_after']=$drainAfter;$r['replacement_hash']=$replacementHash;}
            $r['state']=$to;$r['height']=$height;$r['sequence']++;
            $q=$this->db->prepare('UPDATE installs SET body=? WHERE policy_hash=?');$q->execute([CanonicalJson::encode($r),$hash]);$this->appendAudit($r);$this->db->exec('COMMIT');return $r;
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function all(): array
    {
        $ids=$this->db->query('SELECT policy_hash FROM installs ORDER BY policy_hash')->fetchAll(\PDO::FETCH_COLUMN);return array_map($this->get(...),$ids);
    }
    private function validateHandle(array $r,SystemServiceHandle $h): void
    {
        $id=$r['container_id'];if($h->containerId!==$id||$h->serviceId!==$id||$h->imageDigest!==$r['manifest']['image_digest']||!str_ends_with($h->imageReference,'@'.$h->imageDigest)||$h->viewKey!=='gw-system-view-'.$id||$h->leaseId!=='gw-system-lease-'.$id||$h->pid<1||$h->parentSnapshot==='')throw new \InvalidArgumentException('Runtime handle differs from installation ownership');
    }
    private function appendAudit(array $r): void
    {
        $q=$this->db->prepare('SELECT body,digest FROM audit WHERE policy_hash=? ORDER BY sequence DESC LIMIT 1');$q->execute([$r['policy_hash']]);$last=$q->fetch(\PDO::FETCH_ASSOC);$prior=$last?CanonicalJson::decode($last['body']):null;
        $identity=$this->db->query('SELECT * FROM identity')->fetch(\PDO::FETCH_ASSOC);
        $event=['version'=>1,'chain'=>$identity['chain'],'genesis'=>$identity['genesis'],'policy_hash'=>$r['policy_hash'],'sequence'=>$prior?$prior['sequence']+1:0,'lifecycle_sequence'=>$r['sequence'],'height'=>$r['height'],'state'=>$r['state'],'history_complete'=>$prior?$prior['history_complete']:$r['sequence']===0,'previous_hash'=>$last?$last['digest']:str_repeat('0',64),'record_hash'=>bin2hex(\Greenwood\Crypto\ProtocolHash::hash('GREENWOOD_SYSTEM_SERVICE_RECORD_V1',CanonicalJson::encode($r)))];
        $body=CanonicalJson::encode($event);$digest=bin2hex(\Greenwood\Crypto\ProtocolHash::hash('GREENWOOD_SYSTEM_SERVICE_AUDIT_V1',$body));$q=$this->db->prepare('INSERT INTO audit VALUES(?,?,?,?)');$q->execute([$r['policy_hash'],$event['sequence'],$body,$digest]);
    }
    public function audit(string $hash): array
    {
        $r=$this->get($hash);if(!$r)throw new \InvalidArgumentException('Unknown service audit');$q=$this->db->prepare('SELECT * FROM audit WHERE policy_hash=? ORDER BY sequence');$q->execute([$hash]);$events=[];$previous=str_repeat('0',64);
        foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $row){$e=CanonicalJson::decode($row['body']);$digest=bin2hex(\Greenwood\Crypto\ProtocolHash::hash('GREENWOOD_SYSTEM_SERVICE_AUDIT_V1',$row['body']));
            if($e['sequence']!==count($events)||$e['previous_hash']!==$previous||$digest!==$row['digest']||$e['policy_hash']!==$hash)throw new \RuntimeException('Corrupt installation audit lineage');$events[]=$e;$previous=$digest;}
        if($events&&end($events)['record_hash']!==bin2hex(\Greenwood\Crypto\ProtocolHash::hash('GREENWOOD_SYSTEM_SERVICE_RECORD_V1',CanonicalJson::encode($r))))throw new \RuntimeException('Audit does not match current installation state');
        return ['version'=>1,'policy_hash'=>$hash,'events'=>$events,'head'=>$previous,'history_complete'=>$events?(bool)$events[0]['history_complete']:false,'independent_qualification'=>false];
    }
    public function signedAudit(string $hash,\Greenwood\Crypto\Ed25519KeyPair $signer): array
    {
        $body=CanonicalJson::encode($this->audit($hash));return ['body'=>$body,'public_key'=>bin2hex($signer->getPublicKey()),'signature'=>bin2hex($signer->sign(\Greenwood\Crypto\ProtocolHash::hash('GREENWOOD_SYSTEM_SERVICE_AUDIT_EXPORT_V1',$body)))];
    }
    public function confirmVacant(string $hash,int $height,VacantSystemService $proof): array
    {
        $this->pinRuntime($proof->namespace,$proof->socket,$proof->snapshotter);$this->db->exec('BEGIN IMMEDIATE');
        try{$r=$this->get($hash);if(!$r||!in_array($r['state'],['UNCERTAIN','STARTING','STOPPING'],true)||$height<$r['height']||$proof->recordHash!==bin2hex(\Greenwood\Crypto\ProtocolHash::hash('GREENWOOD_SYSTEM_SERVICE_RECORD_V1',CanonicalJson::encode($r))))throw new \InvalidArgumentException('Vacancy proof is stale or does not match uncertain ownership');
            $r['state']='STOPPED';$r['height']=$height;$r['sequence']++;$q=$this->db->prepare('UPDATE installs SET body=? WHERE policy_hash=?');$q->execute([CanonicalJson::encode($r),$hash]);$this->appendAudit($r);$this->db->exec('COMMIT');return $r;
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
}
