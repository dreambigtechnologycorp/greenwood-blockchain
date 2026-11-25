<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Economics\{Amount,CanonicalJson,Policy};
use Greenwood\Crypto\ProtocolHash;
use Greenwood\Resources\{ResourceCatalog,ResourceManifest};
use Greenwood\Resources\Metering\{SignedObservation,TelemetryFrame};

/** All resource families share exact workload scope, sequence, source and clock rules.
 * Hardware collectors supply counters; only accepted native policies can authorize money.
 */
final class ResourceTelemetryJournal
{
    private \PDO $db;
    private JournalArchive $archive;
    public function __construct(string $directory)
    {
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new \RuntimeException('Telemetry directory unavailable');
        if(realpath($directory)!==$directory||is_link($directory)||fileowner($directory)!==posix_geteuid()||(fileperms($directory)&0077)!==0)throw new \RuntimeException('Private telemetry directory required');
        $path=$directory.'/resource-telemetry.sqlite';foreach(['','-journal','-wal','-shm'] as $suffix)if(is_link($path.$suffix)||(file_exists($path.$suffix)&&(!is_file($path.$suffix)||fileowner($path.$suffix)!==posix_geteuid()||(fileperms($path.$suffix)&0077)!==0)))throw new \RuntimeException('Unsafe telemetry database');
        $this->archive=new JournalArchive($directory.'/archive');$this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);chmod($path,0600);
        \Greenwood\Persistence\SqliteDurability::configure($this->db, 1000);
        $this->db->exec('CREATE TABLE IF NOT EXISTS intervals(id TEXT PRIMARY KEY,body TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS archived(id TEXT PRIMARY KEY,digest TEXT NOT NULL)');
    }
    private function load(string $id): array
    {
        Policy::hex($id);$q=$this->db->prepare('SELECT body FROM intervals WHERE id=?');$q->execute([$id]);$body=$q->fetchColumn();
        if($body!==false)return CanonicalJson::decode($body);
        $q=$this->db->prepare('SELECT digest FROM archived WHERE id=?');$q->execute([$id]);$digest=$q->fetchColumn();
        if($digest!==false)return $this->archive->get($digest);
        throw new \InvalidArgumentException('Unknown telemetry interval');
    }
    public function bind(array $scope,array $manifest,string $sourceKey,int $maximumGapMs): void
    {
        SignedObservation::scope($scope);Policy::hex($sourceKey);
        if(ResourceManifest::hash($manifest)!==$scope['manifest_hash']||!is_array($manifest['lines']??null)||!array_is_list($manifest['lines'])||!$manifest['lines']||count($manifest['lines'])>32||$maximumGapMs<1||$maximumGapMs>Amount::integer($manifest['duration_ms']??null))throw new \InvalidArgumentException('Exact bounded accepted manifest required');
        $previous='';foreach($manifest['lines'] as $line){$d=ResourceCatalog::definition($line['resource_id']??null);Policy::hex($line['asset_id']??null);if(!is_string($line['line_id']??null)||!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$line['line_id'])||strcmp($line['line_id'],$previous)<=0||($line['meter_version']??null)!==$d['meter_version']||Amount::integer($line['reserved_units']??null)<1)throw new \InvalidArgumentException('Exact canonical resource lines required');$previous=$line['line_id'];}
        $body=['scope'=>$scope,'manifest'=>$manifest,'source_key'=>$sourceKey,'maximum_gap_ms'=>$maximumGapMs,'state'=>'OPEN','first'=>null,'last'=>null,'totals'=>[],'available_ms'=>[],'head'=>str_repeat('0',64),'count'=>0,'unverified'=>false];
        $this->db->exec('BEGIN IMMEDIATE');try{
            $q=$this->db->prepare('SELECT id FROM intervals WHERE id=? UNION ALL SELECT id FROM archived WHERE id=?');$q->execute([$scope['commitment'],$scope['commitment']]);if($q->fetchColumn()!==false)throw new \InvalidArgumentException('Telemetry binding already exists');
            if((int)$this->db->query('SELECT COUNT(*) FROM intervals')->fetchColumn()>=100000)throw new \RuntimeException('Archive closed telemetry intervals before capacity exhaustion');
            $q=$this->db->prepare('INSERT INTO intervals VALUES(?,?)');$q->execute([$scope['commitment'],CanonicalJson::encode($body)]);$this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    /** Resume only the exact previously accepted source configuration. */
    public function resume(array $scope,array $manifest,string $sourceKey,int $maximumGapMs): array
    {
        $q=$this->db->prepare('SELECT body FROM intervals WHERE id=?');$q->execute([$scope['commitment']]);$raw=$q->fetchColumn();
        if($raw===false){$this->bind($scope,$manifest,$sourceKey,$maximumGapMs);return ['sequence'=>0,'state'=>'OPEN'];}
        $r=CanonicalJson::decode($raw);
        if(CanonicalJson::encode($r['scope'])!==CanonicalJson::encode($scope)||CanonicalJson::encode($r['manifest'])!==CanonicalJson::encode($manifest)||$r['source_key']!==$sourceKey||$r['maximum_gap_ms']!==$maximumGapMs)throw new \InvalidArgumentException('Collector cannot replace accepted binding');
        return ['sequence'=>$r['count'],'state'=>$r['state']];
    }
    public function append(string $id,array $signed,bool $close=false): array
    {
        $this->db->exec('BEGIN IMMEDIATE');try{
            $r=$this->load($id);if($r['state']!=='OPEN')throw new \InvalidArgumentException('Telemetry interval closed');$f=TelemetryFrame::verify($signed,$r['source_key']);
            $fields=array_keys($f);sort($fields);
            if($fields!==['measurements','monotonic_ms','runtime_identity','scope_hash','sequence','source_incarnation','version']||$f['version']!==1||$f['sequence']!==$r['count']
                ||$f['scope_hash']!==hash('sha256',CanonicalJson::encode($r['scope']))||!is_array($f['measurements'])||!array_is_list($f['measurements'])||count($f['measurements'])!==count($r['manifest']['lines']))throw new \InvalidArgumentException('Telemetry scope/sequence mismatch');
            Policy::hex($f['runtime_identity']);Policy::hex($f['source_incarnation']);$now=Amount::integer($f['monotonic_ms']);$last=$r['last'];$dt=0;
            if($last){$dt=$now-Amount::integer($last['monotonic_ms']);if($dt<=0||$dt>$r['maximum_gap_ms']||$f['runtime_identity']!==$last['runtime_identity']||$f['source_incarnation']!==$last['source_incarnation'])throw new \InvalidArgumentException('Telemetry gap/reset requires explicit interval reconciliation');}
            if($r['first']&&$now-Amount::integer($r['first']['monotonic_ms'])>Amount::integer($r['manifest']['duration_ms']))throw new \InvalidArgumentException('Telemetry exceeds accepted interval');
            foreach($r['manifest']['lines'] as $i=>$line){$v=$f['measurements'][$i];$fields=is_array($v)?array_keys($v):[];sort($fields);
                if($fields!==['asset_id','available','line_id','value']||$v['line_id']!==$line['line_id']||$v['asset_id']!==$line['asset_id']||!is_bool($v['available']))throw new \InvalidArgumentException('Telemetry line attribution mismatch');
                $value=Amount::integer($v['value']);$name=$line['line_id'];$def=ResourceCatalog::definition($line['resource_id']);
                $r['totals'][$name]??='0';$r['available_ms'][$name]??='0';
                if($def['measurement_type']==='reserved'){
                    $units=Amount::integer($line['reserved_units']);$eligible=$last&&$v['available']&&$last['measurements'][$i]['available']&&$value>=$units&&Amount::integer($last['measurements'][$i]['value'])>=$units;
                    if($eligible){$r['available_ms'][$name]=(string)Amount::add(Amount::integer($r['available_ms'][$name]),$dt);$r['totals'][$name]=(string)Amount::multiply($units,Amount::integer($r['available_ms'][$name]));}
                }else{
                    if(!$v['available'])$r['unverified']=true;
                    if($last){$delta=$value-Amount::integer($last['measurements'][$i]['value']);if($delta<0)throw new \InvalidArgumentException('Consumed counter reset');$r['totals'][$name]=(string)Amount::add(Amount::integer($r['totals'][$name]),$delta);}
                }
            }
            ResourceManifest::value($r['manifest'],$r['totals']);
            if($close&&(!$last||$dt<=0))throw new \InvalidArgumentException('Two snapshots required before close');
            $r['head']=$this->archive->put(['version'=>1,'kind'=>'resource-telemetry-frame','previous_hash'=>$r['head'],'signed'=>$signed]);
            $r['first']??=$f;$r['last']=$f;$r['count']=Amount::add($r['count'],1);if($close)$r['state']='CLOSED';
            $q=$this->db->prepare('UPDATE intervals SET body=? WHERE id=?');$q->execute([CanonicalJson::encode($r),$id]);$this->db->exec('COMMIT');
            return ['state'=>$r['state'],'count'=>$r['count'],'head'=>$r['head'],'payable'=>false,'verification_level'=>'C'];
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function evidence(string $id): array
    {
        $r=$this->load($id);if($r['state']!=='CLOSED'||$r['unverified'])throw new \InvalidArgumentException('Complete available telemetry required');
        // Verify retained provenance before emitting a candidate, including after archival/restart.
        $hash=$r['head'];$later=null;$totals=[];$available=[];$unverified=false;
        foreach($r['manifest']['lines'] as $line){$totals[$line['line_id']]='0';$available[$line['line_id']]='0';}
        for($i=$r['count']-1;$i>=0;$i--){
            $row=$this->archive->get($hash);$f=TelemetryFrame::verify($row['signed'],$r['source_key']);
            if($f['sequence']!==$i||$f['scope_hash']!==hash('sha256',CanonicalJson::encode($r['scope']))||$f['runtime_identity']!==$r['first']['runtime_identity']||$f['source_incarnation']!==$r['first']['source_incarnation'])throw new \RuntimeException('Telemetry archive lineage mismatch');
            if($i===$r['count']-1&&CanonicalJson::encode($f)!==CanonicalJson::encode($r['last']))throw new \RuntimeException('Telemetry last frame differs from archive');
            $dt=$later?Amount::integer($later['monotonic_ms'])-Amount::integer($f['monotonic_ms']):0;
            if($later&&($dt<=0||$dt>$r['maximum_gap_ms']))throw new \RuntimeException('Archived clock discontinuity');
            foreach($r['manifest']['lines'] as $j=>$line){$v=$f['measurements'][$j];$name=$line['line_id'];$def=ResourceCatalog::definition($line['resource_id']);
                if($v['line_id']!==$name||$v['asset_id']!==$line['asset_id'])throw new \RuntimeException('Archived asset mismatch');
                if($def['measurement_type']==='reserved'){
                    $units=Amount::integer($line['reserved_units']);if($later&&$v['available']&&$later['measurements'][$j]['available']&&Amount::integer($v['value'])>=$units&&Amount::integer($later['measurements'][$j]['value'])>=$units){$available[$name]=(string)Amount::add(Amount::integer($available[$name]),$dt);$totals[$name]=(string)Amount::multiply($units,Amount::integer($available[$name]));}
                }else{
                    if(!$v['available'])$unverified=true;
                    if($later){$delta=Amount::integer($later['measurements'][$j]['value'])-Amount::integer($v['value']);if($delta<0)throw new \RuntimeException('Archived counter reset');$totals[$name]=(string)Amount::add(Amount::integer($totals[$name]),$delta);}
                }
            }
            $later=$f;$hash=$row['previous_hash'];
        }
        if($hash!==str_repeat('0',64)||$unverified||CanonicalJson::encode($later??[])!==CanonicalJson::encode($r['first'])||CanonicalJson::encode($totals)!==CanonicalJson::encode($r['totals'])||CanonicalJson::encode($available)!==CanonicalJson::encode($r['available_ms']))throw new \RuntimeException('Telemetry archive/cache integrity mismatch');
        $s=$r['scope'];$measurements=[];foreach($r['manifest']['lines'] as $line){$reserved=ResourceCatalog::definition($line['resource_id'])['measurement_type']==='reserved';$measurements[]=['line_id'=>$line['line_id'],'asset_id'=>$line['asset_id'],'meter_version'=>$line['meter_version'],'quantity'=>$r['totals'][$line['line_id']],'reserved_units'=>$reserved?$line['reserved_units']:null,'available_ms'=>$reserved?$r['available_ms'][$line['line_id']]:null,'source_hash'=>$r['head']];}
        $devices=array_values(array_unique(array_column($r['manifest']['lines'],'asset_id')));sort($devices,SORT_STRING);
        return ['version'=>1,'chain'=>$s['chain'],'genesis'=>$s['genesis'],'commitment'=>$id,'provider_id'=>$s['provider_id'],'workload_id'=>$s['workload_id'],'assignment_generation'=>$s['assignment_generation'],'billing_interval_id'=>$id,'resource_catalog_hash'=>$s['catalog_hash'],'resource_manifest_hash'=>$s['manifest_hash'],'clock'=>'attested_monotonic_ms_v1','measurement_start_ms'=>$r['first']['monotonic_ms'],'measurement_end_ms'=>$r['last']['monotonic_ms'],'runtime_identity'=>$r['first']['runtime_identity'],'device_identities'=>$devices,'evidence_bundle_hash'=>$r['head'],'measurements'=>$measurements];
    }
    public function archiveClosed(string $id,string $expectedHead): string
    {
        Policy::hex($expectedHead);$this->db->exec('BEGIN IMMEDIATE');try{$r=$this->load($id);if($r['state']!=='CLOSED'||$r['head']!==$expectedHead)throw new \InvalidArgumentException('Exact closed telemetry head required');
            $digest=$this->archive->put($r);$q=$this->db->prepare('INSERT OR IGNORE INTO archived VALUES(?,?)');$q->execute([$id,$digest]);$q=$this->db->prepare('DELETE FROM intervals WHERE id=?');$q->execute([$id]);$this->db->exec('COMMIT');return $digest;
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
}
