<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Crypto\Ed25519KeyPair;
use Greenwood\Economics\{Amount,CanonicalJson,Policy};
use Greenwood\Resources\Metering\{AllocationAccounting,LinuxCollectors,TelemetryFrame};

/** Compose actual host, allocator and service observations into signed durable frames.
 * Caller supplies existing finalized funding checks; this class never authorizes work.
 */
final class ResourceCollectionSession
{
    public function __construct(private readonly ResourceTelemetryJournal $journal,private readonly Ed25519KeyPair $key,
        private readonly array $scope,private readonly array $manifest,private readonly int $maximumGapMs,
        private readonly \Closure $observe,private readonly \Closure $authorize,
        private readonly ?ResourceAllocationJournal $allocations=null,private readonly array $storage=[],private readonly array $blockBindings=[])
    {
        foreach($storage as $adapter)if(!$adapter instanceof MeteredStorageAdapter)throw new \InvalidArgumentException('Concrete storage adapter required');
    }
    /** Bind the collector to the same live guard used by hosted start/recovery. */
    public static function forHosted(ResourceTelemetryJournal $journal,Ed25519KeyPair $key,\Greenwood\Economics\HostedFundingGuard $guard,array $document,
        ResourceHostCollector $host,int $maximumGapMs,?ResourceAllocationJournal $allocations=null,array $storage=[],array $blockBindings=[]): self
    {
        $funding=$guard->require($document);$scope=$funding['meter_scope']??throw new \RuntimeException('Typed funded scope required');
        $manifest=$funding['resource_manifest']??throw new \RuntimeException('Typed funded manifest required');
        $authorize=static function(array $accepted)use($guard,$document,$manifest):bool{$current=$guard->require($document);return CanonicalJson::encode($current['meter_scope']??[])===CanonicalJson::encode($accepted)&&CanonicalJson::encode($current['resource_manifest']??[])===CanonicalJson::encode($manifest);};
        return new self($journal,$key,$scope,$manifest,$maximumGapMs,$host->observe(...),$authorize,$allocations,$storage,$blockBindings);
    }
    /** One-shot collection is safe to schedule; concurrent sequence conflicts fail closed. */
    public function sample(bool $close=false): array
    {
        if(($this->authorize)($this->scope)!==true)throw new \RuntimeException('Current finalized funding authorization unavailable');
        $cursor=$this->journal->resume($this->scope,$this->manifest,bin2hex($this->key->getPublicKey()),$this->maximumGapMs);
        if($cursor['state']!=='OPEN')throw new \RuntimeException('Closed interval cannot collect again');
        $observation=($this->observe)();$host=$observation['sample']??throw new \RuntimeException('Host sample required');
        $runtime=$observation['runtime']??throw new \RuntimeException('Observed runtime required');$boot=$observation['boot_id']??null;Policy::hex($boot);
        $now=Amount::integer($host['monotonic_ms']??null);
        if($runtime['runtime_identity']!==$host['runtime_identity']||$runtime['workload_id']!==$this->scope['workload_id']||$runtime['assignment_generation']!==$this->scope['assignment_generation']||($runtime['ready']??null)!==true)throw new \RuntimeException('Exact live funded runtime required');
        $values=[];$sources=['boot_id'=>$boot,'runtime_identity'=>$runtime['runtime_identity'],'block_bindings'=>$this->blockBindings];
        $add=static function(array $rows)use(&$values):void{foreach($rows as $row){if(!$row['available'])continue;if(isset($values[$row['line_id']]))throw new \RuntimeException('Multiple sources claim same resource line');unset($row['unavailable_reason']);$values[$row['line_id']]=$row;}};
        $linux=LinuxCollectors::measurements($this->manifest,$host,$this->blockBindings);
        // Reservations come from the host-wide allocator, not merely a cgroup ceiling.
        $add(array_values(array_filter($linux,fn($r)=>!in_array($r['line_id'],array_column(array_filter($this->manifest['lines'],fn($l)=>\Greenwood\Resources\ResourceCatalog::definition($l['resource_id'])['measurement_type']==='reserved'),'line_id'),true))));
        if(isset($host['network']))$sources['network_identity']=$host['network']['network_identity'];
        if($this->allocations){$leases=$this->allocations->leases($this->scope['commitment'],$runtime['runtime_identity'],$boot);$reserved=AllocationAccounting::measurements($this->manifest,$this->scope,$leases,$runtime,$now);
            $lines=array_column($this->manifest['lines'],null,'line_id');
            foreach($reserved as &$row){$resource=$lines[$row['line_id']]['resource_id'];
                if(in_array($resource,['compute.gpu.reserved','compute.npu.reserved','compute.accelerator.reserved','compute.gpu.vram'],true)){$device=$observation['reservation_sources'][$row['asset_id']]??[];$row['available']=$row['available']&&($device['available']??false)===true;if($row['available'])$sources['devices'][$row['asset_id']]=$device['source_identity'];}
                elseif($resource==='compute.cpu.reserved')$row['available']=$row['available']&&isset($host['cpu_limit_millicores'])&&Amount::integer($host['cpu_limit_millicores'])>=Amount::integer($row['value']);
                elseif($resource==='memory.ram.reserved')$row['available']=$row['available']&&isset($host['memory_limit_bytes'])&&intdiv(Amount::integer($host['memory_limit_bytes']),1048576)>=Amount::integer($row['value']);
            }unset($row);$add($reserved);}
        foreach($this->storage as $adapter){$snapshot=$adapter->snapshot($this->manifest);if($snapshot['scope_hash']!==hash('sha256',CanonicalJson::encode($this->scope)))throw new \RuntimeException('Storage service belongs to another funded scope');
            $sources['storage'][]=['incarnation'=>$snapshot['source_incarnation'],'identity'=>$snapshot['storage']['root_identity'],'asset'=>$snapshot['storage']['asset_id']];$add($snapshot['measurements']);}
        $measurements=[];foreach($this->manifest['lines'] as $line)$measurements[]=$values[$line['line_id']]??['line_id'=>$line['line_id'],'asset_id'=>$line['asset_id'],'value'=>'0','available'=>false];
        $frame=['version'=>1,'scope_hash'=>hash('sha256',CanonicalJson::encode($this->scope)),'runtime_identity'=>$runtime['runtime_identity'],'source_incarnation'=>hash('sha256',CanonicalJson::encode($sources)),'sequence'=>$cursor['sequence'],'monotonic_ms'=>(string)$now,'measurements'=>$measurements];
        return $this->journal->append($this->scope['commitment'],TelemetryFrame::sign($frame,$this->key),$close);
    }
}
