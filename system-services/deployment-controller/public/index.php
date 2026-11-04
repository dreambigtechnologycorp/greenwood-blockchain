<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='GET'&&in_array($path,['/health','/v1/health','/live'],true)){echo json_encode(['alive'=>true]);return;}
if($path==='/metrics'){http_response_code(404);echo json_encode(['error'=>'NOT_FOUND']);return;}
// Readiness stays closed until physical acceptance, including contract policy and
// assignment distribution, has been reviewed. Liveness proves only the PHP process.
if(in_array($path,['/ready','/v1/ready'],true)){http_response_code(503);echo json_encode(['ready'=>false,'error'=>'PHYSICAL_ACCEPTANCE_REQUIRED']);return;}
try {
    $config=getenv('GREENWOOD_CONTROLLER_CONFIG');
    if(!$config){
        if($method==='GET'&&$path==='/v1/developer/capabilities'){
            echo json_encode(['schema_version'=>2,'version'=>'0.3.0-rc1','chain_id'=>'greenwood-physical-v1','registry_contract_id'=>\Greenwood\DeploymentController\Auth\DeploymentIdentity::REGISTRY,'deployment_enabled'=>false,
                'features'=>array_fill_keys(['chain_account_auth','onchain_application_authority','signed_deployment_intent','finalized_release','provider_assignment','edge_reconciliation','readiness','idempotency'],false)]);return;
        }
        throw new RuntimeException('UNCONFIGURED');
    }
    // Only protected configuration can enable bounded, release-scoped writes.
    $app=\Greenwood\DeploymentController\Bootstrap::build(\Greenwood\DeploymentController\Assignment\PhysicalAssignmentServices::config($config));
    $body=file_get_contents('php://input',false,null,0,16385);
    [$response,$status]=$app['api']->handleRequest($method,$path,$body,getallheaders());
    http_response_code($status);echo json_encode($response,JSON_THROW_ON_ERROR);
}catch(Throwable){http_response_code(503);echo json_encode(['ready'=>false,'error'=>'CONTROLLER_UNAVAILABLE']);}
