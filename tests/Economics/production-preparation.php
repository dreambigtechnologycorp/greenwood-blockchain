<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
use Greenwood\Genesis\ProductionPreparation;
use Greenwood\Crypto\Ed25519KeyPair;
use Greenwood\Node\Configuration;
$f=fixture(true);
$t=['version'=>1,'chain_id'=>'production-candidate-test','previous_chain_id'=>'retired-test-chain',
    'retired_public_keys'=>[bin2hex(Ed25519KeyPair::generate()->getPublicKey())],'validators'=>[],'providers'=>[]];
foreach($f['validators'] as $id=>$key){$t['validators'][$id]=['public_key'=>bin2hex($key->getPublicKey()),'endpoint'=>'tcp://node-'.strtolower($id).'.example:9000'];$t['providers'][$id]=bin2hex(Ed25519KeyPair::generate()->getPublicKey());}
$r=ProductionPreparation::prepare($f['p'],$t);
check($r['status']==='CANDIDATE_ONLY'&&!$r['activation_authorized']&&!$r['formal_ratification_verified'],'Complete technical inputs do not authorize activation or assert ratification');
check($r['candidate']['accounts']===[],'No extra founder or migrated testnet allocation');
check($r['provider_public_keys']===$t['providers'],'Provider identities remain separate public deployment inputs');
$mutations=[
 static function(&$t){$t['chain_id']=$t['previous_chain_id'];},
 static function(&$t){$t['providers']['A']=$t['validators']['A']['public_key'];},
 static function(&$t){$t['retired_public_keys'][]=$t['providers']['A'];},
 static function(&$t){$t['validators']['B']['public_key']=$t['validators']['A']['public_key'];},
 static function(&$t){$t['validators']['A']['private_key']='never-accept-secret-input';},
 static function(&$t){$t['validators']['A']['endpoint']='tcp://host:9000;touch /tmp/example';},
 static function(&$t){$t['validators']['A']['endpoint']='tcp://host:65536';},
 static function(&$t){$t['validators']['B']['endpoint']=$t['validators']['A']['endpoint'];},
 static function(&$t){unset($t['providers']['C']);},
 static function(&$t){$t['retired_public_keys']=[];},
];
foreach($mutations as $mutate){$bad=$t;$mutate($bad);$v=ProductionPreparation::prepare($f['p'],$bad);check($v['status']==='BLOCKED'&&$v['candidate']===null,'Invalid public identities/topology cannot produce genesis candidate');}
$policy=$f['p'];$policy['private_key']='never-emit-secret-input';$blocked=ProductionPreparation::prepare($policy,$t);check($blocked['status']==='BLOCKED'&&!str_contains(json_encode($blocked),'never-emit-secret-input'),'Unexpected policy fields cannot leak into public candidate');
$policy=$f['p'];unset($policy['service_trust_policy']);check(ProductionPreparation::prepare($policy,$t)['status']==='BLOCKED','Legacy optional trust policy cannot enter preparation');
$policy=$f['p'];$policy['limits']['subsidies']=null;check(ProductionPreparation::prepare($policy,$t)['status']==='BLOCKED','Unresolved budgets cannot enter preparation');
$root=dirname(__DIR__,2);$template=json_decode(file_get_contents($root.'/docs/economics/PRODUCTION_POLICY.template.json'),true);$topology=json_decode(file_get_contents($root.'/docs/economics/PRODUCTION_TOPOLOGY.template.json'),true);
check(ProductionPreparation::prepare($template,$topology)['status']==='BLOCKED','Unratified checked-in templates cannot emit genesis');
$dir=sys_get_temp_dir().'/greenwood-public-candidate-'.bin2hex(random_bytes(8));mkdir($dir,0700);
try {
 file_put_contents($dir.'/genesis.json',json_encode($r['candidate']));[$g]=Configuration::load($dir.'/genesis.json');
 check(bin2hex($g->getGenesisHash())===$r['genesis_hash']&&bin2hex($g->initialState()->getStateRoot())===$r['initial_state_root'],'Candidate loads through actual node configuration with matching commitments');
 $reordered=$t;$reordered['validators']=array_reverse($t['validators'],true);$again=ProductionPreparation::prepare($f['p'],$reordered);
 check($again['genesis_hash']===$r['genesis_hash'],'Public topology input order cannot change canonical genesis');
 echo json_encode(['status'=>'PASS','checks'=>$checks,'activation_authorized'=>false,'permanent_genesis_created'=>false],JSON_PRETTY_PRINT)."\n";
}finally{unlink($dir.'/genesis.json');rmdir($dir);}
