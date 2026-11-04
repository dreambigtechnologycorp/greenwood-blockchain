<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Greenwood\DeploymentController\Auth\FinalizedAuthority;
use Greenwood\DeploymentController\Registry\GreenwoodRpc;
// Synthetic corrected-registry protocol fixture derived from captured read-only response shapes.
// Rebinding fixture identities is local testing only, not evidence of a finalized cutover.
// This is deterministic local parity testing, not current physical-chain authority.
$f=json_decode(file_get_contents(__DIR__.'/fixtures/registry-readonly.json'),true,32,JSON_THROW_ON_ERROR);
$f['metadata']['contract_id']=$f['metadata']['wasm_hash']=FinalizedAuthority::CONTRACT;
foreach($f['queries']as&$q)$q['contract_id']=FinalizedAuthority::CONTRACT;unset($q);
$account=substr($f['queries']['qap']['return_data'],8,64);$digest='sha256:'.$f['queries']['qrs']['return_data'];
$mode='valid';$queries=0;
$rpc=new GreenwoodRpc('http://10.77.0.100:9201','greenwood-physical-v1',static function($method,$path,$body,$timeout)use($f,$account,&$mode,&$queries):array{
    if($path==='/v1/chain/status'){
        $r=$f['before'];if($mode==='syncing')$r['syncing']=true;
        if($mode==='moving'&&$queries>0)$r['state_root']=str_repeat('e',64);
    }elseif($method==='GET'){
        $r=$f['metadata'];if($mode==='wrong-code')$r['wasm_hash']=str_repeat('0',64);
    }else{
        if($method!=='POST'||$path!=='/v1/contracts/'.FinalizedAuthority::CONTRACT.'/call')throw new RuntimeException('Unexpected RPC route');
        $queries++;$name=$body['method'];$key=$name;
        if($name==='qau')$key=substr(bin2hex(base64_decode($body['calldata'])),8)===$account?'qau_controller':'qau_unrelated';
        $r=$f['queries'][$key];
        if($mode==='old-query')$r['finalized_height']--;
        if($mode==='wrong-contract')$r['contract_id']=str_repeat('0',64);
        if($mode==='wrong-method')$r['method']='qac';
        if($mode==='wrong-digest'&&$name==='qrs')$r['return_data']=str_repeat('0',64);
    }
    return [200,json_encode($r),[]];
});
$authority=new FinalizedAuthority($rpc);$intent=['chain_id'=>'greenwood-physical-v1','registry_contract_id'=>FinalizedAuthority::CONTRACT,'application_id'=>2,'service'=>'web','release_number'=>2,'image_digest'=>$digest];
$proof=$authority->verify($account,$intent);
if(!$proof['authorized']||$proof['finalized_height']!==$f['after']['finalized_height'])throw new RuntimeException('Source parity failed');
foreach(['old-query','wrong-contract','wrong-method','wrong-digest','syncing','moving','wrong-code','unauthorized']as$mode){
    $queries=0;$failed=false;try{$authority->verify($mode==='unauthorized'?str_repeat('0',64):$account,$intent);}catch(Throwable){$failed=true;}
    if(!$failed)throw new RuntimeException('Accepted '.$mode);
}
echo "Registry authority: synthetic ABI response parity; stale query height, wrong contract/method/digest/code, syncing, incoherent state and unauthorized account rejected\n";
