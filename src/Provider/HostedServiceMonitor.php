<?php
declare(strict_types=1);
namespace Greenwood\Provider;

/** Existing operator/network adapters perform restriction and stop; this class never creates leases. */
final class HostedServiceMonitor
{
    public function __construct(private readonly \Closure $active,private readonly \Closure $authorize,
        private readonly \Closure $restrict,private readonly \Closure $stop) {}
    public function tick(): array {
        $results=[];
        foreach(($this->active)() as $record){
            if(!in_array($record['state'],['STARTING','RUNNING'],true))continue;
            $id=(int)$record['application_id'];$service=$record['service'];
            try{($this->authorize)($id,$service,$record['image_ref']);$results[]=['application_id'=>$id,'service'=>$service,'outcome'=>'FUNDED'];}
            catch(\Throwable){
                // Restrict network before stopping. Still attempt stop if network restriction fails.
                $restricted=false;$stopped=false;try{($this->restrict)($id,$service);$restricted=true;}catch(\Throwable){}
                try{($this->stop)($id,$service);$stopped=true;}catch(\Throwable){}
                $results[]=['application_id'=>$id,'service'=>$service,'outcome'=>$restricted&&$stopped?'RESTRICTED_STOPPED':'RESTRICTION_RETRY_REQUIRED'];
            }
        }
        return $results;
    }
}
