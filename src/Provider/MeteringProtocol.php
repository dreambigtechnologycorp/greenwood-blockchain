<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Economics\CanonicalJson;

/** Bounded private IPC. Never accepts paths, keys, independence levels or payment commands. */
final class MeteringProtocol
{
    public static function handle(MeteringService $meter,string $raw,?ResourceTelemetryJournal $telemetry=null): string
    {
        try{
            if(strlen($raw)>65536||!str_ends_with($raw,"\n"))throw new \InvalidArgumentException('Invalid telemetry frame');
            $c=CanonicalJson::decode(substr($raw,0,-1));$action=$c['action']??null;
            if(is_string($action)&&str_starts_with($action,'resource-'))return self::resource($telemetry,$c);
            $keys=match($action){'begin','checkpoint','finish','close-boundary'=>['action','commitment','sample'],'archive-closed'=>['action','commitment','bundle_hash'],'bundle'=>['action','commitment'],'segment-state'=>['action','commitment','runtime'],'open-segments'=>['action','runtime'],'bind'=>['action','scope'],default=>throw new \InvalidArgumentException('Unknown telemetry operation')};
            if(count($c)!==count($keys)||array_diff(array_keys($c),$keys))throw new \InvalidArgumentException('Exact IPC fields required');
            $result=match($action){
                'bind'=>(function()use($meter,$c){$meter->bind($c['scope']);return ['bound'=>true];})(),
                'segment-state'=>['state'=>$meter->segmentState($c['commitment'],$c['runtime'])],
                'open-segments'=>['segments'=>$meter->openSegments($c['runtime'])],
                'close-boundary'=>(function()use($meter,$c){$meter->closeBoundary($c['commitment'],$c['sample']);return ['state'=>'CLOSED_BOUNDARY_UNVERIFIED'];})(),
                'begin'=>(function()use($meter,$c){$meter->begin($c['commitment'],$c['sample']);return ['state'=>'OPEN'];})(),
                'checkpoint'=>$meter->checkpoint($c['commitment'],$c['sample']),
                'finish'=>(function()use($meter,$c){$meter->finish($c['commitment'],$c['sample']);return ['state'=>'CLOSED'];})(),
                'archive-closed'=>['archive_hash'=>$meter->archiveClosed($c['commitment'],$c['bundle_hash'])],
                'bundle'=>$meter->bundle($c['commitment']),
            };
            $reply=CanonicalJson::encode(['status'=>'OK','result'=>$result]);
            if(strlen($reply)>65536)throw new \RuntimeException('Evidence needs bounded archival export');return $reply."\n";
        }catch(\Throwable){return "{\"reason\":\"TELEMETRY_REJECTED_RECONCILIATION_REQUIRED\",\"status\":\"UNVERIFIED\"}\n";}
    }    private static function resource(?ResourceTelemetryJournal $journal,array $c): string
    {
        if(!$journal)throw new \RuntimeException('Resource telemetry not configured');
        $fields=match($c['action']){'resource-bind'=>['action','scope','manifest','source_key','maximum_gap_ms'],
            'resource-append','resource-close'=>['action','commitment','signed'],
            'resource-evidence'=>['action','commitment'],'resource-archive'=>['action','commitment','head'],default=>throw new \InvalidArgumentException('Unsupported resource telemetry operation')};
        if(count($c)!==count($fields)||array_diff(array_keys($c),$fields))throw new \InvalidArgumentException('Exact resource IPC fields required');
        $result=match($c['action']){
            'resource-bind'=>(function()use($journal,$c){$journal->bind($c['scope'],$c['manifest'],$c['source_key'],$c['maximum_gap_ms']);return ['bound'=>true,'payable'=>false];})(),
            'resource-append','resource-close'=>$journal->append($c['commitment'],$c['signed'],$c['action']==='resource-close'),
            'resource-evidence'=>['evidence'=>$journal->evidence($c['commitment']),'payable'=>false,'signing_required'=>true,'verification_level'=>'C'],
            'resource-archive'=>['archive_hash'=>$journal->archiveClosed($c['commitment'],$c['head'])],
        };
        $reply=CanonicalJson::encode(['status'=>'OK','result'=>$result]);if(strlen($reply)>65536)throw new \RuntimeException('Resource response exceeds IPC bound');return $reply."\n";
    }

}
