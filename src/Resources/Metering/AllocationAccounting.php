<?php
declare(strict_types=1);
namespace Greenwood\Resources\Metering;
use Greenwood\Economics\{Amount,Policy};
use Greenwood\Resources\ResourceCatalog;

/** Adapter for allocator-issued leases and its current workload readiness observation.
 * A lease is an operator assertion, not proof of physical independence or utilization.
 */
final class AllocationAccounting
{
    public static function measurements(array $manifest,array $scope,array $leases,array $runtime,int $monotonicMs): array
    {
        SignedObservation::scope($scope);Policy::hex($runtime['runtime_identity']??null);
        if(($runtime['assignment_generation']??null)!==$scope['assignment_generation']||($runtime['workload_id']??null)!==$scope['workload_id']||!is_bool($runtime['ready']??null)||$monotonicMs<0)throw new \InvalidArgumentException('Exact observed allocation runtime required');
        $indexed=[];foreach($leases as $lease){
            if(!is_array($lease)||count($lease)!==8||array_diff(array_keys($lease),['asset_id','resource_id','units','workload_id','assignment_generation','runtime_identity','start_ms','end_ms']))throw new \InvalidArgumentException('Exact allocator lease required');
            Policy::hex($lease['asset_id']);$def=ResourceCatalog::definition($lease['resource_id']);
            if($def['measurement_type']!=='reserved'||$lease['workload_id']!==$scope['workload_id']||$lease['assignment_generation']!==$scope['assignment_generation']||$lease['runtime_identity']!==$runtime['runtime_identity'])throw new \InvalidArgumentException('Foreign or consumed allocation lease');
            Amount::integer($lease['units']);$start=Amount::integer($lease['start_ms']);$end=Amount::integer($lease['end_ms']);if($end<=$start)throw new \InvalidArgumentException('Positive allocation lease required');
            $key=$lease['asset_id'].':'.$lease['resource_id'];if(isset($indexed[$key]))throw new \InvalidArgumentException('Duplicate allocator lease');$indexed[$key]=$lease;
        }
        $out=[];foreach($manifest['lines'] as $line){if(ResourceCatalog::definition($line['resource_id'])['measurement_type']!=='reserved')continue;
            $lease=$indexed[$line['asset_id'].':'.$line['resource_id']]??null;
            $available=$lease!==null&&$runtime['ready']&&$monotonicMs>=Amount::integer($lease['start_ms'])&&$monotonicMs<=Amount::integer($lease['end_ms']);
            $out[]=['line_id'=>$line['line_id'],'asset_id'=>$line['asset_id'],'value'=>$available?$lease['units']:'0','available'=>$available];
        }return $out;
    }
}
