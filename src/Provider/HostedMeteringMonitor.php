<?php
declare(strict_types=1);
namespace Greenwood\Provider;

/** Existing finalized funding + application registry -> local diagnostic meter journal.
 * No keys, remote telemetry origins, claims, or financial relay are accepted here.
 */
final class HostedMeteringMonitor
{
    private ?int $lastSample=null;
    public function __construct(private readonly MeterJournal $meter,private readonly \Closure $active,private readonly \Closure $funding,
        private readonly \Closure $observe,private readonly int $sampleMs=15000,private readonly ?\Closure $clock=null)
    {if($sampleMs<1000||$sampleMs>60000)throw new \InvalidArgumentException('Bounded metering cadence required');}
    public static function forProvider(MeterJournal $meter,ProviderApplicationRegistry $applications,\Closure $funding,int $sampleMs=15000,?ProviderApplicationNetwork $network=null): self
    {return new self($meter,$applications->active(...),$funding,static function(string $id,array $runtime)use($network):array{
        $sample=MeteringService::observeTask('greenwood',$id);
        if($network!==null){try{$binding=$network->find((int)$runtime['application_id'],$runtime['service'])??throw new \RuntimeException('Network binding missing');$sample['network']=\Greenwood\Resources\Metering\NetworkAccounting::observe($binding,$runtime);}
            catch(\Throwable){$sample['network']=null;$sample['network_status']='UNVERIFIED';}}
        return $sample;
    },$sampleMs);}
    public function tick(): array
    {
        $now=$this->clock?($this->clock)():intdiv(hrtime(true),1000000);
        if(!is_int($now)||$now<0||$this->lastSample!==null&&$now<$this->lastSample)throw new \RuntimeException('Meter monotonic clock reset');
        if($this->lastSample!==null&&$now-$this->lastSample<$this->sampleMs)return [];$this->lastSample=$now;$results=[];
        foreach(($this->active)() as $r){if($r['state']!=='RUNNING')continue;
            try{
                $funding=($this->funding)((int)$r['application_id'],$r['service'],$r['image_ref']);$scope=$funding['meter_scope']??throw new \RuntimeException('Finalized scope unavailable');
                // Bind before reading host counters. The receiver/funding guard already checked exact assignment and image.
                $this->meter->bind($scope);$sample=($this->observe)($r['container_id'],$r);$id=$scope['commitment'];
                foreach($this->meter->openSegments($sample['runtime_identity']) as $open)if($open['commitment']!==$id){
                    $prior=$open['scope'];if(!$prior)throw new \RuntimeException('Unbound old runtime requires explicit reconciliation');
                    foreach(['chain','genesis','provider_id','workload_id'] as $field)if($prior[$field]!==$scope[$field])throw new \RuntimeException('Runtime cannot cross tenant/provider/chain scope');
                    if($prior['interval_end_height']>$scope['interval_start_height'])throw new \RuntimeException('Overlapping metering intervals denied');
                    // A poll crossing a finalized interval boundary does NOT establish the exact CPU split.
                    $this->meter->closeBoundary($open['commitment'],$sample);
                }
                if($this->meter->segmentState($id,$sample['runtime_identity'])===null)$this->meter->begin($id,$sample);else $this->meter->checkpoint($id,$sample);
                $results[]=['application_id'=>(int)$r['application_id'],'service'=>$r['service'],'commitment'=>$id,'status'=>'OBSERVED_PROVIDER_ORIGIN','payable'=>false];
            }catch(\Throwable){$results[]=['application_id'=>(int)$r['application_id'],'service'=>$r['service'],'status'=>'UNVERIFIED','reason'=>'FUNDING_OR_TELEMETRY_UNAVAILABLE','payable'=>false];}
        }return $results;
    }
    /** Explicit pre-stop sampling. A vanished cgroup leaves OPEN; never invent a final sample. */
    public function finish(string $commitment,string $containerId): array
    {$this->meter->finish($commitment,($this->observe)($containerId));return $this->meter->bundle($commitment);}
}
