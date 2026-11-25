<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Economics\{Amount,CanonicalJson,Policy};
use Greenwood\Resources\Metering\SignedObservation;

/** Cooperating NAS/object/backup gateways record completed workload operations.
 * Persist intent before performing I/O. An unfinished operation prevents evidence export.
 */
final class ServiceOperationMeter
{
    private \PDO $db;
    private JournalArchive $archive;
    private const RESOURCES=['storage.nas.operations','storage.nas.transfer','storage.object.requests','storage.object.egress','backup.snapshot.restore'];
    public function __construct(string $directory,array $scope)
    {
        SignedObservation::scope($scope);
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new \RuntimeException('Operation journal unavailable');
        if(realpath($directory)!==$directory||is_link($directory)||fileowner($directory)!==posix_geteuid()||(fileperms($directory)&0077)!==0)throw new \RuntimeException('Private operation journal required');
        $path=$directory.'/operations.sqlite';foreach(['','-journal','-wal','-shm'] as $suffix)if(is_link($path.$suffix)||(file_exists($path.$suffix)&&(!is_file($path.$suffix)||fileowner($path.$suffix)!==posix_geteuid()||(fileperms($path.$suffix)&0077)!==0)))throw new \RuntimeException('Unsafe operation journal');
        $this->archive=new JournalArchive($directory.'/archive');$this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);chmod($path,0600);
        \Greenwood\Persistence\SqliteDurability::configure($this->db, 1000);
        $this->db->exec('CREATE TABLE IF NOT EXISTS identity(scope TEXT NOT NULL,incarnation TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS operations(id TEXT PRIMARY KEY,state TEXT NOT NULL,digest TEXT)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS requests(id TEXT PRIMARY KEY,hash TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS counters(asset TEXT NOT NULL,resource TEXT NOT NULL,quantity TEXT NOT NULL,PRIMARY KEY(asset,resource))');
        $this->db->exec('BEGIN IMMEDIATE');try{$rows=$this->db->query('SELECT scope FROM identity')->fetchAll(\PDO::FETCH_COLUMN);$raw=CanonicalJson::encode($scope);
            if(!$rows){$q=$this->db->prepare('INSERT INTO identity VALUES(?,?)');$q->execute([$raw,bin2hex(random_bytes(32))]);}
            elseif($rows!==[$raw])throw new \InvalidArgumentException('Operation journal scope cannot change');$this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function begin(string $eventId,?string $requestHash=null): string
    {
        Policy::hex($eventId);if($requestHash!==null)Policy::hex($requestHash);$this->db->exec('BEGIN IMMEDIATE');try{
            $bound=$this->db->prepare('SELECT hash FROM requests WHERE id=?');$bound->execute([$eventId]);$existing=$bound->fetchColumn();
            if($existing!==false&&$existing!==$requestHash)throw new \InvalidArgumentException('Operation request changed');
            if($requestHash!==null&&$existing===false){$bound=$this->db->prepare('INSERT INTO requests VALUES(?,?)');$bound->execute([$eventId,$requestHash]);}
            $q=$this->db->prepare('SELECT state FROM operations WHERE id=?');$q->execute([$eventId]);$state=$q->fetchColumn();
            if($state===false){if((int)$this->db->query("SELECT COUNT(*) FROM operations WHERE state='PENDING'")->fetchColumn()>=10000)throw new \RuntimeException('Reconcile unfinished operations before accepting more work');$q=$this->db->prepare("INSERT INTO operations VALUES(?,'PENDING',NULL)");$q->execute([$eventId]);$state='NEW';}
            $this->db->exec('COMMIT');return $state;
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function complete(string $eventId,array $quantities): string
    {
        Policy::hex($eventId);if(!array_is_list($quantities)||count($quantities)>32)throw new \InvalidArgumentException('Bounded operation counters required');
        $last='';foreach($quantities as $row){if(!is_array($row)||count($row)!==3||array_diff(array_keys($row),['asset_id','resource_id','quantity'])||!in_array($row['resource_id']??null,self::RESOURCES,true))throw new \InvalidArgumentException('Unsupported service operation meter');Policy::hex($row['asset_id']);Amount::integer($row['quantity']??null);$key=$row['asset_id'].':'.$row['resource_id'];if(strcmp($key,$last)<=0)throw new \InvalidArgumentException('Sorted unique operation counters required');$last=$key;}
        $this->db->exec('BEGIN IMMEDIATE');try{$q=$this->db->prepare('SELECT state,digest FROM operations WHERE id=?');$q->execute([$eventId]);$old=$q->fetch(\PDO::FETCH_ASSOC);if(!$old)throw new \InvalidArgumentException('Durable operation intent required');
            $identity=$this->db->query('SELECT * FROM identity')->fetch(\PDO::FETCH_ASSOC);$body=['version'=>1,'event_id'=>$eventId,'scope_hash'=>hash('sha256',$identity['scope']),'incarnation'=>$identity['incarnation'],'quantities'=>$quantities];$expected=hash('sha256',CanonicalJson::encode($body));
            if($old['state']==='COMPLETE'){if($old['digest']!==$expected||CanonicalJson::encode($this->archive->get($expected))!==CanonicalJson::encode($body))throw new \InvalidArgumentException('Conflicting repeated operation completion');$this->db->exec('COMMIT');return $expected;}
            $digest=$this->archive->put($body);
            foreach($quantities as $row){$q=$this->db->prepare('SELECT quantity FROM counters WHERE asset=? AND resource=?');$q->execute([$row['asset_id'],$row['resource_id']]);$prior=$q->fetchColumn();$total=Amount::add($prior===false?0:Amount::integer($prior),Amount::integer($row['quantity']));$q=$this->db->prepare('INSERT OR REPLACE INTO counters VALUES(?,?,?)');$q->execute([$row['asset_id'],$row['resource_id'],(string)$total]);}
            $q=$this->db->prepare("UPDATE operations SET state='COMPLETE',digest=? WHERE id=?");$q->execute([$digest,$eventId]);$this->db->exec('COMMIT');return $digest;
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function status(string $eventId): array
    {
        Policy::hex($eventId);$q=$this->db->prepare('SELECT state,digest FROM operations WHERE id=?');$q->execute([$eventId]);$row=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$row)throw new \InvalidArgumentException('Unknown operation');
        return ['state'=>$row['state'],'receipt_hash'=>$row['digest'],'receipt'=>$row['digest']===null?null:$this->archive->get($row['digest'])];
    }
    public function snapshot(array $manifest): array
    {
        $this->db->exec('BEGIN');try{
            if((int)$this->db->query("SELECT COUNT(*) FROM operations WHERE state='PENDING'")->fetchColumn()>0)throw new \RuntimeException('Unfinished operation requires reconciliation; usage is unverified');
            $identity=$this->db->query('SELECT * FROM identity')->fetch(\PDO::FETCH_ASSOC);
            $out=[];foreach($manifest['lines'] as $line){if(!in_array($line['resource_id'],self::RESOURCES,true))continue;$q=$this->db->prepare('SELECT quantity FROM counters WHERE asset=? AND resource=?');$q->execute([$line['asset_id'],$line['resource_id']]);$value=$q->fetchColumn();$out[]=['line_id'=>$line['line_id'],'asset_id'=>$line['asset_id'],'value'=>$value===false?'0':$value,'available'=>true];}
            $this->db->exec('COMMIT');return ['source_incarnation'=>$identity['incarnation'],'scope_hash'=>hash('sha256',$identity['scope']),'measurements'=>$out,'payable'=>false,'verification_level'=>'C'];
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
}
