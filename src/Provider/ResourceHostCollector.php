<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Compute\Containerd\CgroupAccounting;
use Greenwood\Resources\Metering\{SignedObservation,NetworkAccounting,DeviceReservationCollector};

/** Protected host-side adapter. Runtime lookup comes from the existing provider inspector. */
final class ResourceHostCollector
{
    public function __construct(private readonly string $namespace,private readonly string $containerId,private readonly array $scope,
        private readonly \Closure $runtimeLookup,private readonly ?\Closure $networkLookup=null,private readonly array $devices=[])
    {SignedObservation::scope($scope);if(count($devices)>64)throw new \InvalidArgumentException('Bounded approved device list required');}
    public function observe(): array
    {
        $before=($this->runtimeLookup)($this->containerId);
        if(($before['state']??null)!=='RUNNING'||($before['workload_id']??null)!==$this->scope['workload_id']||($before['assignment_generation']??null)!==$this->scope['assignment_generation']||!is_int($before['pid']??null)||$before['pid']<1)throw new \RuntimeException('Current exact provider runtime required');
        $collector=CgroupAccounting::forTask($this->namespace,$this->containerId);$sample=$collector->observe();
        // PID and declared task must resolve to the same cgroup incarnation.
        if(CgroupAccounting::forPid($before['pid'])->observe()['runtime_identity']!==$sample['runtime_identity'])throw new \RuntimeException('PID/task cgroup mismatch');
        $boot=file_get_contents('/proc/sys/kernel/random/boot_id');if($boot===false)throw new \RuntimeException('Host boot identity unavailable');
        $sources=[];foreach($this->devices as $device){$observation=DeviceReservationCollector::observe($device,$before['pid']);if(isset($sources[$device['asset_id']]))throw new \RuntimeException('Duplicate device source');$sources[$device['asset_id']]=$observation;}
        if($this->networkLookup){$sample['network']=NetworkAccounting::observe(($this->networkLookup)($before),$before);}
        $after=($this->runtimeLookup)($this->containerId);
        foreach(['state','pid','workload_id','assignment_generation','image_digest','container_id'] as $field)if(($before[$field]??null)!==($after[$field]??null))throw new \RuntimeException('Runtime changed during collection');
        if($collector->observe()['runtime_identity']!==$sample['runtime_identity']||CgroupAccounting::forPid($before['pid'])->observe()['runtime_identity']!==$sample['runtime_identity'])throw new \RuntimeException('Runtime cgroup changed during collection');
        return ['boot_id'=>hash('sha256',trim($boot)),'sample'=>$sample,'reservation_sources'=>$sources,'runtime'=>['runtime_identity'=>$sample['runtime_identity'],'workload_id'=>$this->scope['workload_id'],'assignment_generation'=>$this->scope['assignment_generation'],'ready'=>true]];
    }
}
