<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Economics\CanonicalJson;

/** Bounded private IPC. Never accepts paths, keys, independence levels or payment commands. */
final class MeteringProtocol
{
    public static function handle(MeteringService $meter,string $raw): string
    {
        try{
            if(strlen($raw)>65536||!str_ends_with($raw,"\n"))throw new \InvalidArgumentException('Invalid telemetry frame');
            $c=CanonicalJson::decode(substr($raw,0,-1));$action=$c['action']??null;
            $keys=match($action){'begin','checkpoint','finish','close-boundary'=>['action','commitment','sample'],'bundle'=>['action','commitment'],'segment-state'=>['action','commitment','runtime'],'open-segments'=>['action','runtime'],'bind'=>['action','scope'],default=>throw new \InvalidArgumentException('Unknown telemetry operation')};
            if(count($c)!==count($keys)||array_diff(array_keys($c),$keys))throw new \InvalidArgumentException('Exact IPC fields required');
            $result=match($action){
                'bind'=>(function()use($meter,$c){$meter->bind($c['scope']);return ['bound'=>true];})(),
                'segment-state'=>['state'=>$meter->segmentState($c['commitment'],$c['runtime'])],
                'open-segments'=>['segments'=>$meter->openSegments($c['runtime'])],
                'close-boundary'=>(function()use($meter,$c){$meter->closeBoundary($c['commitment'],$c['sample']);return ['state'=>'CLOSED_BOUNDARY_UNVERIFIED'];})(),
                'begin'=>(function()use($meter,$c){$meter->begin($c['commitment'],$c['sample']);return ['state'=>'OPEN'];})(),
                'checkpoint'=>$meter->checkpoint($c['commitment'],$c['sample']),
                'finish'=>(function()use($meter,$c){$meter->finish($c['commitment'],$c['sample']);return ['state'=>'CLOSED'];})(),
                'bundle'=>$meter->bundle($c['commitment']),
            };
            $reply=CanonicalJson::encode(['status'=>'OK','result'=>$result]);
            if(strlen($reply)>65536)throw new \RuntimeException('Evidence needs bounded archival export');return $reply."\n";
        }catch(\Throwable){return "{\"reason\":\"TELEMETRY_REJECTED_RECONCILIATION_REQUIRED\",\"status\":\"UNVERIFIED\"}\n";}
    }
}
