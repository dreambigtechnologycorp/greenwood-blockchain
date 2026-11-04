<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Assignment;
use Greenwood\Edge\ApprovedDeploymentStore;

/** Restricted mTLS handler. Destination and node identity are local administrator configuration. */
final class AssignmentReceiver {
    private readonly \PDO $db;
    public function __construct(private readonly string $nodeId,private readonly string $storeDirectory,string $ledgerPath,
        private readonly \Closure $authorize,private readonly \Closure $approvedAllocation,private readonly ?\Closure $afterInstall=null,private readonly ?\Closure $replacementSafe=null) {
        foreach([$storeDirectory,dirname($ledgerPath)]as$p)if(is_link($p)||!is_dir($p)||(fileperms($p)&0077)!==0||fileowner($p)!==posix_geteuid())throw new \RuntimeException('PRIVATE_RECEIVER_STORAGE_REQUIRED');
        if(is_link($ledgerPath))throw new \RuntimeException('UNSAFE_RECEIVER_LEDGER');
        $this->db=new \PDO('sqlite:'.$ledgerPath,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);chmod($ledgerPath,0600);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS generations(generation TEXT PRIMARY KEY, app INTEGER NOT NULL, service TEXT NOT NULL, version INTEGER NOT NULL, document TEXT NOT NULL, state TEXT NOT NULL, UNIQUE(app,service,version))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS receiver_nonces(nonce TEXT PRIMARY KEY, expires INTEGER NOT NULL)');
    }
    /** principal MUST originate from MutualTlsChannel::serveOne, never request JSON. */
    public function handle(string $principal,array $request):array {
        if($principal!=='deployment-controller'&&!($principal==='edge-admission'&&($request['action']??null)==='status'))throw new \RuntimeException('DISTRIBUTION_PRINCIPAL_REJECTED');
        if(array_diff(array_keys($request),['action','document','nonce','issued_at','expires_at'])||!in_array($request['action']??null,['prepare','commit','status'],true)
            ||!is_string($request['nonce']??null)||!preg_match('/^[a-f0-9]{64}$/D',$request['nonce'])||!is_int($request['issued_at']??null)||!is_int($request['expires_at']??null)
            ||$request['issued_at']>time()+2||$request['issued_at']<time()-60||$request['expires_at']<=time()||$request['expires_at']>$request['issued_at']+60)throw new \RuntimeException('INVALID_DISTRIBUTION_CHALLENGE');
        $d=AssignmentDocument::validate($request['document']);$generation=AssignmentDocument::generation($d);$a=$d['assignment'];
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->permission($d);
            $q=$this->db->prepare('INSERT OR IGNORE INTO receiver_nonces VALUES(?,?)');$q->execute([$request['nonce'],$request['expires_at']]);if($q->rowCount()!==1)throw new \RuntimeException('REPLAY');
            $q=$this->db->prepare('SELECT * FROM generations WHERE app=? AND service=? ORDER BY version DESC LIMIT 1');$q->execute([$a['application_id'],$a['service']]);$latest=$q->fetch(\PDO::FETCH_ASSOC);
            if($latest&&((int)$latest['version']>$d['version']||((int)$latest['version']===$d['version']&&$latest['generation']!==$generation)))throw new \RuntimeException('STALE_OR_CONFLICTING_GENERATION');
            $q=$this->db->prepare('SELECT * FROM generations WHERE generation=?');$q->execute([$generation]);$row=$q->fetch(\PDO::FETCH_ASSOC);
            if(!$row){
                if($request['action']!=='prepare')throw new \RuntimeException('GENERATION_NOT_PREPARED');
                if($latest&&($latest['state']!=='COMMITTED'||$d['version']!==(int)$latest['version']+1||$d['previous_generation']!==$latest['generation']))throw new \RuntimeException('PREVIOUS_GENERATION_UNCONFIRMED');
                if(!$latest&&$d['version']!==1)throw new \RuntimeException('MISSING_PREVIOUS_GENERATION');
                $q=$this->db->prepare("INSERT INTO generations VALUES(?,?,?,?,?,'PREPARED')");$q->execute([$generation,$a['application_id'],$a['service'],$d['version'],AssignmentDocument::canonical($d)]);
                $row=['state'=>'PREPARED'];
            }
            if($request['action']==='commit'){
                $this->permission($d);
                $path=$this->storeDirectory.'/'.$a['application_id'].'-'.$a['service'].'.json';$previous=null;
                if($d['previous_generation']!==null){$q=$this->db->prepare('SELECT document FROM generations WHERE generation=?');$q->execute([$d['previous_generation']]);$previous=json_decode($q->fetchColumn(),true,32,JSON_THROW_ON_ERROR)['assignment'];}
                $this->install($path,$a,$previous);($this->afterInstall)?->__invoke();
                $this->permission($d);
                $q=$this->db->prepare("UPDATE generations SET state='COMMITTED' WHERE generation=?");$q->execute([$generation]);$row['state']='COMMITTED';
            }
            if($row['state']==='COMMITTED'){
                $read=(new ApprovedDeploymentStore($this->storeDirectory,$a['chain_id'],$a['registry_contract_id']))->get($a['application_id'],$a['service'],$a['release_id']);
                if($read['assignment_id']!==AssignmentDocument::assignmentId($d))throw new \RuntimeException('INSTALL_READBACK_MISMATCH');
            }
            $this->permission($d);$this->db->exec('COMMIT');
            return ['node_id'=>$this->nodeId,'generation'=>$generation,'version'=>$d['version'],'assignment_id'=>AssignmentDocument::assignmentId($d),'state'=>$row['state'],
                'nonce'=>$request['nonce'],'observed_at'=>time()];
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    private function permission(array $d):void {
        if($d['version']>1&&($this->replacementSafe===null||($this->replacementSafe)($d)!==true))throw new \RuntimeException('REPLACEMENT_WITHDRAWAL_UNVERIFIED');
        if(($this->authorize)($d)!==true||($this->approvedAllocation)($d)!==true)throw new \RuntimeException('ASSIGNMENT_AUTHORITY_REVOKED_OR_ALLOCATION_INVALID');
    }
    private function install(string $path,array $a,?array $previous):void {
        if(is_link($path))throw new \RuntimeException('UNSAFE_ASSIGNMENT_PATH');
        if(is_file($path)){
            if((fileperms($path)&0077)!==0||fileowner($path)!==posix_geteuid())throw new \RuntimeException('UNSAFE_ASSIGNMENT_OWNERSHIP');
            $old=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
            if(AssignmentDocument::canonical($old)===AssignmentDocument::canonical($a))return; // recovery after file sync and before ledger commit
            if($previous===null||AssignmentDocument::canonical($old)!==AssignmentDocument::canonical($previous))throw new \RuntimeException('INSTALLED_ASSIGNMENT_CONFLICT');
        }elseif($previous!==null)throw new \RuntimeException('PREVIOUS_ASSIGNMENT_MISSING');
        $temp=tempnam($this->storeDirectory,'.assignment-');$file=fopen($temp,'wb');
        try{
            chmod($temp,0600);ksort($a);$bytes=json_encode($a,JSON_THROW_ON_ERROR);
            if(fwrite($file,$bytes)!==strlen($bytes)||!fflush($file)||!fsync($file))throw new \RuntimeException('ASSIGNMENT_SYNC_FAILED');
        }finally{fclose($file);}
        try{
            if(!rename($temp,$path))throw new \RuntimeException('ASSIGNMENT_INSTALL_FAILED');
            $dir=fopen($this->storeDirectory,'r');try{if(!fsync($dir))throw new \RuntimeException('ASSIGNMENT_DIRECTORY_SYNC_FAILED');}finally{fclose($dir);}
        }finally{if(is_file($temp))unlink($temp);}
    }
}
