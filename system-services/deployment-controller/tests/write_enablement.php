<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
require_once __DIR__.'/identity-fixture.php';
use Greenwood\DeploymentController\Deployment\WriteEnablement;
use Greenwood\DeploymentController\Auth\DeploymentIdentity;
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function denied(array $c,string $message):void {
    try {new WriteEnablement($c);} catch(Throwable $e) {check($e->getMessage()===$message,'Wrong rejection: '.$e->getMessage());return;}
    throw new RuntimeException('Unsafe configuration accepted: '.$message);
}
$provider=str_repeat('a',64);$account=str_repeat('b',64);
$c=['deployment_enabled'=>true,'provider_http'=>'unix:///run/greenwood-provider/operator.sock',
    'deployment_write_scope'=>['chain_id'=>DeploymentIdentity::chain(),'registry_contract_id'=>DeploymentIdentity::registry(),
        'application_id'=>1,'release_number'=>1,'service'=>'web','image_digest'=>'sha256:'.str_repeat('c',64),
        'provider_id'=>$provider,'account_id'=>$account,'issued_at'=>time()-1,'expires_at'=>time()+300]];
foreach (['node-1','node-4','edge','provider'] as $i=>$name) {
    $peer=['endpoint'=>'tcp://192.168.72.'.(100+$i).':19443','tls_name'=>$name.'.test','identity'=>$name==='provider'?$provider:$name];
    $pin=str_repeat((string)($i+1),64);
    $c['tls']['peer_identities'][$pin]=$peer['identity'];
    $role=match($name){'node-1','node-4'=>'assignment/'.$name,'edge'=>'edge-admission',default=>'provider/node-1'};
    $c['tls']['peer_uris'][$pin]='spiffe://'.DeploymentIdentity::chain().'/control-plane/'.$role;
    if(str_starts_with($name,'node-'))$c['assignment_receivers'][$name]=$peer;
    elseif($name==='edge')$c['edge_remote']=$peer;
    else $c['provider_policy']=['provider_node'=>'node-1','provider_id'=>$provider,'provider_endpoint'=>$peer['endpoint'],'provider_tls_name'=>$peer['tls_name']];
}
foreach(['node-1','node-2','node-3','node-6','node-7'] as $node){
    $v=$c;$v['provider_policy']['provider_node']=$node;
    $v['tls']['peer_uris'][str_repeat('4',64)]='spiffe://'.DeploymentIdentity::chain().'/control-plane/provider/'.$node;
    $gate=new WriteEnablement($v);check($gate->enabled(),'Write gate disabled');$gate->authorize($account,$v['deployment_write_scope']);
    $wrong=$v;$wrong['tls']['peer_uris'][str_repeat('4',64)]='spiffe://'.DeploymentIdentity::chain().'/control-plane/provider/node-5';
    denied($wrong,'PHYSICAL_PEER_ROLE_PIN_REQUIRED');
    $wrong=$v;$wrong['deployment_write_scope']['provider_id']=str_repeat('d',64);denied($wrong,'WRITE_PROVIDER_SCOPE_MISMATCH');
    $wrong=$v;$wrong['tls']['peer_uris'][str_repeat('4',64)]='spiffe://wrong-chain/control-plane/provider/'.$node;denied($wrong,'PHYSICAL_PEER_ROLE_PIN_REQUIRED');
}
foreach([null,'node-4','node-5','node-8',6,'node-06'] as $node){$v=$c;$v['provider_policy']['provider_node']=$node;denied($v,'EXPLICIT_PROVIDER_NODE_REQUIRED');}
$v=$c;unset($v['provider_policy']['provider_node']);denied($v,'EXPLICIT_PROVIDER_NODE_REQUIRED');
$v=$c;$v['provider_http']='http://192.168.72.105:8080';denied($v,'LOCAL_AUTHENTICATED_OPERATOR_REQUIRED');
$v=$c;$v['assignment_receivers']['node-4']['endpoint']=$v['assignment_receivers']['node-1']['endpoint'];denied($v,'DISTINCT_RECEIVERS_REQUIRED');
$v=$c;$v['deployment_write_scope']['expires_at']=time()-1;denied($v,'CURRENT_BOUNDED_WRITE_SCOPE_REQUIRED');
check(!(new WriteEnablement([]))->enabled(),'Missing activation flag enabled writes');
echo "Write enablement: five provider roles, explicit selection, scope/URI/chain pins, local socket and fail-closed topology passed\n";
