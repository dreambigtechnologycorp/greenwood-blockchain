<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Greenwood\SystemServices\RegistryAuth\{RegistryAuthService,Database};
use Greenwood\DeploymentController\Auth\DeploymentIdentity;
use Greenwood\DeploymentController\Registry\GreenwoodRpc;

function check(bool $value,string $label):void {if(!$value)throw new RuntimeException($label);}
$f=json_decode(file_get_contents(dirname(__DIR__,2).'/deployment-controller/tests/fixtures/registry-readonly.json'),true);
$f['metadata']['contract_id']=$f['metadata']['wasm_hash']=DeploymentIdentity::REGISTRY;
foreach($f['queries']as&$q)$q['contract_id']=DeploymentIdentity::REGISTRY;unset($q);
$mode='valid';$capabilities=15;$queries=0;
$rpc=new GreenwoodRpc('http://127.0.0.1',DeploymentIdentity::CHAIN,static function($method,$path,$body)use($f,&$mode,&$capabilities,&$queries):array{
    if($path==='/v1/chain/status'){
        $r=$f['before'];if($mode==='syncing')$r['syncing']=true;
        if($mode==='moving'&&$queries>0)$r['state_root']=str_repeat('e',64);
    }elseif($method==='GET'){
        $r=$f['metadata'];if($mode==='wrong-code')$r['wasm_hash']=str_repeat('0',64);
    }else{
        $queries++;$name=$body['method'];$r=$f['queries'][$name==='qau'?'qau_controller':$name];
        if($name==='qau')$r['return_data']=bin2hex(pack('n',$capabilities));
        if($mode==='stale')$r['finalized_height']--;
        if($mode==='unknown'&&$name==='qap')$r['return_data']='';
        if($mode==='wrong-contract')$r['contract_id']=str_repeat('0',64);
    }
    return [200,json_encode($r),[]];
});
umask(0077);$root=sys_get_temp_dir().'/gw-registry-auth-'.bin2hex(random_bytes(8));mkdir($root,0700);
$db=Database::open($root.'/auth.sqlite');$key=sodium_crypto_sign_keypair();$developer=sodium_crypto_sign_keypair();
$service=new RegistryAuthService($db,DeploymentIdentity::CHAIN,'http://127.0.0.1',DeploymentIdentity::REGISTRY,$key,$rpc);
$authorize=static function(array $actions)use($service,$developer):array{
    $challenge=$service->challenge(1,'web',$actions);
    return $service->authorize($challenge['id'],sodium_crypto_sign_publickey($developer),
        sodium_crypto_sign_detached($challenge['canonical_bytes'],sodium_crypto_sign_secretkey($developer)));
};
try{
    $repository='chains/'.DeploymentIdentity::CHAIN.'/registries/'.DeploymentIdentity::REGISTRY.'/apps/1/web';
    $scope='repository:'.$repository.':';
    $challenge=$service->challenge(1,'web',['pull']);
    check($challenge['registry_contract_id']===DeploymentIdentity::REGISTRY,'Missing registry binding');
    check(str_starts_with($challenge['canonical_bytes'],"GREENWOOD-REGISTRY-AUTH-V2\nchain_id=".DeploymentIdentity::CHAIN."\nregistry_contract_id=".DeploymentIdentity::REGISTRY."\n"),'Canonical registry binding missing');
    $session=$authorize(['push','pull']);check($session['success'],'Authorized session rejected');
    $token=$service->token($session['credential'],'greenwood-registry',$scope.'pull,push');
    check(isset($token['token']),'Authorized token rejected');
    $claims=json_decode(base64_decode(strtr(explode('.',$token['token'])[1],'-_','+/')),true);
    check($token['expires_in']===120,'Bearer lifetime changed');
    check(strtotime($token['issued_at'])===$claims['iat'],'Issued-at differs from signed claims');
    check($claims['exp']-$claims['iat']===$token['expires_in'],'Advertised expiry differs from JWT');
    check($claims['exp']<=$session['expires_at'],'Token outlives session');
    check($session['expires_at']-time()>1700,'Push session too short');
    $renewed=$service->token($session['credential'],'greenwood-registry',$scope.'pull,push');
    check(isset($renewed['token'])&&$renewed['token']!==$token['token'],'Session cannot exchange another token');
    $short=$authorize(['pull']);
    $update=$db->prepare('UPDATE registry_sessions SET expires_at=:expiry WHERE credential_hash=:hash');
    $update->execute([':expiry'=>time()+90,':hash'=>hash('sha256',$short['credential'])]);
    $bounded=$service->token($short['credential'],'greenwood-registry',$scope.'pull');
    check($bounded['expires_in']>=60&&$bounded['expires_in']<=90,'Near-expiry token not bounded');
    $update->execute([':expiry'=>time()+30,':hash'=>hash('sha256',$short['credential'])]);
    check(isset($service->token($short['credential'],'greenwood-registry',$scope.'pull')['error']),'Sub-minute token issued');
    $update->execute([':expiry'=>time()-1,':hash'=>hash('sha256',$short['credential'])]);
    check(isset($service->token($short['credential'],'greenwood-registry',$scope.'pull')['error']),'Expired session renewed');
    [$header,$payload,$signature]=explode('.',$token['token']);
    check(sodium_crypto_sign_verify_detached(base64_decode(strtr($signature,'-_','+/')),$header.'.'.$payload,sodium_crypto_sign_publickey($key)),'Issuer signature invalid');
    check(isset($service->token($session['credential'],'greenwood-registry',str_replace('/apps/1/','/apps/2/',$scope).'pull')['error']),'Cross-app token accepted');
    check(isset($service->token('invalid','greenwood-registry',$scope.'pull')['error']),'Invalid session accepted');
    foreach(['repository:apps/1/web:pull',str_replace(DeploymentIdentity::REGISTRY,str_repeat('0',64),$scope).'pull']as$bad){
        $rejected=false;try{$service->token($session['credential'],'greenwood-registry',$bad);}catch(InvalidArgumentException){$rejected=true;}
        check($rejected,'Legacy or cross-registry scope accepted');
    }
    $empty=$service->token($session['credential'],'greenwood-registry','');
    check(isset($empty['token']),'Authenticated empty-scope exchange denied');
    $payload=json_decode(base64_decode(strtr(explode('.',$empty['token'])[1],'-_','+/')),true);
    check($payload['access'][0]['actions']===[],'Empty scope granted permissions');
    $capabilities=0;check(!$authorize(['pull'])['success'],'Unauthorized pull session accepted');
    check(isset($service->token($session['credential'],'greenwood-registry',$scope.'pull')['error']),'Revoked authority retained token access');
    $capabilities=2;check(!$authorize(['push'])['success'],'DEPLOY granted push');
    check($authorize(['pull'])['success'],'DEPLOY scoped pull denied');
    check(isset($service->token($session['credential'],'greenwood-registry',$scope.'push')['error']),'Revoked PUBLISH retained push');
    $capabilities=15;
    foreach(['syncing','moving','wrong-code','stale','unknown','wrong-contract']as$mode){
        $queries=0;check(!$authorize(['pull'])['success'],'Accepted '.$mode);
    }
    echo "Registry issuer: signed scoped token, cross-app denial, invalid session, unauthorized pull, revoked permissions and incoherent finalized authority checks passed\n";
}finally{
    unset($service,$db,$authorize);
    foreach(glob($root.'/*')as$file)unlink($file);rmdir($root);
}
