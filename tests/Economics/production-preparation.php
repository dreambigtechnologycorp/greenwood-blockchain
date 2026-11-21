<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Greenwood\Genesis\ProductionPreparation;
use Greenwood\Node\Configuration;
// Public-only synthetic byte strings for schema/commitment tests; no keypair,
// seed, private key or signature is generated or needed by this helper.
$checks=0;
function check(bool $value,string $message): void {global $checks;$checks++;if(!$value)throw new RuntimeException($message);}
function publicFixture(int $id): string {return sprintf('%064x',$id);}
$root=dirname(__DIR__,2);
$p=json_decode(file_get_contents($root.'/docs/economics/PRODUCTION_POLICY.template.json'),true,32,JSON_THROW_ON_ERROR);
$p=array_replace($p,[
    'signers'=>array_map('publicFixture',range(1,5)), 'guardians'=>[publicFixture(6)],
    'recovery_policy'=>['enabled'=>false], 'pause_blocks'=>10,
    'limits'=>['grants'=>'0','subsidies'=>'0','institute_treasury'=>'0','working_operations'=>'10000000000'],
    'large_threshold'=>'100000000000','operations_balance'=>'5000000000',
    'operations_payment'=>'500000000','operations_window'=>'10000000000',
    'bootstrap_units'=>'1000000000','operations_account'=>publicFixture(7),
    'fees'=>['transfer'=>'1000','governance'=>'10000','contract'=>'10000','compute'=>'10000'],
    'recipients'=>[publicFixture(7)=>'operations'],
]);
$f=['p'=>$p];
$t=['version'=>1,'chain_id'=>'production-candidate-test','previous_chain_id'=>'retired-test-chain',
    'retired_public_keys'=>[publicFixture(8)],'validators'=>[],'providers'=>[]];
foreach(['A','B','C'] as $i=>$id){
    $t['validators'][$id]=['public_key'=>publicFixture(10+$i),'endpoint'=>'http://node-'.($i+1).'.example:9000'];
    $t['providers'][$id]=publicFixture(20+$i);
}
$r=ProductionPreparation::prepare($f['p'],$t);
check($r['status']==='CANDIDATE_ONLY'&&!$r['activation_authorized']&&!$r['formal_ratification_verified'],'Complete technical inputs do not authorize activation or assert ratification');
check($r['candidate']['accounts']===[],'No extra founder or migrated testnet allocation');
check($r['provider_public_keys']===$t['providers'],'Provider identities remain separate public deployment inputs');
$seven=$t;$seven['providers']['D']=publicFixture(23);$seven['providers']['E']=publicFixture(24);
$expanded=ProductionPreparation::prepare($f['p'],$seven);
check($expanded['status']==='CANDIDATE_ONLY'&&!$expanded['activation_authorized'],'Five providers produce only a public candidate');
check($expanded['provider_public_keys']===$seven['providers'],'All five provider bindings survive preparation');
check(array_keys($expanded['candidate']['validators'])===['A','B','C'],'Additional providers never become validators');
check($expanded['genesis_hash']===$r['genesis_hash']&&$expanded['initial_state_root']===$r['initial_state_root'],'Provider deployment bindings do not change consensus or economic commitments');
$providerMutations=[
 static function(&$t){unset($t['providers']['D']);},
 static function(&$t){unset($t['providers']['A']);},
 static function(&$t){$t['providers']['F']=publicFixture(25);},
 static function(&$t){$t['providers']['F']=$t['providers']['E'];unset($t['providers']['E']);},
 static function(&$t){$t['providers']['D']=$t['providers']['A'];},
 static function(&$t){$t['providers']['E']=$t['providers']['D'];},
 static function(&$t){$t['providers']['E']=$t['validators']['C']['public_key'];},
 static function(&$t){$t['retired_public_keys'][]=$t['providers']['D'];},
 static function(&$t){$t['retired_public_keys'][]=$t['providers']['E'];},
 static function(&$t){$t['providers']['E']=null;},
 static function(&$t){$t['providers']['D']=['public_key'=>publicFixture(23),'private_key'=>'never-accept-secret-input'];},
 static function(&$t){$t['providers']['E']='malformed';},
 static function(&$t){$t['validators']['D']=['public_key'=>publicFixture(13),'endpoint'=>'http://node-6.example:9000'];},
 static function(&$t){$t['providers']=array_values($t['providers']);},
];
foreach($providerMutations as $mutate){
 $bad=$seven;$mutate($bad);$v=ProductionPreparation::prepare($f['p'],$bad);
 check($v['status']==='BLOCKED'&&$v['candidate']===null&&!str_contains(json_encode($v),'never-accept-secret-input'),'Invalid five-provider topology fails closed without leaking input');
}
$mutations=[
 static function(&$t){$t['validators']['A']['endpoint']='tcp://node-1.example:9201';},
 static function(&$t){$t['validators']['A']['endpoint']='https://node-1.example:9201';},
 static function(&$t){$t['validators']['A']['endpoint']='http://user:password@node-1.example:9201';},
 static function(&$t){$t['validators']['A']['endpoint']='http://node-1.example:9201/path';},
 static function(&$t){$t['validators']['A']['endpoint']='http://node-1.example:9201?query=1';},
 static function(&$t){$t['chain_id']=$t['previous_chain_id'];},
 static function(&$t){$t['providers']['A']=$t['validators']['A']['public_key'];},
 static function(&$t){$t['retired_public_keys'][]=$t['providers']['A'];},
 static function(&$t){$t['validators']['B']['public_key']=$t['validators']['A']['public_key'];},
 static function(&$t){$t['validators']['A']['private_key']='never-accept-secret-input';},
 static function(&$t){$t['validators']['A']['endpoint']='http://host:9000;touch /tmp/example';},
 static function(&$t){$t['validators']['A']['endpoint']='http://host:65536';},
 static function(&$t){$t['validators']['B']['endpoint']=$t['validators']['A']['endpoint'];},
 static function(&$t){unset($t['providers']['C']);},
 static function(&$t){$t['retired_public_keys']=[];},
];
foreach($mutations as $mutate){$bad=$t;$mutate($bad);$v=ProductionPreparation::prepare($f['p'],$bad);check($v['status']==='BLOCKED'&&$v['candidate']===null,'Invalid public identities/topology cannot produce genesis candidate');}
$policy=$f['p'];$policy['private_key']='never-emit-secret-input';$blocked=ProductionPreparation::prepare($policy,$t);check($blocked['status']==='BLOCKED'&&!str_contains(json_encode($blocked),'never-emit-secret-input'),'Unexpected policy fields cannot leak into public candidate');
$policy=$f['p'];unset($policy['service_trust_policy']);check(ProductionPreparation::prepare($policy,$t)['status']==='BLOCKED','Legacy optional trust policy cannot enter preparation');
$policy=$f['p'];$policy['limits']['subsidies']=null;check(ProductionPreparation::prepare($policy,$t)['status']==='BLOCKED','Unresolved budgets cannot enter preparation');
$root=dirname(__DIR__,2);$template=json_decode(file_get_contents($root.'/docs/economics/PRODUCTION_POLICY.template.json'),true);$topology=json_decode(file_get_contents($root.'/docs/economics/PRODUCTION_TOPOLOGY.template.json'),true);
check(array_keys($topology['providers'])===['A','B','C','D','E']&&array_keys($topology['validators'])===['A','B','C'],'Production template defaults to five providers and three validators');
check(ProductionPreparation::prepare($template,$topology)['status']==='BLOCKED','Unratified checked-in templates cannot emit genesis');
$dir=sys_get_temp_dir().'/greenwood-public-candidate-'.bin2hex(random_bytes(8));mkdir($dir,0700);
try {
 file_put_contents($dir.'/genesis.json',json_encode($expanded['candidate']));[$g]=Configuration::load($dir.'/genesis.json');
 check(bin2hex($g->getGenesisHash())===$r['genesis_hash']&&bin2hex($g->initialState()->getStateRoot())===$r['initial_state_root'],'Candidate loads through actual node configuration with matching commitments');
 $reordered=$seven;$reordered['validators']=array_reverse($seven['validators'],true);$reordered['providers']=array_reverse($seven['providers'],true);$again=ProductionPreparation::prepare($f['p'],$reordered);
 check($again['genesis_hash']===$r['genesis_hash'],'Public topology input order cannot change canonical genesis');
 check($again['provider_public_keys']===$reordered['providers'],'Reordered provider labels keep the correct key associations');
 file_put_contents($dir.'/policy.json',json_encode($f['p']));file_put_contents($dir.'/topology.json',json_encode($seven));
 $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/economics-cli.php').' prepare-production-genesis '.escapeshellarg($dir.'/policy.json').' '.escapeshellarg($dir.'/topology.json');
 exec($command,$lines,$code);$cli=json_decode(implode("\n",$lines),true,32,JSON_THROW_ON_ERROR);
 check($code===0&&$cli['provider_public_keys']===$seven['providers']&&$cli['genesis_hash']===$expanded['genesis_hash'],'CLI preserves all five public provider bindings and commitments');
 echo json_encode(['status'=>'PASS','checks'=>$checks,'activation_authorized'=>false,'permanent_genesis_created'=>false],JSON_PRETTY_PRINT)."\n";
}finally{foreach(['genesis.json','policy.json','topology.json'] as $file)if(is_file($dir.'/'.$file))unlink($dir.'/'.$file);rmdir($dir);}
