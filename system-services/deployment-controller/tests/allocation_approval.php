<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Greenwood\DeploymentController\Assignment\{AllocationApproval,AssignmentDocument};
use Greenwood\DeploymentController\Auth\DeploymentIdentity;
$d = ['chain_id'=>DeploymentIdentity::CHAIN,'registry_contract_id'=>DeploymentIdentity::REGISTRY,
    'operation_id'=>str_repeat('c',32),'account_id'=>str_repeat('d',64),'version'=>1,'previous_generation'=>null,
    'assignment'=>['chain_id'=>DeploymentIdentity::CHAIN,'registry_contract_id'=>DeploymentIdentity::REGISTRY,
        'application_id'=>1,'service'=>'web','release_id'=>1,'provider_id'=>str_repeat('a',64),
        'provider_address'=>'10.77.0.100','ingress_port'=>21001,'instance_id'=>'app-1-web',
        'image_digest'=>'sha256:'.str_repeat('b',64),'provider_endpoint'=>'tcp://10.77.0.100:19443',
        'provider_tls_name'=>'provider.greenwood']];
function exported(array $d): array { return ['document'=>$d,'operation_id'=>$d['operation_id'],
    'generation'=>AssignmentDocument::generation($d),'assignment_id'=>AssignmentDocument::assignmentId($d)]; }
function denied(Closure $fn): void { try { $fn(); } catch (Throwable) { return; } throw new RuntimeException('Invalid approval accepted'); }
$e = exported($d); $g = $e['generation']; $calls = 0;
$authorize = static function(array $actual) use ($d, &$calls): bool { ++$calls; return $actual === $d; };
if (AllocationApproval::validate($e,$g,$authorize) !== $d || $calls !== 1) throw new RuntimeException('Approval failed');
denied(fn()=>AllocationApproval::validate($e,str_repeat('0',64),$authorize));
denied(fn()=>AllocationApproval::validate($e,'',$authorize));
denied(fn()=>AllocationApproval::validate($e,$g,static fn()=>false));
denied(fn()=>AllocationApproval::validate($e,$g,static fn()=>throw new RuntimeException('RPC unavailable')));
foreach (['generation','assignment_id','operation_id'] as $field) {
    $bad = $e; $bad[$field] = str_repeat('0',strlen($bad[$field]));
    denied(fn()=>AllocationApproval::validate($bad,$g,$authorize));
}
$bad = $e; $bad['document']['assignment']['ingress_port']++;
denied(fn()=>AllocationApproval::validate($bad,$g,$authorize));
$next = $d; $next['version']=2; $next['previous_generation']=$g; $next=exported($next);
denied(fn()=>AllocationApproval::validate($next,$next['generation'],static fn()=>true));
echo "PASS: exact export/generation binding, finalized denial/unavailability, tamper rejection, initial-version restriction\n";
