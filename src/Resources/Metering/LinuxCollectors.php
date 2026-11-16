<?php
declare(strict_types=1);
namespace Greenwood\Resources\Metering;
use Greenwood\Economics\Amount;
use Greenwood\Resources\ResourceCatalog;

/** No shell execution or hardware mutation. Unknown attribution is unavailable, never zero usage. */
final class LinuxCollectors
{
    public static function measurements(array $manifest,array $sample,array $bindings=[]): array
    {
        $result=[];
        foreach($manifest['lines'] as $line){$id=$line['resource_id'];ResourceCatalog::definition($id);$value=null;$reason=null;
            try{
                if($id==='compute.cpu.consumed')$value=Amount::integer($sample['cpu_ms']??null);
                elseif($id==='compute.cpu.reserved')$value=Amount::integer($sample['cpu_limit_millicores']??null);
                elseif($id==='memory.ram.reserved')$value=intdiv(Amount::integer($sample['memory_limit_bytes']??null),1048576);
                elseif(preg_match('/^storage\.(block|ssd|nvme|hdd)\.(read|write|iops)$/D',$id,$m)){
                    $binding=$bindings[$line['asset_id']]??[];
                    if(($binding['kind']??null)!==$m[1]||!is_string($binding['device']??null)||!preg_match('/^[0-9]+:[0-9]+$/D',$binding['device']))throw new \RuntimeException('Explicit reviewed block-device/class binding unavailable');
                    $counter=$sample['io_devices'][$binding['device']]??null;if(!is_array($counter))throw new \RuntimeException('Attributable device counter unavailable');
                    $value=match($m[2]){'read'=>Amount::integer($counter['rbytes']??null),'write'=>Amount::integer($counter['wbytes']??null),default=>Amount::add(Amount::integer($counter['rios']??null),Amount::integer($counter['wios']??null))};
                }elseif(in_array($id,['network.ingress','network.egress'],true)){
                    $network=$sample['network']??[];NetworkAccounting::delta($network,$network);
                    $value=Amount::integer($network[$id==='network.ingress'?'ingress_bytes':'egress_bytes']);
                }else $reason='Requires an attributed allocation or service-specific source adapter';
            }catch(\InvalidArgumentException|\RuntimeException $e){$reason=$e->getMessage();}
            $result[]=['line_id'=>$line['line_id'],'asset_id'=>$line['asset_id'],'value'=>(string)($value??0),'available'=>$value!==null,'unavailable_reason'=>$reason];
        }return $result;
    }
    /** Hardware presence is diagnostic only; no device count becomes a reservation. */
    public static function inventory(string $sys='/sys'): array
    {
        if(realpath($sys)!==$sys||!is_dir($sys))throw new \InvalidArgumentException('Canonical sysfs root required');
        $devices=[];
        foreach(['gpu'=>'class/drm/card[0-9]*/device','npu'=>'class/accel/accel[0-9]*/device'] as $kind=>$pattern){
            foreach(array_slice(glob($sys.'/'.$pattern)?:[],0,256) as $entry){$path=realpath($entry);if($path===false||!str_starts_with($path,$sys.'/'))continue;
                $read=static function($name)use($path){$raw=@file_get_contents($path.'/'.$name,false,null,0,257);return $raw===false||strlen($raw)>256?null:trim($raw);};
                $devices[]=['kind'=>$kind,'device_identity'=>hash('sha256',$path),'vendor'=>$read('vendor'),'device'=>$read('device'),'driver'=>($driver=realpath($path.'/driver'))===false?null:basename($driver),'payable'=>false,'workload_attribution'=>false];
            }
        }
        return ['version'=>1,'verification_level'=>'C','devices'=>$devices,'payable'=>false,'note'=>'Absence of a standard sysfs device does not prove absence of hardware. Vendor-specific sources require explicit workload attribution.'];
    }
}
