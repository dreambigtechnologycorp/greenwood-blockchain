<?php
declare(strict_types=1);
namespace Greenwood\Resources;
use Greenwood\Economics\{Amount,CanonicalJson,Policy};
use Greenwood\Compute\Protocol\ProviderId;

/** Canonical extension of the finalized V1 provider capability; self-advertisement is level C. */
final class ProviderCapabilityV2
{
    public static function validate(array $c): array {
        if(($c['version']??null)!==2||array_diff(array_keys($c),['version','genesis','provider_key','provider_id','base_nonce','nonce','region','availability_zone','assets'])
            ||!is_int($c['base_nonce']??null)||$c['base_nonce']<1||$c['base_nonce']>0xffffffff||!is_int($c['nonce']??null)||$c['nonce']<1||$c['nonce']>0xffffffff
            ||!is_string($c['region']??null)||!preg_match('/^[A-Za-z0-9._-]{1,64}$/D',$c['region'])||!is_array($c['assets']??null)||!array_is_list($c['assets'])||count($c['assets'])>64)throw new \InvalidArgumentException('Invalid resource capability extension');
        foreach(['genesis','provider_key','provider_id'] as $key)Policy::hex($c[$key]??null);
        if(bin2hex(ProviderId::fromPublicKey(hex2bin($c['provider_key']))->toBytes())!==$c['provider_id'])throw new \InvalidArgumentException('Capability provider mismatch');
        if(isset($c['availability_zone'])&&(!is_string($c['availability_zone'])||!preg_match('/^[A-Za-z0-9._-]{1,64}$/D',$c['availability_zone'])))throw new \InvalidArgumentException('Invalid advertised availability zone');
        $previous='';$partitions=[];foreach($c['assets'] as $asset){if(!is_array($asset))throw new \InvalidArgumentException('Invalid capability asset');
            foreach(['asset_id','billing_group','exclusivity_group'] as $key)Policy::hex($asset[$key]??null);
            if(strcmp($asset['asset_id'],$previous)<=0||array_diff(array_keys($asset),['asset_id','billing_group','exclusivity_group','group_capacity','capacity','capacity_class','vendor','model','runtime','partition_id','attributes','resources','meter_versions'])
                ||Amount::integer($asset['capacity']??null)<1||Amount::integer($asset['group_capacity']??null)<Amount::integer($asset['capacity'])||!is_string($asset['capacity_class']??null)||!preg_match('/^[A-Za-z0-9._-]{1,64}$/D',$asset['capacity_class'])
                ||!is_string($asset['vendor']??null)||strlen($asset['vendor'])<1||strlen($asset['vendor'])>64||!is_string($asset['model']??null)||strlen($asset['model'])<1||strlen($asset['model'])>128
                ||!is_string($asset['runtime']??null)||!preg_match('/^[A-Za-z0-9._-]{1,64}$/D',$asset['runtime'])||!is_string($asset['partition_id']??null)||!preg_match('/^[A-Za-z0-9._-]{1,128}$/D',$asset['partition_id'])
                ||!is_array($asset['resources']??null)||!array_is_list($asset['resources'])||!$asset['resources']||count($asset['resources'])>32
                ||!is_array($asset['meter_versions']??null)||!array_is_list($asset['meter_versions'])||count($asset['meter_versions'])>16)throw new \InvalidArgumentException('Invalid resource asset');
            $unit=null;$last='';foreach($asset['resources'] as $resource){$d=ResourceCatalog::definition($resource);if(strcmp($resource,$last)<=0||($unit!==null&&$unit!==$d['capacity_unit']))throw new \InvalidArgumentException('Ambiguous asset capacity units');$unit=$d['capacity_unit'];$last=$resource;}
            $last='';foreach($asset['meter_versions'] as $profile){if(!is_string($profile)||!preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$profile)||strcmp($profile,$last)<=0)throw new \InvalidArgumentException('Invalid meter profile declaration');$last=$profile;}
            if(isset($asset['attributes'])&&!is_array($asset['attributes']))throw new \InvalidArgumentException('Invalid hardware attributes');
            self::attributes($asset['attributes']??[]);
            foreach($asset['resources'] as $resource)if(isset($asset['attributes']['storage'])){$medium=$asset['attributes']['storage']['medium'];foreach(['nvme'=>['nvme'],'ssd'=>['ssd','nvme'],'hdd'=>['hdd']] as $kind=>$allowed)if(str_starts_with($resource,'storage.'.$kind.'.')&&!in_array($medium,$allowed,true))throw new \InvalidArgumentException('Storage kind contradicts declared medium');}
            if(in_array('compute.gpu.vram',$asset['resources'],true)){if(($asset['attributes']['accelerator']['memory_model']??null)!=='device_local'||Amount::integer($asset['group_capacity'])>Amount::integer($asset['attributes']['accelerator']['memory_mib']??null))throw new \InvalidArgumentException('VRAM reservation exceeds declared device-local memory');}
            $partition=$asset['exclusivity_group'].':'.$unit.':'.$asset['partition_id'];if(isset($partitions[$partition]))throw new \InvalidArgumentException('Duplicate physical partition identity');$partitions[$partition]=true;
            $previous=$asset['asset_id'];
        }
        self::groupCapacities($c);
        if(strlen(CanonicalJson::encode($c))>32768)throw new \InvalidArgumentException('Capability extension too large');return $c;
    }
    private static function attributes(array $a): void {
        if(array_diff(array_keys($a),['storage','accelerator','network']))throw new \InvalidArgumentException('Unknown typed hardware attributes');
        if(isset($a['storage'])){$s=$a['storage'];if(!is_array($s)||count($s)!==4||array_diff(array_keys($s),['medium','persistence','durability','protocols'])
            ||!in_array($s['medium']??null,['ssd','nvme','hdd','nas','object','block','snapshot'],true)||!in_array($s['persistence']??null,['ephemeral','persistent'],true)||!in_array($s['durability']??null,['no_claim','single_copy','replicated'],true)
            ||!is_array($s['protocols']??null)||!array_is_list($s['protocols'])||!$s['protocols']||count($s['protocols'])>8)throw new \InvalidArgumentException('Invalid advertised storage attributes');
            $last='';foreach($s['protocols'] as $protocol){if(!in_array($protocol,['iscsi','nfs3','nfs4','nvmeof','posix','s3','smb3'],true)||strcmp($protocol,$last)<=0)throw new \InvalidArgumentException('Invalid storage protocol');$last=$protocol;}}
        if(isset($a['accelerator'])){$g=$a['accelerator'];if(!is_array($g)||count($g)!==3||array_diff(array_keys($g),['memory_model','memory_mib','partitioning'])||!in_array($g['memory_model']??null,['device_local','shared_system'],true)
            ||!in_array($g['partitioning']??null,['exclusive','partitioned','shared'],true)||($g['memory_model']==='device_local'?Amount::integer($g['memory_mib']??null)<1:($g['memory_mib']??null)!==null))throw new \InvalidArgumentException('Invalid accelerator memory/partition advertisement');}
        if(isset($a['network'])){$n=$a['network'];if(!is_array($n)||count($n)!==3||array_diff(array_keys($n),['connectivity_class','bandwidth_kbps','transfer_limit_bytes'])||!in_array($n['connectivity_class']??null,['private_lan','public_internet','interconnect','unknown'],true)||Amount::integer($n['bandwidth_kbps']??null)<1||Amount::integer($n['transfer_limit_bytes']??null)<1)throw new \InvalidArgumentException('Invalid advertised network class');}
    }
    public static function validateBase(array $c,\Greenwood\Compute\Protocol\ProviderCapability $base): void {
        $groups=[];foreach($c['assets'] as $asset)foreach($asset['resources'] as $id){$domain=ResourceCatalog::definition($id)['domain'];if(in_array($domain,['cpu','ram'],true))$groups[$domain][$asset['exclusivity_group']]=Amount::integer($asset['group_capacity']);}
        foreach(['cpu'=>$base->cpuCapacityMillis,'ram'=>intdiv($base->memoryCapacityBytes,1048576)] as $domain=>$limit){$sum=0;foreach($groups[$domain]??[] as $capacity)$sum=Amount::add($sum,$capacity);if($sum>$limit)throw new \InvalidArgumentException('Resource extension exceeds frozen base capability');}
    }
    public static function groupCapacities(array $c): array {
        $groups=[];foreach($c['assets'] as $a){$unit=ResourceCatalog::definition($a['resources'][0])['capacity_unit'];$key=$a['exclusivity_group'].':'.$unit;$capacity=Amount::integer($a['group_capacity']);
            if(isset($groups[$key])&&$groups[$key]!==$capacity)throw new \InvalidArgumentException('Conflicting shared/partition capacity');$groups[$key]=$capacity;}
        ksort($groups,SORT_STRING);return $groups;
    }
    public static function hash(array $c): string {return bin2hex(\Greenwood\Crypto\ProtocolHash::hash('GREENWOOD_PROVIDER_RESOURCES_V2',CanonicalJson::encode(self::validate($c))));}
    public static function assets(array $c): array {$out=[];foreach(self::validate($c)['assets'] as $a)$out[$a['asset_id']]=$a;return $out;}
}
