<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Provider;
use Greenwood\DeploymentController\Assignment\AssignmentDocument;

final class EdgeAdmissionEndpoint
{
    public function __construct(private readonly TimerEdgeAdmission $admission,
        private readonly \Closure $authorize,private readonly \Closure $readiness) {}
    /** Principal comes exclusively from the pinned mTLS channel. */
    public function handle(string $principal,array $request):array
    {
        if($principal!=='deployment-controller')throw new \RuntimeException('CONTROLLER_REQUIRED');
        if(($request['action']??null)==='readiness'){
            if(array_diff(array_keys($request),['action','request'])||!is_array($request['request']??null))throw new \InvalidArgumentException('INVALID_READINESS_REQUEST');
            return ($this->readiness)($request['request']);
        }
        $keys=['action','operation_id','assignment','account_id'];
        if(array_diff(array_keys($request),$keys)||array_diff($keys,array_keys($request))
            ||!in_array($request['action'],['admit','inspect'],true)||!is_array($request['assignment']))throw new \InvalidArgumentException('INVALID_EDGE_REQUEST');
        $a=$request['assignment'];$plain=$a;unset($plain['assignment_id']);
        $d=AssignmentDocument::validate(['chain_id'=>$plain['chain_id']??null,'registry_contract_id'=>$plain['registry_contract_id']??null,
            'operation_id'=>$request['operation_id'],'account_id'=>$request['account_id'],'version'=>1,'previous_generation'=>null,'assignment'=>$plain]);
        if(($a['assignment_id']??null)!==AssignmentDocument::assignmentId($d))throw new \RuntimeException('ASSIGNMENT_ID_MISMATCH');
        ksort($plain);$a=$plain+['assignment_id'=>$a['assignment_id']];
        $proof=($this->authorize)($d['account_id'],['chain_id'=>$d['chain_id'],'registry_contract_id'=>$d['registry_contract_id'],
            'application_id'=>$a['application_id'],'service'=>$a['service'],'release_number'=>$a['release_id'],'image_digest'=>$a['image_digest']]);
        if(($proof['authorized']??false)!==true||($proof['account_id']??null)!==$d['account_id'])throw new \RuntimeException('FINALIZED_AUTHORITY_REQUIRED');
        if($request['action']==='admit'){
            $this->admission->admit($d['operation_id'],$a,$proof);
            return ['admitted'=>true,'assignment_id'=>$a['assignment_id']];
        }
        return $this->admission->inspect($d['operation_id'],$a,$proof);
    }
}
