<?php
declare(strict_types=1);
namespace Greenwood\Resources;
use Greenwood\Economics\{CanonicalJson,Policy};
use Greenwood\Crypto\ProtocolHash;

/** Stable atom definitions. Display GB/month or GiB/hour conversions never enter consensus. */
final class ResourceCatalog
{
    public static function definitions(): array {
        static $definitions=null;if($definitions!==null)return $definitions;
        $d=[];
        $add=static function($id,$unit,$mode,$domain,$instant,$meter)use(&$d){$d[$id]=['unit'=>$unit,'measurement_type'=>$mode,'domain'=>$domain,'capacity_unit'=>$instant,'meter_version'=>$meter];};
        $add('compute.cpu.reserved','millicore_millisecond','reserved','cpu','millicore','allocation.interval.v1');
        $add('compute.cpu.consumed','millisecond','consumed','cpu','millicore','cgroup.cpu.v1');
        foreach(['accelerator','gpu','npu'] as $kind)$add('compute.'.$kind.'.reserved','millidevice_millisecond','reserved',$kind,'millidevice','allocation.interval.v1');
        $add('compute.gpu.vram','MiB_millisecond','reserved','gpu_memory','MiB','allocation.interval.v1');
        $add('memory.ram.reserved','MiB_millisecond','reserved','ram','MiB','allocation.interval.v1');
        foreach(['ssd','nvme','hdd','block','nas','object'] as $kind){
            $add('storage.'.$kind.'.capacity','MiB_millisecond','reserved','storage','MiB','allocation.interval.v1');
            if(in_array($kind,['ssd','nvme','hdd','block'],true)){
                $add('storage.'.$kind.'.read','byte','consumed','storage_read','MiB','cgroup.io.v1');
                $add('storage.'.$kind.'.write','byte','consumed','storage_write','MiB','cgroup.io.v1');
                $add('storage.'.$kind.'.iops','operation','consumed','storage_operations','MiB','cgroup.io.v1');
            }
        }
        $add('storage.nas.operations','operation','consumed','storage_operations','MiB','nas.workload.v1');
        $add('storage.nas.transfer','byte','consumed','network','kbps','nas.workload.v1');
        $add('storage.object.requests','request','consumed','storage_operations','MiB','object.workload.v1');
        $add('storage.object.egress','byte','consumed','network_egress','kbps','object.workload.v1');
        foreach(['ingress','egress'] as $direction)$add('network.'.$direction,'byte','consumed','network_'.$direction,'kbps','network.workload.v1');
        $add('network.bandwidth.reserved','kbps_millisecond','reserved','network','kbps','allocation.interval.v1');
        $add('network.public_ip.reserved','address_millisecond','reserved','ip','address','allocation.interval.v1');
        $add('infrastructure.node.reserved','node_millisecond','reserved','node','node','allocation.interval.v1');
        $add('backup.snapshot.capacity','MiB_millisecond','reserved','backup','MiB','allocation.interval.v1');
        $add('backup.snapshot.restore','operation','consumed','backup_operations','MiB','backup.workload.v1');
        ksort($d,SORT_STRING);$definitions=$d;return $d;
    }
    public static function definition(mixed $id): array { if(!is_string($id))throw new \InvalidArgumentException('Invalid resource identifier');return self::definitions()[$id]??throw new \InvalidArgumentException('Undefined resource'); }
    public static function profileSupported(string $profile): bool { return in_array($profile,['cgroup.cpu.v1','cgroup.io.v1','allocation.interval.v1'],true); }
    public static function validate(array $catalog): array {
        if(($catalog['version']??null)!==2||array_diff(array_keys($catalog),['version','entries'])||!is_array($catalog['entries']??null)||!array_is_list($catalog['entries'])||!$catalog['entries']||count($catalog['entries'])>64)throw new \InvalidArgumentException('Invalid resource catalog');
        $previous='';foreach($catalog['entries'] as $entry){if(!is_array($entry))throw new \InvalidArgumentException('Invalid catalog entry');
            $id=$entry['resource_id']??'';$def=self::definition($id);
            if(strcmp($id,$previous)<=0||array_diff(array_keys($entry),['resource_id','unit','measurement_type','meter_version','enabled','verification_level','qualification_hash'])
                ||($entry['unit']??null)!==$def['unit']||($entry['measurement_type']??null)!==$def['measurement_type']||($entry['meter_version']??null)!==$def['meter_version']
                ||!is_bool($entry['enabled']??null)||!in_array($entry['verification_level']??null,['A','B','C'],true))throw new \InvalidArgumentException('Noncanonical/incorrect resource definition');
            $previous=$id;Policy::hex($entry['qualification_hash']??null);
            if($entry['enabled']&&(!self::profileSupported($entry['meter_version'])||$entry['qualification_hash']===str_repeat('0',64)))throw new \InvalidArgumentException('Unsupported or unqualified payable meter');
        }return $catalog;
    }
    public static function hash(array $catalog): string {return bin2hex(ProtocolHash::hash('GREENWOOD_RESOURCE_CATALOG_V2',CanonicalJson::encode(self::validate($catalog))));}
    public static function entries(array $catalog): array { $out=[];foreach(self::validate($catalog)['entries'] as $e)$out[$e['resource_id']]=$e;return $out; }
    public static function disabled(): array {
        $entries=[];foreach(self::definitions() as $id=>$d)$entries[]=['resource_id'=>$id,'unit'=>$d['unit'],'measurement_type'=>$d['measurement_type'],'meter_version'=>$d['meter_version'],'enabled'=>false,'verification_level'=>'C','qualification_hash'=>str_repeat('0',64)];return ['version'=>2,'entries'=>$entries];
    }
}
