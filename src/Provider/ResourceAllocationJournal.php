<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Economics\{Amount,CanonicalJson,Policy};
use Greenwood\Resources\{ResourceCatalog,ResourceManifest};
use Greenwood\Resources\Metering\SignedObservation;

/** Host-wide capacity accounting. All workloads on one host share this journal.
 * Inventory is operator-approved, not independently certified hardware truth.
 */
final class ResourceAllocationJournal
{
    private \PDO $db;
    public function __construct(string $directory, private readonly array $inventory)
    {
        if(!array_is_list($inventory)||count($inventory)>256)throw new \InvalidArgumentException('Bounded inventory required');
        $seen=[];
        foreach($inventory as $asset){
            if(!is_array($asset)||count($asset)!==6||array_diff(array_keys($asset),['asset_id','resource_id','capacity','exclusive','identity_hash','profile_hash']))throw new \InvalidArgumentException('Exact inventory fields required');
            foreach(['asset_id','identity_hash','profile_hash'] as $field)Policy::hex($asset[$field]);
            if($asset['profile_hash']===str_repeat('0',64)||$asset['identity_hash']===str_repeat('0',64)||!is_bool($asset['exclusive'])||Amount::integer($asset['capacity'])<1||ResourceCatalog::definition($asset['resource_id'])['measurement_type']!=='reserved')throw new \InvalidArgumentException('Qualified reservation inventory required');
            // One underlying asset must not be advertised under different billable aliases.
            if(isset($seen[$asset['asset_id']])||isset($seen[$asset['identity_hash']]))throw new \InvalidArgumentException('Duplicate physical asset or alias');
            $seen[$asset['asset_id']]=$seen[$asset['identity_hash']]=true;
        }
        if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new \RuntimeException('Allocation directory unavailable');
        if(realpath($directory)!==$directory||fileowner($directory)!==posix_geteuid()||(fileperms($directory)&0077))throw new \RuntimeException('Private canonical allocation directory required');
        $path=$directory.'/allocations.sqlite';foreach(['','-wal','-shm'] as $suffix)if(is_link($path.$suffix)||(file_exists($path.$suffix)&&(!is_file($path.$suffix)||fileowner($path.$suffix)!==posix_geteuid()||(fileperms($path.$suffix)&0077))))throw new \RuntimeException('Unsafe allocation database');
        $this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);chmod($path,0600);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS inventory(body TEXT NOT NULL); CREATE TABLE IF NOT EXISTS leases(id TEXT PRIMARY KEY,body TEXT NOT NULL,released INTEGER NOT NULL DEFAULT 0)');
        $this->db->exec('BEGIN IMMEDIATE');try{
            $old=$this->db->query('SELECT body FROM inventory')->fetchAll(\PDO::FETCH_COLUMN);$raw=CanonicalJson::encode($inventory);
            if(!$old){$q=$this->db->prepare('INSERT INTO inventory VALUES(?)');$q->execute([$raw]);}elseif($old!==[$raw])throw new \InvalidArgumentException('Inventory change requires explicit capacity migration');
            $this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function reserve(array $scope,array $manifest,string $runtime,string $bootId,int $start,int $end): array
    {
        SignedObservation::scope($scope);Policy::hex($runtime);Policy::hex($bootId);
        if(ResourceManifest::hash($manifest)!==$scope['manifest_hash']||$start<0||$end<=$start||$end-$start>Amount::integer($manifest['duration_ms']))throw new \InvalidArgumentException('Exact bounded allocation scope required');
        $assets=array_column($this->inventory,null,'asset_id');$leases=[];
        foreach($manifest['lines'] as $line){if(ResourceCatalog::definition($line['resource_id'])['measurement_type']!=='reserved')continue;
            $asset=$assets[$line['asset_id']]??throw new \InvalidArgumentException('Unapproved physical allocation');
            if($asset['resource_id']!==$line['resource_id']||Amount::integer($line['reserved_units'])<1||Amount::integer($line['reserved_units'])>Amount::integer($asset['capacity']))throw new \InvalidArgumentException('Reservation exceeds approved capacity');
            if(isset($leases[$line['asset_id']]))throw new \InvalidArgumentException('Duplicate reservation asset');
            $leases[$line['asset_id']]=['asset_id'=>$line['asset_id'],'resource_id'=>$line['resource_id'],'units'=>$line['reserved_units'],'workload_id'=>$scope['workload_id'],'assignment_generation'=>$scope['assignment_generation'],'runtime_identity'=>$runtime,'start_ms'=>(string)$start,'end_ms'=>(string)$end];
        }
        $body=['scope'=>$scope,'runtime_identity'=>$runtime,'boot_id'=>$bootId,'leases'=>array_values($leases)];$raw=CanonicalJson::encode($body);
        $this->db->exec('BEGIN IMMEDIATE');try{
            $q=$this->db->prepare('SELECT body,released FROM leases WHERE id=?');$q->execute([$scope['commitment']]);$old=$q->fetch(\PDO::FETCH_ASSOC);
            if($old){if($old['body']!==$raw||(int)$old['released']!==0)throw new \InvalidArgumentException('Commitment allocation cannot change or reopen');$this->db->exec('COMMIT');return $body;}
            if((int)$this->db->query('SELECT COUNT(*) FROM leases WHERE released=0')->fetchColumn()>=10000)throw new \RuntimeException('Allocation capacity requires retirement');
            $active=$this->db->query('SELECT body FROM leases WHERE released=0');$events=[];
            while(($r=$active->fetchColumn())!==false){$other=CanonicalJson::decode($r);
                // Boot change does not silently release old hardware reservations.
                if($other['boot_id']!==$bootId)throw new \RuntimeException('Reconcile prior boot allocations before admission');
                foreach($other['leases'] as $l){if(!isset($leases[$l['asset_id']]))continue;$a=max($start,Amount::integer($l['start_ms']));$b=min($end,Amount::integer($l['end_ms']));if($a>=$b)continue;
                    if($assets[$l['asset_id']]['exclusive'])throw new \RuntimeException('Exclusive device already allocated');
                    $events[$l['asset_id']][]=[$a,Amount::integer($l['units'])];$events[$l['asset_id']][]=[$b,-Amount::integer($l['units'])];
                }
            }
            foreach($leases as $id=>$l){$changes=$events[$id]??[];usort($changes,fn($a,$b)=>$a[0]<=>$b[0]?:$a[1]<=>$b[1]);$used=Amount::integer($l['units']);foreach($changes as [$time,$delta]){$used=$delta>=0?Amount::add($used,$delta):$used+$delta;if($used>Amount::integer($assets[$id]['capacity']))throw new \RuntimeException('Overlapping allocations exceed physical capacity');}}
            $q=$this->db->prepare('INSERT INTO leases(id,body) VALUES(?,?)');$q->execute([$scope['commitment'],$raw]);$this->db->exec('COMMIT');return $body;
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function leases(string $commitment,string $runtime,string $bootId): array
    {
        Policy::hex($commitment);$q=$this->db->prepare('SELECT body,released FROM leases WHERE id=?');$q->execute([$commitment]);$row=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$row||(int)$row['released']!==0)throw new \RuntimeException('Active allocation required');$r=CanonicalJson::decode($row['body']);
        if($r['runtime_identity']!==$runtime||$r['boot_id']!==$bootId)throw new \RuntimeException('Allocation runtime or boot changed');return $r['leases'];
    }
    /** Call only after the runtime/device has been observed detached. Tombstone remains. */
    public function release(string $commitment,string $runtime): void
    {
        Policy::hex($commitment);Policy::hex($runtime);$this->db->exec('BEGIN IMMEDIATE');try{
            $q=$this->db->prepare('SELECT body FROM leases WHERE id=?');$q->execute([$commitment]);$raw=$q->fetchColumn();if($raw===false||CanonicalJson::decode($raw)['runtime_identity']!==$runtime)throw new \InvalidArgumentException('Exact allocation retirement required');
            $q=$this->db->prepare('UPDATE leases SET released=1 WHERE id=?');$q->execute([$commitment]);$this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
}
