<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Compute\Containerd\CgroupAccounting;
use Greenwood\Economics\{Amount,CanonicalJson,Policy};
use Greenwood\Crypto\ProtocolHash;

/** Provider/control-plane library, not a daemon or financial ledger.
 * Local samples are level C. No method promotes them to independent attestation.
 */
final class MeteringService implements MeterJournal
{
    private readonly \PDO $db;
    private readonly JournalArchive $archive;
    public function __construct(string $directory) {
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new \RuntimeException('Meter journal directory unavailable');
        if(realpath($directory)!==$directory||is_link($directory)||(fileperms($directory)&0077)!==0||fileowner($directory)!==posix_geteuid())throw new \RuntimeException('Private meter journal required');
        $path=$directory.'/meter-segments.sqlite';if(is_link($path)||(is_file($path)&&((fileperms($path)&0077)!==0||fileowner($path)!==posix_geteuid())))throw new \RuntimeException('Unsafe meter journal');
        $this->archive=new JournalArchive($directory.'/archive');
        $this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);chmod($path,0600);$this->db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=1000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS segments(commitment TEXT NOT NULL, runtime TEXT NOT NULL, first_sample TEXT NOT NULL, last_sample TEXT, state TEXT NOT NULL, PRIMARY KEY(commitment,runtime))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS checkpoints(commitment TEXT NOT NULL,runtime TEXT NOT NULL,sequence INTEGER NOT NULL,sample TEXT NOT NULL,previous_hash TEXT NOT NULL,digest TEXT NOT NULL,PRIMARY KEY(commitment,runtime,sequence))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS funded_bindings(commitment TEXT PRIMARY KEY,scope TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS checkpoint_chunks(commitment TEXT NOT NULL,runtime TEXT NOT NULL,first_seq INTEGER NOT NULL,digest TEXT NOT NULL,PRIMARY KEY(commitment,runtime,first_seq))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS archived_commitments(commitment TEXT PRIMARY KEY,digest TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS archived_runtime(runtime TEXT PRIMARY KEY,cpu_ms TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS uncertain_boundaries(commitment TEXT NOT NULL,runtime TEXT NOT NULL,PRIMARY KEY(commitment,runtime))');
    }
    public static function observeTask(string $namespace,string $containerId): array {return CgroupAccounting::forTask($namespace,$containerId)->observe();}
    public function bind(array $scope): void {
        \Greenwood\Resources\Metering\SignedObservation::scope($scope);$raw=CanonicalJson::encode($scope);$this->db->exec('BEGIN IMMEDIATE');
        try{if($old=$this->archived($scope['commitment'])){if(CanonicalJson::encode($old['bundle']['bundle']['funding_scope']??[])!==$raw)throw new \InvalidArgumentException('Archived scope is immutable');$this->db->exec('COMMIT');return;}
            $q=$this->db->prepare('SELECT scope FROM funded_bindings WHERE commitment=?');$q->execute([$scope['commitment']]);$old=$q->fetchColumn();
            if($old!==false&&$old!==$raw)throw new \InvalidArgumentException('Funded meter scope is immutable');
            if($old===false){if((int)$this->db->query('SELECT COUNT(*) FROM funded_bindings')->fetchColumn()>=100000)throw new \RuntimeException('Funded meter binding capacity');$q=$this->db->prepare('INSERT INTO funded_bindings VALUES(?,?)');$q->execute([$scope['commitment'],$raw]);}$this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function segmentState(string $commitment,string $runtime): ?string {
        Policy::hex($commitment);Policy::hex($runtime);if($old=$this->archived($commitment)){foreach($old['bundle']['bundle']['segments'] as $segment)if($segment['runtime_identity']===$runtime)return 'CLOSED';return null;}$q=$this->db->prepare('SELECT state FROM segments WHERE commitment=? AND runtime=?');$q->execute([$commitment,$runtime]);$state=$q->fetchColumn();return $state===false?null:$state;
    }
    public function openSegments(string $runtime): array {
        Policy::hex($runtime);$q=$this->db->prepare("SELECT s.commitment,b.scope FROM segments s LEFT JOIN funded_bindings b ON b.commitment=s.commitment WHERE s.runtime=? AND s.state='OPEN' LIMIT 2");$q->execute([$runtime]);$rows=$q->fetchAll(\PDO::FETCH_ASSOC);
        if(count($rows)>1)throw new \RuntimeException('Ambiguous open runtime ownership');return array_map(static fn($r)=>['commitment'=>$r['commitment'],'scope'=>$r['scope']===null?null:CanonicalJson::decode($r['scope'])],$rows);
    }
    public function closeBoundary(string $commitment,array $sample): void {$this->finish($commitment,$sample,true);}
    private static function sample(array $s): void {
        if(($s['version']??null)!==1||($s['verification_level']??null)!=='C'||($s['source']??null)!=='local_cgroup_v2')throw new \InvalidArgumentException('Local collector cannot claim independent qualification');
        if(strlen(CanonicalJson::encode($s))>65536)throw new \InvalidArgumentException('Observation exceeds bounded framing');
        Policy::hex($s['runtime_identity']??null);Amount::integer($s['cpu_ms']??null);
        foreach(['memory_peak_bytes','memory_current_bytes','memory_limit_bytes','cpu_limit_millicores','monotonic_ms'] as $field)if(($s[$field]??null)!==null)Amount::integer($s[$field]);
        if(($s['network']??null)!==null)\Greenwood\Resources\Metering\NetworkAccounting::delta($s['network'],$s['network']);
        if(($s['io_devices']??null)!==null){
            if(!is_array($s['io_devices'])||count($s['io_devices'])>256)throw new \InvalidArgumentException('Bounded device counters required');
            foreach($s['io_devices'] as $device=>$counters){
                if(!preg_match('/^[0-9]+:[0-9]+$/D',(string)$device)||!is_array($counters))throw new \InvalidArgumentException('Invalid block device identity');
                foreach(['rbytes','wbytes','rios','wios'] as $field)Amount::integer($counters[$field]??null);
            }
        }
    }
    /** Durable periodic sample. Sequence/hash lineage is local provenance, never proof of independence. */
    public function checkpoint(string $commitment,array $sample): array {
        Policy::hex($commitment);self::sample($sample);$this->db->exec('BEGIN IMMEDIATE');
        try{$result=$this->appendCheckpoint($commitment,$sample);$this->db->exec('COMMIT');return $result;}
        catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    private function appendCheckpoint(string $commitment,array $sample,bool $terminal=false): array {
        $runtime=$sample['runtime_identity'];$q=$this->db->prepare('SELECT first_sample,state FROM segments WHERE commitment=? AND runtime=?');$q->execute([$commitment,$runtime]);$segment=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$segment||$segment['state']!=='OPEN')throw new \InvalidArgumentException('Checkpoint requires an open attributable segment');
        $q=$this->db->prepare('SELECT * FROM checkpoints WHERE commitment=? AND runtime=? ORDER BY sequence DESC LIMIT 1');$q->execute([$commitment,$runtime]);$old=$q->fetch(\PDO::FETCH_ASSOC);
        $sequence=$old?Amount::add((int)$old['sequence'],1):0;
        $count=$this->db->prepare('SELECT COUNT(*) FROM checkpoints WHERE commitment=? AND runtime=?');$count->execute([$commitment,$runtime]);
        if((int)$count->fetchColumn()>=512)$this->rollCheckpoints($commitment,$runtime);
        $prior=CanonicalJson::decode($old?$old['sample']:$segment['first_sample']);
        if(isset($prior['monotonic_ms'])&&(!isset($sample['monotonic_ms'])||Amount::integer($sample['monotonic_ms'])<Amount::integer($prior['monotonic_ms'])))throw new \InvalidArgumentException('Monotonic clock reset or missing timestamp requires reconciliation');
        if(array_key_exists('memory_limit_bytes',$prior)&&($sample['memory_limit_bytes']??null)!==$prior['memory_limit_bytes'])throw new \InvalidArgumentException('Memory reservation limit changed during interval');
        if(Amount::integer($sample['cpu_ms'])<Amount::integer($prior['cpu_ms']))throw new \InvalidArgumentException('CPU counter reset requires reconciliation');
        if(($prior['memory_peak_bytes']??null)!==null&&($sample['memory_peak_bytes']??null)!==null&&Amount::integer($sample['memory_peak_bytes'])<Amount::integer($prior['memory_peak_bytes']))throw new \InvalidArgumentException('Memory peak reset requires reconciliation');
        if(($prior['network']??null)!==null)\Greenwood\Resources\Metering\NetworkAccounting::delta($prior['network'],$sample['network']??[]);
        foreach(($prior['io_devices']??[]) as $device=>$counters)foreach($counters as $field=>$value){
            if(!isset($sample['io_devices'][$device][$field])||Amount::integer($sample['io_devices'][$device][$field])<Amount::integer($value))throw new \InvalidArgumentException('Block counter reset or missing device requires reconciliation');
        }
        $body=['commitment'=>$commitment,'runtime'=>$runtime,'sequence'=>$sequence,'sample'=>$sample,'previous_hash'=>$old?$old['digest']:str_repeat('0',64)];
        $digest=bin2hex(ProtocolHash::hash('GREENWOOD_METER_CHECKPOINT_V1',CanonicalJson::encode($body)));
        $q=$this->db->prepare('INSERT INTO checkpoints VALUES(?,?,?,?,?,?)');$q->execute([$commitment,$runtime,$sequence,CanonicalJson::encode($sample),$body['previous_hash'],$digest]);
        return $body+['digest'=>$digest];
    }
    private function checkpointLineage(string $commitment,string $runtime): array {
        $q=$this->db->prepare('SELECT * FROM checkpoints WHERE commitment=? AND runtime=? ORDER BY sequence');$q->execute([$commitment,$runtime]);$previous=str_repeat('0',64);$sequence=0;
        $active=$q->fetchAll(\PDO::FETCH_ASSOC);
        $chunks=$this->db->prepare('SELECT digest FROM checkpoint_chunks WHERE commitment=? AND runtime=? ORDER BY first_seq');$chunks->execute([$commitment,$runtime]);
        $sets=(function()use($chunks,$active){while(($hash=$chunks->fetchColumn())!==false)yield $this->archive->get($hash)['checkpoints'];yield $active;})();
        foreach($sets as $rows)foreach($rows as $row){
            if((int)$row['sequence']===$sequence-1&&$row['digest']===$previous)continue; // retained overlap anchor
            if((int)$row['sequence']!==$sequence||$row['previous_hash']!==$previous||$row['commitment']!==$commitment||$row['runtime']!==$runtime)throw new \RuntimeException('Broken meter checkpoint lineage');
            $body=['commitment'=>$commitment,'runtime'=>$runtime,'sequence'=>$sequence,'sample'=>CanonicalJson::decode($row['sample']),'previous_hash'=>$previous];
            $digest=bin2hex(ProtocolHash::hash('GREENWOOD_METER_CHECKPOINT_V1',CanonicalJson::encode($body)));
            if($digest!==$row['digest'])throw new \RuntimeException('Corrupt meter checkpoint');$previous=$digest;$sequence++;
        }return ['checkpoint_count'=>$sequence,'checkpoint_head'=>$sequence?$previous:null];
    }
    private function rollCheckpoints(string $commitment,string $runtime): void
    {
        $this->checkpointLineage($commitment,$runtime);
        $q=$this->db->prepare('SELECT * FROM checkpoints WHERE commitment=? AND runtime=? ORDER BY sequence');$q->execute([$commitment,$runtime]);$rows=$q->fetchAll(\PDO::FETCH_ASSOC);
        $hash=$this->archive->put(['version'=>1,'kind'=>'meter-checkpoints','checkpoints'=>$rows]);
        $q=$this->db->prepare('INSERT INTO checkpoint_chunks VALUES(?,?,?,?)');$q->execute([$commitment,$runtime,(int)$rows[0]['sequence'],$hash]);
        $q=$this->db->prepare('DELETE FROM checkpoints WHERE commitment=? AND runtime=? AND sequence<?');$q->execute([$commitment,$runtime,(int)end($rows)['sequence']]);
    }
    public function begin(string $commitment,array $sample): void {
        Policy::hex($commitment);self::sample($sample);$this->db->exec('BEGIN IMMEDIATE');
        try{
            if($this->archived($commitment))throw new \InvalidArgumentException('Archived commitment cannot reopen');
            $guard=$this->db->prepare('SELECT cpu_ms FROM archived_runtime WHERE runtime=?');$guard->execute([$sample['runtime_identity']]);$minimum=$guard->fetchColumn();if($minimum!==false&&Amount::integer($sample['cpu_ms'])<Amount::integer($minimum))throw new \InvalidArgumentException('Archived counter range already attributed');
            if((int)$this->db->query('SELECT COUNT(*) FROM segments')->fetchColumn()>=100000)throw new \InvalidArgumentException('Local meter archive required');
            $count=$this->db->prepare('SELECT COUNT(*) FROM segments WHERE commitment=?');$count->execute([$commitment]);if((int)$count->fetchColumn()>=256)throw new \InvalidArgumentException('Segment bound reached; reconciliation required');
            $q=$this->db->prepare('SELECT commitment,first_sample,last_sample,state FROM segments WHERE runtime=?');$q->execute([$sample['runtime_identity']]);
            foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $old){if($old['commitment']===$commitment||$old['state']==='OPEN')throw new \InvalidArgumentException('Runtime segment exists or belongs to an open interval');
                $last=CanonicalJson::decode($old['last_sample']);if(Amount::integer($sample['cpu_ms'])<Amount::integer($last['cpu_ms']))throw new \InvalidArgumentException('Counter range already attributed to a prior interval');}
            $q=$this->db->prepare("INSERT INTO segments VALUES(?,?,?,NULL,'OPEN')");$q->execute([$commitment,$sample['runtime_identity'],CanonicalJson::encode($sample)]);$this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}

    }
    public function finish(string $commitment,array $sample,bool $boundaryUncertain=false): void {
        Policy::hex($commitment);self::sample($sample);$this->db->exec('BEGIN IMMEDIATE');
        try{$q=$this->db->prepare('SELECT first_sample,state FROM segments WHERE commitment=? AND runtime=?');$q->execute([$commitment,$sample['runtime_identity']]);$row=$q->fetch(\PDO::FETCH_ASSOC);
            if(!$row||$row['state']!=='OPEN')throw new \InvalidArgumentException('Unknown, closed or changed runtime segment');$first=CanonicalJson::decode($row['first_sample']);
            if(Amount::integer($sample['cpu_ms'])<Amount::integer($first['cpu_ms']))throw new \InvalidArgumentException('Counter reset requires independent reconciliation');
            $this->appendCheckpoint($commitment,$sample,true);
            if($boundaryUncertain){$q=$this->db->prepare('INSERT INTO uncertain_boundaries VALUES(?,?)');$q->execute([$commitment,$sample['runtime_identity']]);}
            $q=$this->db->prepare("UPDATE segments SET last_sample=?,state='CLOSED' WHERE commitment=? AND runtime=?");$q->execute([CanonicalJson::encode($sample),$commitment,$sample['runtime_identity']]);$this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function bundle(string $commitment): array {
        Policy::hex($commitment);if($old=$this->archived($commitment))return $old['bundle'];$q=$this->db->prepare('SELECT * FROM segments WHERE commitment=? ORDER BY runtime');$q->execute([$commitment]);$rows=$q->fetchAll(\PDO::FETCH_ASSOC);
        if(!$rows)throw new \InvalidArgumentException('No attributable observations');$total=0;$segments=[];
        foreach($rows as $row){if($row['state']!=='CLOSED')throw new \InvalidArgumentException('Collector outage/open segment requires reconciliation');$first=CanonicalJson::decode($row['first_sample']);$last=CanonicalJson::decode($row['last_sample']);$delta=Amount::integer($last['cpu_ms'])-Amount::integer($first['cpu_ms']);$total=Amount::add($total,$delta);$segments[]=['runtime_identity'=>$row['runtime'],'first'=>$first,'last'=>$last,'cpu_ms'=>(string)$delta]+$this->checkpointLineage($commitment,$row['runtime']);}
        $body=['version'=>1,'commitment'=>$commitment,'verification_level'=>'C','segments'=>$segments];$q=$this->db->prepare('SELECT scope FROM funded_bindings WHERE commitment=?');$q->execute([$commitment]);$scope=$q->fetchColumn();if($scope!==false)$body['funding_scope']=CanonicalJson::decode($scope);
        $q=$this->db->prepare('SELECT COUNT(*) FROM uncertain_boundaries WHERE commitment=?');$q->execute([$commitment]);$uncertain=(int)$q->fetchColumn()>0;
        if($uncertain)$body['boundary_reconciliation_required']=true;
        return ['bundle'=>$body,'cpu_ms'=>$uncertain?null:(string)$total,'measurement_status'=>$uncertain?'BOUNDARY_RECONCILIATION_REQUIRED':'PROVIDER_ORIGIN_DIAGNOSTIC','evidence_bundle_hash'=>bin2hex(ProtocolHash::hash('GREENWOOD_LOCAL_METER_BUNDLE_V1',CanonicalJson::encode($body))),'subsidy_eligible'=>false];
    }
    private function archived(string $commitment): ?array
    {
        Policy::hex($commitment);$q=$this->db->prepare('SELECT digest FROM archived_commitments WHERE commitment=?');$q->execute([$commitment]);$digest=$q->fetchColumn();if($digest===false)return null;$record=$this->archive->get($digest);foreach($record['chunks']??[] as $chunk)$this->archive->get($chunk['digest']);return $record;
    }
    /** Retains full evidence and replay guards; never discards an open/uncertain interval. */
    public function archiveClosed(string $commitment,string $expectedBundleHash): string
    {
        Policy::hex($commitment);Policy::hex($expectedBundleHash);$this->db->exec('BEGIN IMMEDIATE');
        try{
            $bundle=$this->bundle($commitment);
            if($bundle['evidence_bundle_hash']!==$expectedBundleHash||($bundle['bundle']['boundary_reconciliation_required']??false))throw new \InvalidArgumentException('Exact reconciled closed bundle required');
            if($old=$this->archived($commitment)){$digest=hash('sha256',CanonicalJson::encode($old));$this->db->exec('COMMIT');return $digest;}
            $q=$this->db->prepare('SELECT COALESCE(SUM(LENGTH(sample)),0) FROM checkpoints WHERE commitment=?');$q->execute([$commitment]);if((int)$q->fetchColumn()>8388608)throw new \RuntimeException('Checkpoint export exceeds archive bound');
            $q=$this->db->prepare('SELECT * FROM checkpoints WHERE commitment=? ORDER BY runtime,sequence');$q->execute([$commitment]);
            $rows=$q->fetchAll(\PDO::FETCH_ASSOC);$q=$this->db->prepare('SELECT * FROM checkpoint_chunks WHERE commitment=? ORDER BY runtime,first_seq');$q->execute([$commitment]);
            $digest=$this->archive->put(['version'=>1,'kind'=>'meter','bundle'=>$bundle,'checkpoints'=>$rows,'chunks'=>$q->fetchAll(\PDO::FETCH_ASSOC)]);
            foreach($bundle['bundle']['segments'] as $segment){$runtime=$segment['runtime_identity'];$last=$segment['last']['cpu_ms'];
                $q=$this->db->prepare('SELECT cpu_ms FROM archived_runtime WHERE runtime=?');$q->execute([$runtime]);$old=$q->fetchColumn();if($old!==false)$last=(string)max(Amount::integer($old),Amount::integer($last));
                $q=$this->db->prepare('INSERT OR REPLACE INTO archived_runtime VALUES(?,?)');$q->execute([$runtime,$last]);}
            $q=$this->db->prepare('INSERT INTO archived_commitments VALUES(?,?)');$q->execute([$commitment,$digest]);
            foreach(['checkpoint_chunks','checkpoints','segments','funded_bindings','uncertain_boundaries'] as $table){$q=$this->db->prepare('DELETE FROM '.$table.' WHERE commitment=?');$q->execute([$commitment]);}
            $this->db->exec('COMMIT');return $digest;
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }

}
