<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Deployment;
use Greenwood\DeploymentController\State\OperationStore;
use Greenwood\DeploymentController\Auth\DeploymentIdentity;
use Greenwood\DeploymentController\Assignment\AssignmentDocument;
use Greenwood\DeploymentController\Provider\ProviderObserverClient;

/** Durable orchestration only. Runtime, observer and proxy work belongs to existing services. */
final class DeploymentCoordinator {
    public function __construct(private readonly OperationStore $store,private readonly \Closure $authorize,
        private readonly \Closure $plan,private readonly \Closure $inspect,private readonly \Closure $start,
        private readonly \Closure $admit,private readonly \Closure $observe,private readonly \Closure $edge,
        private readonly \Closure $readiness,private readonly bool $writesEnabled=false,
        private readonly ?\Closure $assignmentPreflight=null,private readonly ?\Closure $canonicalUrl=null,
        private readonly ?\Closure $acknowledgeAssignment=null) {}
    public function authority(string $account,array $intent):array {
        DeploymentIdentity::validate($intent);
        $proof=($this->authorize)($account,$intent);
        if(($proof['registry_contract']??null)!==$intent['registry_contract_id'])throw new \RuntimeException('REGISTRY_AUTHORITY_MISMATCH');
        if(($proof['authorized']??false)!==true||($proof['account_id']??null)!==$account||!is_int($proof['observed_at']??null)||$proof['observed_at']<time()-15||$proof['observed_at']>time()+2)throw new \RuntimeException('AUTHORITY_UNAVAILABLE');
        if(($proof['chain_id']??null)!==$intent['chain_id']||!is_int($proof['finalized_height']??null)||$proof['finalized_height']<1
            ||!is_int($proof['authority_epoch']??null)||$proof['authority_epoch']<1||!is_int($proof['effective_authority']??null)||($proof['effective_authority']&2)!==2)throw new \RuntimeException('FINALITY_UNAVAILABLE');
        foreach(['block_hash','state_root','registry_contract','controller']as$k)if(!is_string($proof[$k]??null)||!preg_match('/^[a-f0-9]{64}$/D',$proof[$k]))throw new \RuntimeException('FINALITY_UNAVAILABLE');
        foreach(['application_id','service','image_digest']as$k)if(($proof[$k]??null)!==$intent[$k])throw new \RuntimeException('AUTHORITY_MISMATCH');
        if(($proof['release_id']??null)!==$intent['release_number'])throw new \RuntimeException('RELEASE_MISMATCH');return $proof;
    }
    public function run(string $id,bool $allowSideEffects=true):array {
        return $this->store->exclusive($id,function(array $op)use($allowSideEffects):array {
            $i=json_decode($op['intent'],true,32,JSON_THROW_ON_ERROR);$e=json_decode($op['evidence'],true,32,JSON_THROW_ON_ERROR);
            $advance=function(string $state)use(&$op,&$e):void{$op=$this->store->save($op,$state,$e);};
            try {
                $e['authority']=$this->authority($op['account'],$i);
                if(!$allowSideEffects&&!in_array($op['state'],['ASSIGNED','EDGE_PENDING','READY'],true))return $this->response($op,false);
                if(!$this->writesEnabled)throw new \RuntimeException('WRITES_DISABLED');
                if($op['state']==='ACCEPTED'){$advance('AUTHORIZED');}
                if($op['state']==='AUTHORIZED'){
                    $e['plan']=($this->plan)($op['id'],$i,$e['authority']);
                    foreach(['chain_id','registry_contract_id','application_id','service','image_digest']as$k)if(($e['plan'][$k]??null)!==$i[$k])throw new \RuntimeException('PLAN_MISMATCH');
                    if(($e['plan']['release_id']??null)!==$i['release_number'])throw new \RuntimeException('PLAN_MISMATCH');
                    $advance('RESERVED');
                }
                if($op['state']==='RESERVED'){
                    $e['authority']=$this->authority($op['account'],$i);
                    if($this->assignmentPreflight===null)throw new \RuntimeException('ASSIGNMENT_PREFLIGHT_REQUIRED');
                    $prepared=$this->acknowledgeAssignment!==null&&isset($e['assignment_document'])
                        ?['document'=>$e['assignment_document']]:($this->assignmentPreflight)($op['id'],$e['plan'],$e['authority']);
                    $document=AssignmentDocument::validate($prepared['document']??[]);
                    if($document['operation_id']!==$op['id']||$document['account_id']!==$op['account']
                        ||$document['chain_id']!==$i['chain_id']||$document['registry_contract_id']!==$i['registry_contract_id']
                        ||$document['version']!==1||$document['previous_generation']!==null)throw new \RuntimeException('ASSIGNMENT_PREFLIGHT_MISMATCH');
                    $assignment=$document['assignment'];
                    foreach($e['plan']as$key=>$value)if($key!=='image_ref'&&($assignment[$key]??null)!==$value)throw new \RuntimeException('ASSIGNMENT_PLAN_MISMATCH');
                    $generation=AssignmentDocument::generation($document);
                    if(isset($e['assignment_document'])&&AssignmentDocument::generation($e['assignment_document'])!==$generation)throw new \RuntimeException('RESERVATION_CHANGED');
                    $e['assignment_document']=$document;
                    $advance('RESERVED'); // exportable before allocation approval or distribution I/O
                    if($this->acknowledgeAssignment!==null)$prepared['ack']=($this->acknowledgeAssignment)($document);
                    $ack=$prepared['ack']??[];
                    if(($ack['acknowledged']??false)!==true||($ack['generation']??null)!==$generation
                        ||($ack['assignment_id']??null)!==AssignmentDocument::assignmentId($document)
                        ||($ack['version']??null)!==$document['version'])throw new \RuntimeException('ASSIGNMENT_ACK_REQUIRED');
                    foreach(['node-1','node-4']as$node){
                        $row=$ack['nodes'][$node]??[];
                        if(($row['node_id']??null)!==$node||($row['generation']??null)!==$generation||($row['state']??null)!=='COMMITTED'
                            ||($row['assignment_id']??null)!==$ack['assignment_id']||($row['version']??null)!==$document['version']
                            ||!is_int($row['observed_at']??null)||$row['observed_at']<time()-15||$row['observed_at']>time()+2)throw new \RuntimeException('ASSIGNMENT_ACK_REQUIRED');
                    }
                    ksort($assignment);
                    $e['assignment']=$assignment+['assignment_id'=>AssignmentDocument::assignmentId($document)];
                    $e['assignment_preflight']=$prepared;
                    // Node 4 must accept the exact committed assignment and release evidence
                    // before the first start. Publication still requires live runtime health.
                    ($this->admit)($op['id'],$e['assignment'],$e['authority']);
                    $e['authority']=$this->authority($op['account'],$i);
                    foreach($ack['nodes']as$row)if(time()-$row['observed_at']>15)throw new \RuntimeException('ASSIGNMENT_ACK_EXPIRED');
                    // First attempt only. Provider serializes by application/service and
                    // rejects a conflicting immutable image; recovery below never replays it.
                    $advance('START_UNCERTAIN'); // durable BEFORE network side effect
                    ($this->start)($op['id'],$e['plan']);
                }
                if($op['state']==='START_UNCERTAIN'){
                    // A timeout/restart only inspects. It never replays start.
                    $view=($this->inspect)($e['plan']);$r=$view['record']??[];$network=$view['network']??[];$runtime=$view['runtime']??[];
                    if(($r['image_ref']??null)!==$e['plan']['image_ref']||($r['image_digest']??null)!==$i['image_digest']||($r['application_id']??null)!==$i['application_id']||($r['service']??null)!==$i['service']
                        ||($r['state']??null)!=='RUNNING'||($runtime['runtime_state']??null)!=='RUNNING'||($network['ready']??false)!==true
                        ||!is_int($network['ingress_port']??null)||!is_string($r['container_id']??null))throw new \RuntimeException('START_UNRESOLVED');
                    if(!isset($e['assignment_preflight'],$e['assignment'])
                        ||$e['assignment']['ingress_port']!==$network['ingress_port']||$e['assignment']['instance_id']!==$r['container_id'])throw new \RuntimeException('RUNTIME_ASSIGNMENT_MISMATCH');
                    $advance('RUNNING');
                }
                if(in_array($op['state'],['RUNNING','ADMISSION_PENDING'],true)){
                    $e['authority']=$this->authority($op['account'],$i);$advance('ADMISSION_PENDING');
                    // Idempotent exact-content install. No overwrites on conflicting assignment identity.
                    ($this->admit)($op['id'],$e['assignment'],$e['authority']);$advance('ASSIGNED');
                }
                if(in_array($op['state'],['ASSIGNED','EDGE_PENDING','READY'],true)){
                    $e['authority']=$this->authority($op['account'],$i);
                    // Drop any previous READY assertion before every live re-observation.
                    unset($e['ready']);$advance('EDGE_PENDING');
                    $observedStart=time();$e['observation']=($this->observe)($e['assignment']);
                    ProviderObserverClient::validate($e['assignment'],$e['observation'],$e['observation']['nonce']??'', $observedStart,time());
                    $e['authority']=$this->authority($op['account'],$i);
                    $route=($this->edge)($op['id'],$e['assignment'],$e['authority']);
                    if(($route['confirmed']??false)!==true)return $this->response($op,false);
                    $nonce=bin2hex(random_bytes(32));$sent=time();
                    $proof=($this->readiness)($e['assignment'],$nonce);
                    self::verifyReady($e['assignment'],$proof,$nonce,$sent,$route,$e['authority'],($this->canonicalUrl)?->__invoke($e['assignment']));
                    $after=$this->authority($op['account'],$i);
                    if(($after['authority_epoch']??null)!==($e['authority']['authority_epoch']??null))throw new \RuntimeException('AUTHORITY_CHANGED');
                    $e['ready']=$proof;$e['authority']=$after;$advance('READY');return $this->response($op,true);
                }
                return $this->response($op,false);
            }catch(\Throwable $error){
                unset($e['ready']);$e['last_error']='DEPENDENCY_OR_AUTHORIZATION_UNAVAILABLE';
                // Never persist raw error strings or credentials. Keep phase for reconciliation.
                $op=$this->store->save($op,$op['state']==='READY'?'EDGE_PENDING':$op['state'],$e);
                return $this->response($op,false);
            }
        });
    }
    public static function verifyReady(array $a,array $p,string $nonce,int $sent,array $route,array $authority,?string $canonicalUrl=null):void {
        foreach(['application_id','service','release_id','image_digest','assignment_id','provider_id','instance_id']as$k)if(($p[$k]??null)!==$a[$k])throw new \RuntimeException('READINESS_IDENTITY_MISMATCH');
        foreach(['chain_id','registry_contract','application_id','release_id','service','image_digest','controller','authority_epoch']as$k)
            if(($p['authority'][$k]??null)!==$authority[$k])throw new \RuntimeException('READINESS_AUTHORITY_MISMATCH');
        if(!is_int($p['authority']['finalized_height']??null)||$p['authority']['finalized_height']<$authority['finalized_height']
            ||!is_int($p['pid']??null)||$p['pid']<2||!is_string($p['namespace_identity']??null)||$p['namespace_identity']===''
            ||($p['backend']['address']??null)!==$a['provider_address']||($p['backend']['port']??null)!==$a['ingress_port']
            ||!is_int($p['provider_observed_at']??null)||$p['provider_observed_at']<$sent-2||$p['provider_observed_at']>time()+2||time()-$p['provider_observed_at']>15)throw new \RuntimeException('READINESS_RUNTIME_UNVERIFIED');
        $url=$canonicalUrl??'https://gw-'.$a['application_id'].'-'.$a['service'].'.greenwoodblockchain.com/';
        if(!in_array($url,['https://gw-'.$a['application_id'].'-'.$a['service'].'.greenwoodblockchain.com/','https://app-'.$a['application_id'].'.greenwoodblockchain.com/'],true))throw new \RuntimeException('UNAPPROVED_PUBLIC_URL');
        if(($p['canonical_url']??null)!==$url||($p['public_https']['url']??null)!==$url||($p['authority']['authorized']??false)!==true)throw new \RuntimeException('PUBLIC_READINESS_UNVERIFIED');
        if(($p['nonce']??null)!==$nonce||($p['ready']??false)!==true||($p['provider_mtls_verified']??false)!==true
            ||!is_int($p['observed_at']??null)||$p['observed_at']<$sent||$p['observed_at']>time()+2||!is_int($p['expires_at']??null)||$p['expires_at']<=time()||$p['expires_at']>$p['observed_at']+15
            ||($p['edge_generation']??null)!==($route['generation']??null)||!preg_match('/^[a-f0-9]{64}$/D',$p['edge_generation']??'')
            ||($p['public_https']['verified']??false)!==true||($p['public_https']['status']??null)!==200
            ||($p['public_https']['body_sha256']??null)!==($route['body_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$route['body_sha256']??''))throw new \RuntimeException('READINESS_UNVERIFIED');
    }
    private function response(array $op,bool $ready):array {
        $i=json_decode($op['intent'],true);$e=json_decode($op['evidence'],true);$proof=$ready?($e['ready']??[]):[];
        return ['operation_id'=>$op['id'],'state'=>$ready?'READY':$op['state'],'ready'=>$ready,'finalized'=>$ready,
            'chain_id'=>$i['chain_id'],'registry_contract_id'=>$i['registry_contract_id'],'application_id'=>$i['application_id'],'release_number'=>$i['release_number'],'image_digest'=>$i['image_digest']]+$proof;
    }
}
