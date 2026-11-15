<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
use Greenwood\Economics\{Policy,Amount,CanonicalJson};
$f=fixture(true);$f['p']['allocations']=Policy::PRODUCTION_ALLOCATION;
$g=new \Greenwood\Genesis\Genesis('greenwood-production-allocation-local-test');foreach($f['validators'] as $name=>$key)$g->addValidator($name,$key);$g->configureEconomics($f['p']);
$s=$g->initialState();$sum=$s->economicSummary();
check(Policy::PRODUCTION_ALLOCATION===['security_emissions'=>'150000000000000','grants'=>'250000000000000','subsidies'=>'300000000000000','institute_treasury'=>'250000000000000','working_operations'=>'50000000000000'],'Selected allocation exactly matches approved production proportions');
$total=0;foreach(Policy::PRODUCTION_ALLOCATION as $reserve=>$amount){$total=Amount::add($total,Amount::integer($amount));check($sum['balances'][$reserve]===Amount::integer($amount)-($reserve==='working_operations'?Amount::integer($f['p']['bootstrap_units']):0),'Each reserve starts with exact allocation minus explicit operational bootstrap');}
check($total===Amount::ISSUANCE&&$total===1000000000000000,'All genesis GWD has exactly six decimals and fixed total supply');
$template=json_decode(file_get_contents(dirname(__DIR__,2).'/docs/economics/PRODUCTION_POLICY.template.json'),true);
check($template['allocations']===Policy::PRODUCTION_ALLOCATION,'Production template cannot silently select a historical candidate');
rejects(fn()=>Policy::validate($template),'Unratified identities and limits cannot form a permanent genesis');
$dir=sys_get_temp_dir().'/greenwood-allocation-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$run=static function($genesis)use($dir):int{$document=json_decode($genesis->toJson(),true);foreach($document['validators'] as &$validator)$validator['endpoint']='tcp://127.0.0.1:1';unset($validator);file_put_contents($dir.'/genesis.json',json_encode($document));$proc=proc_open([PHP_BINARY,dirname(__DIR__,2).'/scripts/economics-cli.php','validate-production-genesis',$dir.'/genesis.json'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return proc_close($proc);};
try{
 check($run($g)===0,'Production validation accepts selected allocation with complete synthetic governance fixtures');
 $legacy=$f['p'];unset($legacy['service_trust_policy']);$legacyGenesis=new \Greenwood\Genesis\Genesis('legacy-policy-replay');foreach($f['validators'] as $name=>$key)$legacyGenesis->addValidator($name,$key);$legacyGenesis->configureEconomics($legacy);check($run($legacyGenesis)!==0,'Historical policy remains replayable but cannot activate fresh production');
 $bad=$f['p'];$bad['service_trust_policy']='optional';rejects(fn()=>Policy::validate($bad),'Unknown trust policy is rejected');
 $old=$f['p'];$old['allocations']['security_emissions']='100000000000000';$old['allocations']['institute_treasury']='300000000000000';$other=new \Greenwood\Genesis\Genesis('greenwood-production-allocation-local-test');foreach($f['validators'] as $name=>$key)$other->addValidator($name,$key);$other->configureEconomics($old);
 check($run($other)!==0,'Fixed supply alone cannot authorize a different fresh production allocation');
 check($other->getGenesisHash()!==$g->getGenesisHash(),'Allocation is committed into chain identity');
 echo json_encode(['status'=>'PASS','checks'=>$checks,'allocation'=>'15-25-30-25-5','permanent_genesis_created'=>false,'formal_ratification'=>false],JSON_PRETTY_PRINT)."\n";
}finally{unlink($dir.'/genesis.json');rmdir($dir);}
