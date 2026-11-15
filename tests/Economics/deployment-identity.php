<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
spl_autoload_register(static function(string $class):void {
    $prefix='Greenwood\\DeploymentController\\';if(str_starts_with($class,$prefix)){ $path=dirname(__DIR__,2).'/system-services/deployment-controller/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require $path; }
});
use Greenwood\DeploymentController\Auth\{DeploymentIdentity,ChallengeService};
use Greenwood\DeploymentController\Assignment\AssignmentDocument;
rejects(fn()=>DeploymentIdentity::chain(),'implicit historical chain');
rejects(fn()=>DeploymentIdentity::configure(['chain_id'=>'new-mainnet']),'missing genesis/registry pins');
$scope=['chain_id'=>'greenwood-fresh-mainnet-qualification','genesis_hash'=>str_repeat('1',64),'registry_contract_id'=>str_repeat('2',64),'registry_wasm_hash'=>str_repeat('3',64)];DeploymentIdentity::configure($scope);
$intent=['action'=>'deploy','chain_id'=>$scope['chain_id'],'registry_contract_id'=>$scope['registry_contract_id'],'application_id'=>1,'service'=>'app','release_number'=>1,'image_digest'=>'sha256:'.str_repeat('4',64),'idempotency_key'=>'test_unique'];
check(ChallengeService::intent($intent)===$intent,'Fresh configured chain accepted');
$bad=$intent;$bad['chain_id']='greenwood-physical-v1';rejects(fn()=>ChallengeService::intent($bad),'historical chain rejected');
$bad=$intent;$bad['registry_contract_id']=str_repeat('5',64);rejects(fn()=>ChallengeService::intent($bad),'different registry rejected');
$assignment=['chain_id'=>$scope['chain_id'],'registry_contract_id'=>$scope['registry_contract_id'],'application_id'=>1,'service'=>'app','release_id'=>1,'provider_id'=>str_repeat('6',64),'provider_address'=>'10.1.2.3','ingress_port'=>11000,'instance_id'=>'app-1','image_digest'=>$intent['image_digest'],'provider_endpoint'=>'tcp://10.1.2.3:5000','provider_tls_name'=>'provider.local'];
$d=['chain_id'=>$scope['chain_id'],'registry_contract_id'=>$scope['registry_contract_id'],'operation_id'=>str_repeat('7',32),'account_id'=>str_repeat('8',64),'version'=>1,'previous_generation'=>null,'assignment'=>$assignment];
check(AssignmentDocument::validate($d)===$d,'Fresh mainnet assignment accepted');$bad=$d;$bad['chain_id']='greenwood-physical-v1';$bad['assignment']['chain_id']='greenwood-physical-v1';rejects(fn()=>AssignmentDocument::validate($bad),'historical assignment rejected');
$bad=$scope;$bad['genesis_hash']=str_repeat('9',64);rejects(fn()=>DeploymentIdentity::configure($bad),'process pins cannot be replaced');DeploymentIdentity::configure($scope);check(DeploymentIdentity::genesis()===$scope['genesis_hash'],'Idempotent trusted initialization');
echo json_encode(['status'=>'PASS','checks'=>$checks,'live_requests'=>0],JSON_PRETTY_PRINT)."\n";
