<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Greenwood\DeploymentController\State\SqliteOperationStore;
use Greenwood\DeploymentController\Auth\{ChallengeService,SignedRequestVerifier};
use Greenwood\DeploymentController\Deployment\DeploymentCoordinator;
use Greenwood\DeploymentController\Api\DeveloperRoutes;
use Greenwood\DeploymentController\Util\RateLimiter;
use Greenwood\Crypto\ProtocolHash;
use Greenwood\DeploymentController\Assignment\AssignmentDocument;
function check(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$dir=sys_get_temp_dir().'/gw-controller-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$key=sodium_crypto_sign_seed_keypair(str_repeat('a',32));$public=sodium_crypto_sign_publickey($key);$secret=sodium_crypto_sign_secretkey($key);$account=bin2hex(ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$public));
$wrongImageScope=false;$allowed=true;$finality=true;$running=false;$startCalls=0;$timeout=false;$edgeReady=true;$stale=false;$wrongGeneration=false;$mirrorFail=false;$admitCalls=0;
$intent=['action'=>'deploy','chain_id'=>'greenwood-physical-v1','registry_contract_id'=>\Greenwood\DeploymentController\Auth\DeploymentIdentity::REGISTRY,'application_id'=>3,'service'=>'web','release_number'=>1,'image_digest'=>'sha256:'.str_repeat('a',64),'idempotency_key'=>'test-operation-001'];
$store=new SqliteOperationStore($dir.'/operations.sqlite');
$authorize=function($who,$i)use(&$allowed,&$finality,$account){return ['authorized'=>$allowed&&$who===$account,'account_id'=>$who,'application_id'=>$i['application_id'],'service'=>$i['service'],'release_id'=>$i['release_number'],'image_digest'=>$i['image_digest'],'authority_epoch'=>1,'observed_at'=>time(),'chain_id'=>$i['chain_id'],'registry_contract'=>\Greenwood\DeploymentController\Auth\DeploymentIdentity::REGISTRY,'controller'=>$account,'effective_authority'=>15,'finalized_height'=>$finality?100:null,'block_hash'=>str_repeat('2',64),'state_root'=>str_repeat('3',64)];};
$plan=static fn($id,$i,$proof)=>['chain_id'=>$i['chain_id'],'registry_contract_id'=>$i['registry_contract_id'],'application_id'=>$i['application_id'],'service'=>$i['service'],'release_id'=>$i['release_number'],'image_digest'=>$i['image_digest'],'provider_id'=>str_repeat('b',64),'provider_address'=>'10.77.0.100','provider_endpoint'=>'tcp://10.77.0.100:19443','provider_tls_name'=>'provider','image_ref'=>'registry/apps/3/web@'.$i['image_digest']];
$inspect=function($p)use(&$running,&$wrongImageScope){return !$running?['absent'=>true]:['record'=>['image_ref'=>$wrongImageScope?'legacy/apps/'.$p['application_id'].'/web@'.$p['image_digest']:$p['image_ref'],'application_id'=>$p['application_id'],'service'=>$p['service'],'image_digest'=>$p['image_digest'],'container_id'=>'app-3-web','state'=>'RUNNING'],'runtime'=>['runtime_state'=>'RUNNING'],'network'=>['ready'=>true,'ingress_port'=>21001]];};
$start=function($id,$p)use(&$running,&$startCalls,&$timeout){$startCalls++;$running=true;if($timeout)throw new RuntimeException('timeout after provider commit');};
$admit=function()use(&$mirrorFail,&$admitCalls){$admitCalls++;if($mirrorFail)throw new RuntimeException('mirror uncertain');};
$observe=function($a)use(&$stale){return $a+['nonce'=>str_repeat('c',64),'address'=>$a['provider_address'],'port'=>$a['ingress_port'],'state'=>'RUNNING','lifecycle_state'=>'RUNNING','network_state'=>'HEALTHY','pid'=>20,'network_pid'=>20,'namespace_identity'=>'netns','ready'=>true,'observed_at'=>time()-($stale?60:0)];};
$edge=function()use(&$edgeReady){return ['confirmed'=>$edgeReady,'generation'=>str_repeat('d',64),'body_sha256'=>str_repeat('e',64)];};
$ready=function($a,$nonce)use(&$wrongGeneration,$authorize,$account){$url='https://gw-'.$a['application_id'].'-web.greenwoodblockchain.com/';return $a+['nonce'=>$nonce,'ready'=>true,'provider_mtls_verified'=>true,'observed_at'=>time(),'expires_at'=>time()+15,'edge_generation'=>str_repeat($wrongGeneration?'f':'d',64),'pid'=>20,'namespace_identity'=>'netns','backend'=>['address'=>$a['provider_address'],'port'=>$a['ingress_port']],'provider_observed_at'=>time(),'authority'=>$authorize($account,['chain_id'=>'greenwood-physical-v1','registry_contract_id'=>\Greenwood\DeploymentController\Auth\DeploymentIdentity::REGISTRY,'application_id'=>$a['application_id'],'service'=>$a['service'],'release_number'=>$a['release_id'],'image_digest'=>$a['image_digest']]),'canonical_url'=>$url,'public_https'=>['url'=>$url,'verified'=>true,'status'=>200,'body_sha256'=>str_repeat('e',64)]];};
$preflightFault=null;
$preflight=static function($id,$p,$proof)use(&$preflightFault):array{
    $a=$p;unset($a['image_ref']);$a['ingress_port']=21001;$a['instance_id']='app-3-web';
    $d=['chain_id'=>$p['chain_id'],'registry_contract_id'=>$p['registry_contract_id'],'operation_id'=>$id,
        'account_id'=>$proof['account_id'],'version'=>1,'previous_generation'=>null,'assignment'=>$a];
    if($preflightFault==='plan')$d['assignment']['provider_id']=str_repeat('f',64);
    if($preflightFault==='runtime')$d['assignment']['ingress_port']=21002;
    $g=AssignmentDocument::generation($d);$assignmentId=AssignmentDocument::assignmentId($d);$nodes=[];
    foreach(['node-1','node-4']as$n)$nodes[$n]=['node_id'=>$n,'generation'=>$g,'assignment_id'=>$assignmentId,'version'=>1,'state'=>'COMMITTED','observed_at'=>time()];
    if($preflightFault==='partial')unset($nodes['node-4']);
    if($preflightFault==='stale')$nodes['node-4']['observed_at']=time()-60;
    if($preflightFault==='generation')$nodes['node-4']['generation']=str_repeat('0',64);
    return ['document'=>$d,'ack'=>['acknowledged'=>true,'generation'=>$g,'assignment_id'=>$assignmentId,'version'=>1,'nodes'=>$nodes]];
};
$make=function()use(&$store,$authorize,$plan,$inspect,$start,$admit,$observe,$edge,$ready,$preflight){return new DeploymentCoordinator($store,$authorize,$plan,$inspect,$start,$admit,$observe,$edge,$ready,true,$preflight);};
$coordinator=$make();$challenges=new ChallengeService($store->db);$signatures=new SignedRequestVerifier($store->db,'https://controller.test');$limits=new RateLimiter($store->db,1000);
$api=new DeveloperRoutes($store,$challenges,$signatures,$coordinator,$limits,true);
$submit=function($i)use($api,$public,$secret){[$r,$s]=$api->handleRequest('POST','/v1/developer/challenge',json_encode($i),[]);check($s===200,'Challenge failed');$c=$r['challenge'];return $api->handleRequest('POST','/v1/developer/deployments',json_encode($i+['challenge_id'=>$c['id'],'public_key'=>bin2hex($public),'signature'=>bin2hex(sodium_crypto_sign_detached($c['canonical_bytes'],$secret))]),[]);};
try{
    // Authenticated request, complete positive path, and duplicate idempotent request.
    [$result,$status]=$submit($intent);check($status===202&&$result['ready'],'Positive path failed');$id=$result['operation_id'];
    [$again]=$submit($intent);check($again['operation_id']===$id&&$startCalls===1,'Duplicate start');
    foreach(['partial','stale','generation','plan']as$fault){
        $preflightFault=$fault;$test=$intent;$test['idempotency_key']='preflight-'.$fault;
        $op=$store->accept($account,$test);$before=$startCalls;$blocked=$coordinator->run($op['id']);
        check(!$blocked['ready']&&$blocked['state']==='RESERVED'&&$startCalls===$before,'Unapproved start: '.$fault);
    }
    $preflightFault=null;
    $test=$intent;$test['idempotency_key']='missing-preflight';$op=$store->accept($account,$test);$before=$startCalls;
    $closed=new DeploymentCoordinator($store,$authorize,$plan,$inspect,$start,$admit,$observe,$edge,$ready,true);
    check($closed->run($op['id'])['state']==='RESERVED'&&$startCalls===$before,'Missing preflight allowed start');
    $states=$store->db->query('SELECT state FROM journal_v2 ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN);
    foreach(['ACCEPTED','AUTHORIZED','RESERVED','START_UNCERTAIN','RUNNING','ADMISSION_PENDING','ASSIGNED','EDGE_PENDING','READY']as$phase)check(in_array($phase,$states,true),'Missing durable phase '.$phase);
    // Stale evidence, incomplete timer apply and generation mismatch never stay READY.
    $stale=true;check(!$coordinator->run($id)['ready'],'Stale observation accepted');$stale=false;
    $edgeReady=false;check(!$coordinator->run($id)['ready'],'Incomplete edge accepted');$edgeReady=true;
    $wrongGeneration=true;check(!$coordinator->run($id)['ready'],'Wrong edge generation accepted');$wrongGeneration=false;
    check($coordinator->run($id)['ready'],'Fresh recovery failed');
    $finality=false;check(!$coordinator->run($id)['ready'],'Missing finalized authority accepted');$finality=true;
    $allowed=false;check(!$coordinator->run($id)['ready'],'Revoked authority accepted');$allowed=true;
    $bad=$intent;$bad['image_digest']='sha256:'.str_repeat('b',64);[$conflict,$status]=$submit($bad);check($status===403&&$startCalls===1,'Idempotency conflict accepted');
    // Timeout after provider commit survives a new DB/coordinator instance, with no repeated start.
    $intent['idempotency_key']='test-timeout-002';$intent['application_id']=4;$running=false;$timeout=true;
    [$uncertain]=$submit($intent);check($uncertain['state']==='START_UNCERTAIN'&&!$uncertain['ready'],'Lost write uncertainty');
    $before=$startCalls;$store=new SqliteOperationStore($dir.'/operations.sqlite');$coordinator=$make();$timeout=false;
    check($coordinator->run($uncertain['operation_id'])['ready']&&$startCalls===$before,'Restart duplicated provider write');
    $intent['idempotency_key']='test-admission-004';$intent['application_id']=6;$mirrorFail=true;$op=$store->accept($account,$intent);
    $before=$startCalls;
    $pending=$coordinator->run($op['id']);
    check($pending['state']==='RESERVED'&&!$pending['ready']&&$startCalls===$before,'Pre-start admission failure allowed provider start');
    $store=new SqliteOperationStore($dir.'/operations.sqlite');$coordinator=$make();$mirrorFail=false;
    check($coordinator->run($op['id'])['ready']&&$startCalls===$before+1,'Admission recovery failed to start exactly once');
    // No known running provider after ambiguous start: remain uncertain forever, never restart.
    $intent['idempotency_key']='test-unknown-003';$intent['application_id']=5;$op=$store->accept($account,$intent);$running=false;$timeout=true;
    $uncertain=$coordinator->run($op['id']);$running=false;$before=$startCalls;
    check(!$coordinator->run($op['id'])['ready']&&$startCalls===$before,'Unknown outcome retried');
    // A legacy runtime with an identical numeric ID and digest cannot be adopted.
    $intent['idempotency_key']='test-registry-image';$intent['application_id']=1;$wrongImageScope=true;$timeout=false;
    $op=$store->accept($account,$intent);$result=$coordinator->run($op['id']);
    check(!$result['ready']&&$result['state']==='START_UNCERTAIN','Legacy image reference adopted');
    $before=$startCalls;$wrongImageScope=false;check($coordinator->run($op['id'])['ready']&&$startCalls===$before,'Scoped runtime recovery repeated start');
    // Invalid application IDs rejected at ingress.
    $intent['application_id']=0;[$r,$status]=$api->handleRequest('POST','/v1/developer/challenge',json_encode($intent),[]);check($status===400,'Protected app accepted');
    // Durable signed GET anti-replay and cross-account denial.
    $path='/v1/developer/deployments/'.$id;$nonce=str_repeat('9',64);$now=(string)time();$raw=implode("\n",['GREENWOOD-REQUEST-AUTH-V1','chain_id=greenwood-physical-v1','audience=https://controller.test','method=GET','path='.$path,'body_sha256='.hash('sha256',''),'nonce='.$nonce,'issued_at='.$now]);
    $headers=['X-Greenwood-Public-Key'=>bin2hex($public),'X-Greenwood-Nonce'=>$nonce,'X-Greenwood-Timestamp'=>$now,'X-Greenwood-Signature'=>bin2hex(sodium_crypto_sign_detached($raw,$secret))];
    check($signatures->verify('GET',$path,'',$headers)===$account,'GET signature parity failed');$rejected=false;try{$signatures->verify('GET',$path,'',$headers);}catch(Throwable){$rejected=true;}check($rejected,'Signed GET replay accepted');
    $other=sodium_crypto_sign_seed_keypair(str_repeat('z',32));$headers['X-Greenwood-Public-Key']=bin2hex(sodium_crypto_sign_publickey($other));$headers['X-Greenwood-Signature']=bin2hex(sodium_crypto_sign_detached($raw,sodium_crypto_sign_secretkey($other)));
    [$r,$status]=$api->handleRequest('GET',$path,'',$headers);check($status===404&&!isset($r['image_digest']),'Private operation leaked');
    $preflightFault='runtime';$test['idempotency_key']='runtime-preflight';$op=$store->accept($account,$test);$before=$startCalls;
    check($coordinator->run($op['id'])['state']==='START_UNCERTAIN'&&$startCalls===$before+1,'Runtime changed approved assignment');
    check($coordinator->run($op['id'])['state']==='START_UNCERTAIN'&&$startCalls===$before+1,'Runtime mismatch retried start');
    $disabledApi=new DeveloperRoutes($store,$challenges,$signatures,$coordinator,$limits,false);
    [$caps,$code]=$disabledApi->handleRequest('GET','/v1/developer/capabilities','',[]);
    check($code===200&&$caps['deployment_enabled']===false,'Disabled capability missing');
    [, $code]=$disabledApi->handleRequest('POST','/v1/developer/deployments','{}',[]);check($code===503,'Disabled writes opened');
    [, $code]=$disabledApi->handleRequest('GET',$path,'',[]);check($code===403,'Disabled controller skipped read authentication');
    $preflightFault=null;$approved=false;$reservations=0;
    $reserve=function($id,$p,$proof)use($preflight,&$reservations){$reservations++;return ['document'=>$preflight($id,$p,$proof)['document']];};
    $acknowledge=function($d)use(&$approved,$preflight,$plan,$authorize,$account,$test){
        if(!$approved)throw new RuntimeException('Allocation pending');
        return $preflight($d['operation_id'],$plan($d['operation_id'],$test,[]),$authorize($account,$test))['ack'];
    };
    $resumeCoordinator=new DeploymentCoordinator($store,$authorize,$plan,$inspect,$start,$admit,$observe,$edge,$ready,true,$reserve,null,$acknowledge);
    $test['idempotency_key']='approval-wait';$op=$store->accept($account,$test);$before=$startCalls;
    check($resumeCoordinator->run($op['id'])['state']==='RESERVED'&&$startCalls===$before,'Unapproved reservation started');
    $saved=json_decode($store->get($op['id'])['evidence'],true);check(isset($saved['assignment_document']),'Reservation not durably exportable');
    $headersFor=static function($method,$path,$kp) {
        $nonce=bin2hex(random_bytes(32));$now=(string)time();
        $raw=implode("\n",['GREENWOOD-REQUEST-AUTH-V1','chain_id=greenwood-physical-v1','audience=https://controller.test','method='.$method,'path='.$path,'body_sha256='.hash('sha256',''),'nonce='.$nonce,'issued_at='.$now]);
        return ['X-Greenwood-Public-Key'=>bin2hex(sodium_crypto_sign_publickey($kp)),'X-Greenwood-Nonce'=>$nonce,'X-Greenwood-Timestamp'=>$now,'X-Greenwood-Signature'=>bin2hex(sodium_crypto_sign_detached($raw,sodium_crypto_sign_secretkey($kp)))];
    };
    $resumeApi=new DeveloperRoutes($store,$challenges,$signatures,$resumeCoordinator,$limits,true);
    $export='/v1/developer/deployments/'.$op['id'].'/assignment';$resume='/v1/developer/deployments/'.$op['id'].'/resume';
    [$exported,$code]=$resumeApi->handleRequest('GET',$export,'',$headersFor('GET',$export,$key));
    check($code===200&&$exported['document']===$saved['assignment_document'],'Signed assignment export failed');
    [, $code]=$resumeApi->handleRequest('GET',$export,'',$headersFor('GET',$export,$other));check($code===404,'Cross-owner export leaked');
    [, $code]=$resumeApi->handleRequest('POST',$resume,'',$headersFor('POST',$resume,$other));check($code===404&&$startCalls===$before,'Cross-owner resume started');
    $approved=true;$resumeHeaders=$headersFor('POST',$resume,$key);
    [$result,$code]=$resumeApi->handleRequest('POST',$resume,'',$resumeHeaders);
    check($code===202&&$result['ready']&&$startCalls===$before+1&&$reservations===1,'Resume failed or changed reservation');
    [, $code]=$resumeApi->handleRequest('POST',$resume,'',$resumeHeaders);check($code===403,'Resume signature replay accepted');
    [$result]=$resumeApi->handleRequest('POST',$resume,'',$headersFor('POST',$resume,$key));
    check($result['ready']&&$startCalls===$before+1,'Resume duplicated start');
    echo "Orchestration integration: durable reservation export, owner-signed resume/replay/isolation, pre-start ACK/plan gate, runtime binding, idempotency, restart, revocation and readiness checks passed\n";
}finally{foreach(glob($dir.'/*')as$p)unlink($p);rmdir($dir);}
