<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Api;
use Greenwood\DeploymentController\Auth\{ChallengeService,SignedRequestVerifier};
use Greenwood\DeploymentController\State\SqliteOperationStore;
use Greenwood\DeploymentController\Deployment\DeploymentCoordinator;
use Greenwood\DeploymentController\Util\RateLimiter;
use Greenwood\DeploymentController\Assignment\AssignmentDocument;
final class DeveloperRoutes {
    public function __construct(private readonly SqliteOperationStore $store,private readonly ChallengeService $challenges,
        private readonly SignedRequestVerifier $signatures,private readonly DeploymentCoordinator $coordinator,
        private readonly RateLimiter $limits,private readonly bool $enabled=false) {}
    public function handleRequest(string $method,string $path,string $body,array $headers):array {
        try {
            if($method==='GET'&&in_array($path,['/health','/v1/health'],true))return [['alive'=>true],200];
            if($path==='/v1/developer/capabilities'&&$method==='GET')return [['schema_version'=>2,'version'=>'0.3.0-rc1','chain_id'=>'greenwood-physical-v1','registry_contract_id'=>\Greenwood\DeploymentController\Auth\DeploymentIdentity::REGISTRY,
                'deployment_enabled'=>$this->enabled,'features'=>array_fill_keys(['chain_account_auth','onchain_application_authority','signed_deployment_intent','finalized_release','provider_assignment','edge_reconciliation','readiness','idempotency'],$this->enabled)],200];
            if(!$this->enabled&&$method==='POST')return [['ready'=>false,'error'=>'WRITES_DISABLED'],503];
            if(strlen($body)>16384)throw new \InvalidArgumentException('REQUEST_TOO_LARGE');
            if(preg_match('~^/v1/developer/deployments/([a-f0-9]{32})/(assignment|resume)$~D',$path,$m)
                &&(($method==='GET'&&$m[2]==='assignment')||($method==='POST'&&$m[2]==='resume'))){
                if($body!=='')throw new \InvalidArgumentException('EMPTY_BODY_REQUIRED');
                $account=$this->signatures->verify($method,$path,$body,$headers);
                if(!$this->limits->isAllowed($account))return [['error'=>'RATE_LIMITED'],429];
                try{$op=$this->store->get($m[1]);}catch(\RuntimeException $e){if($e->getMessage()==='NOT_FOUND')return [['error'=>'NOT_FOUND'],404];throw $e;}
                if($op['account']!==$account)return [['error'=>'NOT_FOUND'],404];
                $this->coordinator->authority($account,json_decode($op['intent'],true));
                if($m[2]==='resume')return [$this->coordinator->run($op['id']),202];
                $e=json_decode($op['evidence'],true,32,JSON_THROW_ON_ERROR);
                if(!isset($e['assignment_document']))return [['error'=>'ASSIGNMENT_NOT_RESERVED'],409];
                $d=AssignmentDocument::validate($e['assignment_document']);
                return [['operation_id'=>$op['id'],'document'=>$d,'generation'=>AssignmentDocument::generation($d),
                    'assignment_id'=>AssignmentDocument::assignmentId($d),'approval_status'=>'NOT_CHECKED'],200];
            }
            if($method==='POST'&&$path==='/v1/developer/challenge')return [['challenge'=>$this->challenges->issue(json_decode($body,true,16,JSON_THROW_ON_ERROR))],200];
            if($method==='POST'&&$path==='/v1/developer/deployments'){
                [$account,$intent]=$this->challenges->verify(json_decode($body,true,16,JSON_THROW_ON_ERROR));
                if(!$this->limits->isAllowed($account))return [['error'=>'RATE_LIMITED'],429];
                $this->coordinator->authority($account,$intent);
                $op=$this->store->accept($account,$intent);return [$this->coordinator->run($op['id']),202];
            }
            if($method==='GET'&&preg_match('~^/v1/developer/deployments/(?:by-key/([a-zA-Z0-9_-]{8,128})|([a-f0-9]{32}))$~D',$path,$m)){
                $account=$this->signatures->verify($method,$path,$body,$headers);
                if(!$this->limits->isAllowed($account))return [['error'=>'RATE_LIMITED'],429];
                $op=($m[1]??'')!==''?$this->store->byKey($account,$m[1]):$this->store->get($m[2]);
                if($op===null||$op['account']!==$account)return [['error'=>'NOT_FOUND'],404];
                $this->coordinator->authority($account,json_decode($op['intent'],true));
                return [$this->coordinator->run($op['id'],false),200];
            }
            return [['error'=>'NOT_FOUND'],404];
        }catch(\InvalidArgumentException|\JsonException|\TypeError){return [['error'=>'INVALID_REQUEST'],400];}
        catch(\Throwable){return [['ready'=>false,'error'=>'AUTHORIZATION_OR_DEPENDENCY_UNAVAILABLE'],403];}
    }
}
