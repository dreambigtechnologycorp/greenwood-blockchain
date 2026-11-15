<?php
declare(strict_types=1);
namespace Greenwood\Resources;
use Greenwood\Economics\{Amount,CanonicalJson,Policy};

/** Typed evidence is authorization input, never a claim that signatures prove physical truth. */
final class ResourceUsageEvidence
{
    public static function quantities(array $e,array $c,array $p,string $chain,string $genesis,?int $receiptCpu): array {
        $m=$c['resource_manifest'];$assets=ProviderCapabilityV2::assets($c['resource_capability']);
        $expected=['version','chain','genesis','commitment','provider_id','workload_id','assignment_generation','billing_interval_id','resource_catalog_hash','resource_manifest_hash','clock','measurement_start_ms','measurement_end_ms','runtime_identity','device_identities','evidence_bundle_hash','measurements'];
        if(count($e)!==count($expected)||array_diff(array_keys($e),$expected)||($e['version']??null)!==1||($e['chain']??null)!==$chain||($e['genesis']??null)!==$genesis
            ||($e['commitment']??null)!==$c['id']||($e['billing_interval_id']??null)!==$c['id']||($e['provider_id']??null)!==$c['resource_capability']['provider_id']||($e['workload_id']??null)!==$c['request_id']
            ||($e['assignment_generation']??null)!==$c['usage_assignment_generation']||($e['resource_catalog_hash']??null)!==$p['catalog_hash']||($e['resource_manifest_hash']??null)!==ResourceManifest::hash($m)
            ||($e['clock']??null)!=='attested_monotonic_ms_v1')throw new \InvalidArgumentException('Resource evidence binding mismatch');
        foreach(['runtime_identity','evidence_bundle_hash'] as $key)Policy::hex($e[$key]??null);
        $start=Amount::integer($e['measurement_start_ms']??null);$end=Amount::integer($e['measurement_end_ms']??null);
        if($end<$start||$end-$start>Amount::integer($m['duration_ms']))throw new \InvalidArgumentException('Resource measurement duration exceeded');
        $devices=array_values(array_unique(array_column($m['lines'],'asset_id')));sort($devices,SORT_STRING);
        if(($e['device_identities']??null)!==$devices||!is_array($e['measurements']??null)||!array_is_list($e['measurements'])||count($e['measurements'])!==count($m['lines']))throw new \InvalidArgumentException('Missing or foreign workload/device measurements');
        $q=[];foreach($m['lines'] as $i=>$line){$v=$e['measurements'][$i];$def=ResourceCatalog::definition($line['resource_id']);
            if(!is_array($v)||count($v)!==7||array_diff(array_keys($v),['line_id','asset_id','meter_version','quantity','reserved_units','available_ms','source_hash'])
                ||($v['line_id']??null)!==$line['line_id']||($v['asset_id']??null)!==$line['asset_id']||($v['meter_version']??null)!==$line['meter_version'])throw new \InvalidArgumentException('Wrong resource line/meter');
            Policy::hex($v['source_hash']??null);$quantity=Amount::integer($v['quantity']??null);
            if($def['measurement_type']==='reserved'){
                $units=Amount::integer($v['reserved_units']??null);$ms=Amount::integer($v['available_ms']??null);
                if($units!==Amount::integer($line['reserved_units'])||$ms>$end-$start||$quantity!==Amount::multiply($units,$ms))throw new \InvalidArgumentException('Reservation evidence integral mismatch');
            }elseif($v['reserved_units']!==null||$v['available_ms']!==null)throw new \InvalidArgumentException('Consumed quantity cannot masquerade as reservation');
            if($line['resource_id']==='compute.cpu.consumed'&&$receiptCpu!==null&&$quantity!==$receiptCpu)throw new \InvalidArgumentException('CPU evidence conflicts with finalized execution receipt');
            $q[$line['line_id']]=(string)$quantity;
        }
        ResourceManifest::value($m,$q);return $q;
    }
}
