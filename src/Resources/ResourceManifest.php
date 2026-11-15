<?php
declare(strict_types=1);
namespace Greenwood\Resources;
use Greenwood\Economics\{Amount,CanonicalJson,Policy};
use Greenwood\Crypto\ProtocolHash;

final class ResourceManifest
{
    public static function validate(array $m,array $catalog,array $capability,int $subsidyPercent): array {
        if(($m['version']??null)!==1||($m['rounding_policy']??null)!=='ceil_line_gru_then_ceil_gwd_v1'||array_diff(array_keys($m),['version','rounding_policy','duration_ms','lines'])
            ||Amount::integer($m['duration_ms']??null)<1||Amount::integer($m['duration_ms'])>2678400000||!is_array($m['lines']??null)||!array_is_list($m['lines'])||!$m['lines']||count($m['lines'])>32)throw new \InvalidArgumentException('Invalid bounded resource manifest');
        $entries=ResourceCatalog::entries($catalog);$assets=ProviderCapabilityV2::assets($capability);$seen=[];$covered=[];$last='';$cpuLines=0;
        foreach($m['lines'] as $line){
            if(!is_array($line)||!is_string($line['resource_id']??null)||!is_string($line['asset_id']??null))throw new \InvalidArgumentException('Malformed resource line');
            $id=$line['line_id']??'';$resource=$line['resource_id']??'';$entry=$entries[$resource]??null;$asset=$assets[$line['asset_id']??'']??null;$def=ResourceCatalog::definition($resource);
            if(!is_string($id)||!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$id)||strcmp($id,$last)<=0||array_diff(array_keys($line),['line_id','resource_id','asset_id','capacity_class','meter_version','reserved_units','maximum_quantity','rate_num','rate_den','coverage_domains','included_quantity','overage_of'])
                ||!$entry||!$entry['enabled']||!$asset||!in_array($resource,$asset['resources'],true)||($line['capacity_class']??null)!==$asset['capacity_class']
                ||($line['meter_version']??null)!==$entry['meter_version']||!in_array($entry['meter_version'],$asset['meter_versions'],true)
                ||($subsidyPercent>0&&$entry['verification_level']==='C'))throw new \InvalidArgumentException('Unsupported resource/class/meter/subsidy trust policy');
            if((str_starts_with($resource,'compute.gpu.')||str_starts_with($resource,'compute.npu.')||str_starts_with($resource,'compute.accelerator.'))&&!isset($asset['attributes']['accelerator']))throw new \InvalidArgumentException('Accelerator memory/partition/runtime metadata is unqualified');
            if(str_starts_with($resource,'storage.')&&!isset($asset['attributes']['storage']))throw new \InvalidArgumentException('Storage persistence/durability/protocol metadata required');
            if(str_starts_with($resource,'network.')&&!isset($asset['attributes']['network']))throw new \InvalidArgumentException('Network capacity class must be explicit');
            if($resource==='compute.cpu.consumed'&&++$cpuLines>1)throw new \InvalidArgumentException('CPU receipt has one attributable aggregate counter');
            $last=$id;$units=Amount::integer($line['reserved_units']??null);$maximum=Amount::integer($line['maximum_quantity']??null);$num=Amount::integer($line['rate_num']??null);$den=Amount::integer($line['rate_den']??null);$included=Amount::integer($line['included_quantity']??null);
            if($units<1||$units>Amount::integer($asset['capacity'])||$maximum<1||$den<1||$included>$maximum||!array_key_exists('overage_of',$line)
                ||!is_array($line['coverage_domains']??null)||!array_is_list($line['coverage_domains'])||!$line['coverage_domains']||count($line['coverage_domains'])>16)throw new \InvalidArgumentException('Invalid resource ceilings/rate');
            if($def['measurement_type']==='reserved'&&$maximum!==Amount::multiply($units,Amount::integer($m['duration_ms'])))throw new \InvalidArgumentException('Reservation integral differs from authorized allocation');
            $domains=$line['coverage_domains'];$sorted=$domains;sort($sorted,SORT_STRING);
            if($domains!==array_values(array_unique($sorted))||!in_array($def['domain'],$domains,true))throw new \InvalidArgumentException('Explicit resource coverage required');
            $billingDomains=$domains;if(in_array('accelerator',$domains,true))$billingDomains=array_values(array_unique(array_merge($billingDomains,['gpu','npu'])));if(in_array('network',$domains,true))$billingDomains=array_values(array_unique(array_merge($billingDomains,['network_ingress','network_egress'])));
            foreach($billingDomains as $domain){if(!in_array($domain,['cpu','ram','accelerator','gpu','gpu_memory','npu','storage','storage_read','storage_write','storage_operations','network','network_ingress','network_egress','ip','node','backup','backup_operations'],true))throw new \InvalidArgumentException('Unknown billing coverage');
                $scope=$asset['billing_group'].':'.$domain;
                if($num>0&&isset($covered[$scope])){
                    $parent=$line['overage_of'];$previous=is_string($parent)?($seen[$parent]??null):null;
                    if(!is_string($parent)||!$previous||$covered[$scope]!==$parent||$included<1||$def['measurement_type']!=='consumed')throw new \InvalidArgumentException('Duplicate charge for bundled/backing capacity');
                    $covered[$scope]=$id;
                }elseif($num>0)$covered[$scope]=$id;
            }
            if($line['overage_of']!==null && (!is_string($line['overage_of'])||!isset($seen[$line['overage_of']])||$included<1))throw new \InvalidArgumentException('Invalid disclosed overage allowance');
            $seen[$id]=$line;
        }
        $capacity=ProviderCapabilityV2::groupCapacities($capability);foreach(self::demands($m,$capability) as $key=>$amount)if($amount>($capacity[$key]??0))throw new \InvalidArgumentException('Manifest oversubscribes shared/partitioned asset');
        return $m;
    }
    public static function hash(array $m): string {return bin2hex(ProtocolHash::hash('GREENWOOD_RESOURCE_MANIFEST_V1',CanonicalJson::encode($m)));}
    public static function value(array $m,array $quantities): array {
        $expected=array_column($m['lines'],'line_id');$keys=array_keys($quantities);sort($keys,SORT_STRING);if($keys!==$expected)throw new \InvalidArgumentException('Missing or extra resource quantity');
        $total=0;$lines=[];foreach($m['lines'] as $l){$q=Amount::integer($quantities[$l['line_id']]);if($q>Amount::integer($l['maximum_quantity']))throw new \InvalidArgumentException('Per-resource allowance exceeded');
            $billable=max(0,$q-Amount::integer($l['included_quantity']));$charge=Amount::mulDiv($billable,Amount::integer($l['rate_num']),Amount::integer($l['rate_den']),true);$total=Amount::add($total,$charge);$lines[$l['line_id']]=(string)$charge;}
        return ['gru_units'=>$total,'line_gru_units'=>$lines];
    }
    public static function maximum(array $m): array {$q=[];foreach($m['lines'] as $l)$q[$l['line_id']]=$l['maximum_quantity'];return self::value($m,$q);}
    public static function demands(array $m,array $capability): array {
        $assets=ProviderCapabilityV2::assets($capability);$perAsset=[];$out=[];
        foreach($m['lines'] as $l)$perAsset[$l['asset_id']]=max($perAsset[$l['asset_id']]??0,Amount::integer($l['reserved_units']));
        foreach($perAsset as $id=>$amount){$a=$assets[$id];$dimension=ResourceCatalog::definition($a['resources'][0])['capacity_unit'];$key=$a['exclusivity_group'].':'.$dimension;$out[$key]=Amount::add($out[$key]??0,$amount);}
        foreach($m['lines'] as $line)if($line['resource_id']==='infrastructure.node.reserved'){$group=$assets[$line['asset_id']]['billing_group'];foreach($assets as $a)if($a['billing_group']===$group){$unit=ResourceCatalog::definition($a['resources'][0])['capacity_unit'];$out[$a['exclusivity_group'].':'.$unit]=Amount::integer($a['group_capacity']);}}
        ksort($out,SORT_STRING);return $out;
    }
}
