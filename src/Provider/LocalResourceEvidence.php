<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Crypto\ProtocolHash;
use Greenwood\Economics\{Amount,CanonicalJson};
use Greenwood\Resources\{ResourceManifest,ResourceUsageEvidence};

/** Convert a closed local journal into an unsigned candidate for explicit review.
 * Device bindings and RAM allocation remain operator assertions, not independent truth.
 */
final class LocalResourceEvidence
{
    public static function prepare(array $bundle,array $commitment,array $program,string $chain,string $genesis,array $blockDevices=[]): array
    {
        $body=$bundle['bundle']??[];
        $digest=bin2hex(ProtocolHash::hash('GREENWOOD_LOCAL_METER_BUNDLE_V1',CanonicalJson::encode($body)));
        if(($bundle['evidence_bundle_hash']??null)!==$digest||($bundle['subsidy_eligible']??null)!==false
            ||($body['verification_level']??null)!=='C'||($body['boundary_reconciliation_required']??false)
            ||count($body['segments']??[])!==1)throw new \InvalidArgumentException('One complete attributable local interval required');
        $manifest=$commitment['resource_manifest'];$scope=$body['funding_scope']??[];
        \Greenwood\Resources\Metering\SignedObservation::scope($scope);
        $expected=['chain'=>$chain,'genesis'=>$genesis,'provider_id'=>$commitment['resource_capability']['provider_id'],
            'workload_id'=>$commitment['request_id'],'assignment_generation'=>$commitment['usage_assignment_generation'],
            'commitment'=>$commitment['id'],'catalog_hash'=>$program['catalog_hash'],'manifest_hash'=>ResourceManifest::hash($manifest)];
        foreach($expected as $key=>$value)if(($scope[$key]??null)!==$value)throw new \InvalidArgumentException('Local journal does not match accepted commitment');
        if(($body['commitment']??null)!==$commitment['id']||$scope['interval_start_height']!==($commitment['hosted']['start_height']??$commitment['funded_height'])||$scope['interval_end_height']!==$commitment['end_height'])throw new \InvalidArgumentException('Local billing interval mismatch');
        $segment=$body['segments'][0];$first=$segment['first'];$last=$segment['last'];
        if($first['runtime_identity']!==$last['runtime_identity']||$segment['runtime_identity']!==$last['runtime_identity'])throw new \InvalidArgumentException('Runtime identity changed');
        $start=Amount::integer($first['monotonic_ms']??null);$end=Amount::integer($last['monotonic_ms']??null);
        if($end<=$start)throw new \InvalidArgumentException('Positive observed interval required');
        $cpu=Amount::integer($last['cpu_ms'])-Amount::integer($first['cpu_ms']);
        if($cpu<0||($bundle['cpu_ms']??null)!==(string)$cpu||$segment['cpu_ms']!==(string)$cpu)throw new \InvalidArgumentException('CPU delta mismatch');
        $usedDevices=[];$assetDevices=[];
        foreach($blockDevices as $asset=>$device){
            if(!is_string($device)||!preg_match('/^[0-9]+:[0-9]+$/D',$device)||isset($usedDevices[$device]))throw new \InvalidArgumentException('Distinct explicit block-device bindings required');
            $usedDevices[$device]=true;$assetDevices[$asset]=$device;
        }
        $measurements=[];$storageAssets=[];
        foreach($manifest['lines'] as $line){
            $reserved=null;$available=null;$resource=$line['resource_id'];
            if($resource==='compute.cpu.consumed'){$quantity=$cpu;}
            elseif($resource==='memory.ram.reserved'){
                $reserved=Amount::integer($line['reserved_units']);$bytes=Amount::multiply($reserved,1048576);
                if(($first['memory_limit_bytes']??null)!==(string)$bytes||($last['memory_limit_bytes']??null)!==(string)$bytes)throw new \InvalidArgumentException('RAM reservation does not match observed hard limit');
                $available=$end-$start;$quantity=Amount::multiply($reserved,$available);
            }elseif(in_array($resource,['storage.block.read','storage.block.write','storage.block.iops'],true)){
                $device=$assetDevices[$line['asset_id']]??throw new \InvalidArgumentException('Unmapped storage asset');$storageAssets[$line['asset_id']]=true;
                if(!is_array($first['io_devices']??null)||!is_array($last['io_devices']??null)||!isset($last['io_devices'][$device]))throw new \InvalidArgumentException('Block controller or device counters unavailable');
                $quantity=0;
                foreach(match($resource){'storage.block.read'=>['rbytes'],'storage.block.write'=>['wbytes'],default=>['rios','wios']} as $field){
                    // Linux omits a device row until its first I/O. A missing controller is never zero.
                    $a=isset($first['io_devices'][$device])?Amount::integer($first['io_devices'][$device][$field]??null):0;
                    $b=Amount::integer($last['io_devices'][$device][$field]??null);
                    if($b<$a)throw new \InvalidArgumentException('Block counter reset');$quantity=Amount::add($quantity,$b-$a);
                }
            }else{throw new \InvalidArgumentException('Resource requires a separately qualified collector: '.$resource);}
            $measurements[]=['line_id'=>$line['line_id'],'asset_id'=>$line['asset_id'],'meter_version'=>$line['meter_version'],'quantity'=>(string)$quantity,
                'reserved_units'=>$reserved===null?null:(string)$reserved,'available_ms'=>$available===null?null:(string)$available,
                'source_hash'=>bin2hex(ProtocolHash::hash('GREENWOOD_LOCAL_RESOURCE_SOURCE_V1',CanonicalJson::encode(['bundle_hash'=>$digest,'line'=>$line,'block_device'=>$assetDevices[$line['asset_id']]??null])))];
        }
        if(array_diff_key($assetDevices,$storageAssets))throw new \InvalidArgumentException('Foreign storage binding');
        $devices=array_values(array_unique(array_column($manifest['lines'],'asset_id')));sort($devices,SORT_STRING);
        $e=['version'=>1,'chain'=>$chain,'genesis'=>$genesis,'commitment'=>$commitment['id'],'provider_id'=>$scope['provider_id'],'workload_id'=>$scope['workload_id'],
            'assignment_generation'=>$scope['assignment_generation'],'billing_interval_id'=>$commitment['id'],'resource_catalog_hash'=>$scope['catalog_hash'],
            'resource_manifest_hash'=>$scope['manifest_hash'],'clock'=>'attested_monotonic_ms_v1','measurement_start_ms'=>(string)$start,'measurement_end_ms'=>(string)$end,
            'runtime_identity'=>$segment['runtime_identity'],'device_identities'=>$devices,'evidence_bundle_hash'=>$digest,'measurements'=>$measurements];
        $quantities=ResourceUsageEvidence::quantities($e,$commitment,$program,$chain,$genesis,null);
        return ['evidence'=>$e,'valuation'=>ResourceManifest::value($manifest,$quantities),'payable'=>false,'signing_required'=>true,
            'verification_level'=>'C','qualification'=>'PROVIDER_ORIGIN_REQUIRES_EXPLICIT_POLICY_AND_ATTESTATION',
            'reservation_basis'=>'operator allocation plus sampled hard memory limit; not RAM consumption or independent availability proof'];
    }
}
