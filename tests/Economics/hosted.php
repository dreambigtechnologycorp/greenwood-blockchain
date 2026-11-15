<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
use Greenwood\Crypto\{Ed25519KeyPair,ProtocolHash};
use Greenwood\Economics\{CanonicalJson,ServiceSettlement,ServiceAuthorization,HostedServiceBinding,HostedFundingGuard};
use Greenwood\Contracts\{ContractId,ContractTransactionExecutor};
use Greenwood\Transaction\{Envelope,DeployContract,CallContract,TransferU64};
use Greenwood\Compute\Protocol\ProviderId;
function tx($s,$k,$type,$payload,$chain){$t=new Envelope(1,$chain,addr($k),$s->getAccount(addr($k))->getNonce(),$type,$payload,'',$k->getPublicKey());return $t->setSignature($k->sign($t->getSigningDigest()));}
function finance($s,$k,$a,$height,$chain){$s->applyTransaction(ServiceAuthorization::transaction($chain,hex2bin($s->economicSummary()['genesis']),$s->getAccount(addr($k))->getNonce(),$a,$k),$chain,$height);}
function signed(array $e,array $keys,string $kind): array{return array_map(fn($k)=>ServiceAuthorization::approval($kind,$e,$k)['approval'],$keys);}
try{
$f=fixture();$s=$f['g']->initialState();$chain=$f['g']->getChainId();$provider=Ed25519KeyPair::generate();$attestors=[Ed25519KeyPair::generate(),Ed25519KeyPair::generate()];$wasm=file_get_contents($argv[1]??'/tmp/greenwood-hosted-registry.wasm');$registry=ContractId::fromWasm($wasm)->bytes;
$s->applyTransaction(tx($s,$f['beneficiary'],2,(new DeployContract($wasm,'',0,1000000))->serialize(),$chain),$chain,1);
$call=function($state,$key,$method,$data,$height)use($registry,$chain){$state->applyTransaction(tx($state,$key,3,(new CallContract($registry,$method,$data,0,1000000))->serialize(),$chain),$chain,$height);};
$call($s,$f['beneficiary'],'cre','',1);$digest=str_repeat('d',32);$call($s,$f['beneficiary'],'pub',pack('Nn',1,1).chr(3).'web'.$digest,1);$call($s,$f['beneficiary'],'act',pack('NN',1,1),1);
$r=ContractTransactionExecutor::query($s,$registry,'qap',pack('N',1),1000000);check($r->runtime->isSuccess() && strlen($r->overlay->effects()['returnValue'])===80,'Actual WASM registry registration and release');
$p=['id'=>str_repeat('a',64),'adapter'=>'hosted_cpu_v1','registry_contract_id'=>bin2hex($registry),'registry_code_hash'=>bin2hex($registry),'provider_key'=>bin2hex($provider->getPublicKey()),'service_id'=>str_repeat('b',64),'catalog_hash'=>'',
 'start_height'=>20161,'end_height'=>50000,'subsidy_percent'=>80,'threshold'=>2,'gru_scale'=>1,'budget_units'=>'10000','beneficiary_budget_units'=>'10000','max_gru_units'=>'10000','gru_per_cpu_ms_num'=>'1','gru_per_cpu_ms_den'=>'1','beneficiaries'=>[bin2hex(addr($f['beneficiary']))],'attestors'=>array_map(fn($k)=>bin2hex($k->getPublicKey()),$attestors)];$p['catalog_hash']=ServiceSettlement::catalogHash($p);
act($s,$f['signers'][0],['action'=>'propose','kind'=>'service_program','reserve'=>'subsidies','nonce'=>0,'expires'=>50000,'program'=>$p],1,$chain);$proposal=array_key_last($s->economicSummary()['proposals']);approve($s,$f,$proposal,4,1);advance($s,20160);act($s,$f['signers'][0],['action'=>'execute','proposal'=>$proposal],20161,$chain);
$s->applyTransaction(tx($s,$f['ops'],11,(new TransferU64(addr($f['beneficiary']),10000))->serialize(),$chain),$chain,20161);
$a=['chain_id'=>$chain,'registry_contract_id'=>bin2hex($registry),'application_id'=>1,'service'=>'web','release_id'=>1,'provider_id'=>bin2hex(ProviderId::fromPublicKey($provider->getPublicKey())->toBytes()),'provider_address'=>'10.1.2.3','ingress_port'=>11000,'instance_id'=>'app-1-web','image_digest'=>'sha256:'.bin2hex($digest),'provider_endpoint'=>'tcp://10.1.2.3:5000','provider_tls_name'=>'provider.local'];
$d=['chain_id'=>$chain,'registry_contract_id'=>bin2hex($registry),'account_id'=>bin2hex(addr($f['beneficiary'])),'operation_id'=>str_repeat('e',32),'version'=>1,'previous_generation'=>null,'assignment'=>$a];
$h=['document'=>$d,'start_height'=>20162,'cpu_budget_ms'=>'1000','max_payment_units'=>'1000','authority_epoch'=>1];$end=20172;
$q=['chain'=>$chain,'genesis'=>$s->economicSummary()['genesis'],'gru_scale'=>1,'issued_height'=>20161,'expiry_height'=>20200,'payer'=>bin2hex(addr($f['beneficiary'])),'provider'=>bin2hex(addr($provider)),'service_id'=>$p['service_id'],'catalog_hash'=>$p['catalog_hash'],'price_kind'=>'provider_gwd','gwd_units_per_gru_num'=>'1','gwd_units_per_gru_den'=>'1','max_gru_units'=>'1000','max_gwd_units'=>'1000','binding_hash'=>HostedServiceBinding::hash($h+['end_height'=>$end])];
$offer=function($hosted,$finish,$nonce)use($p,$q,$provider){$quote=$q;$quote['binding_hash']=HostedServiceBinding::hash($hosted+['end_height'=>$finish]);$quote['offer_nonce']=$nonce;return ['action'=>'fund','program'=>$p['id'],'hosted'=>$hosted,'end_height'=>$finish,'quote'=>CanonicalJson::encode($quote),'quote_approvals'=>signed($quote,[$provider],'quote')];};
$fund=$offer($h,$end,'first');$before=$s->getStateRoot();$bad=$fund;$bad['hosted']['document']['assignment']['image_digest']='sha256:'.str_repeat('0',64);rejects(fn()=>finance($s,$f['beneficiary'],$bad,20161,$chain),'wrong active release digest');check($s->getStateRoot()===$before,'Hosted invalid funding atomic');
$bad=$fund;$bad['hosted']['document']['assignment']['provider_id']=str_repeat('0',64);rejects(fn()=>finance($s,$f['beneficiary'],$bad,20161,$chain),'wrong provider assignment');
$bad=$fund;$bad['hosted']['authority_epoch']=2;rejects(fn()=>finance($s,$f['beneficiary'],$bad,20161,$chain),'stale ownership epoch');
$bad=$fund;$bad['hosted']['start_height']++;rejects(fn()=>finance($s,$f['beneficiary'],$bad,20161,$chain),'provider quote fixes interval');
finance($s,$f['beneficiary'],$fund,20161,$chain);$id=array_key_last($s->economicSummary()['services']['commitments']);check($s->getAccount(addr($f['beneficiary']))->getBalance()===9800,'Hosted customer escrow');
check($s->economicSummary()['services']['programs'][$p['id']]['committed']===800,'Shared protected subsidy commitment');
$duplicate=$offer($h,$end,'duplicate');rejects(fn()=>finance($s,$f['beneficiary'],$duplicate,20161,$chain),'overlapping same interval');
$overlap=$h;$overlap['start_height']++;$overlap['document']['version']=2;$overlap['document']['previous_generation']=HostedServiceBinding::generation($d);$other=$offer($overlap,$end+1,'next-generation');rejects(fn()=>finance($s,$f['beneficiary'],$other,20161,$chain),'new assignment generation cannot double bill same resource');
// Adjacent renewal is independently funded and preserves original interval identity.
$next=$h;$next['start_height']=$end;$renew=$offer($next,$end+10,'renewal');finance($s,$f['beneficiary'],$renew,20161,$chain);$nextId=array_key_last($s->economicSummary()['services']['commitments']);check($nextId!==$id,'Recurring interval has independent financial identity');
$generation=HostedServiceBinding::generation($d);$viewHeight=20161;
$read=function($gen)use(&$s,&$viewHeight,$chain,$generation,$id,$nextId){$snapshot=$s->economicSummary();$chosen=$viewHeight<20172?$id:$nextId;return ['chain_id'=>$chain,'genesis_hash'=>$snapshot['genesis'],'finalized_height'=>$viewHeight,'state_root'=>bin2hex($s->getStateRoot()),'finalized_hash'=>str_repeat('f',64),'commitment_id'=>$chosen,'commitment'=>$snapshot['services']['commitments'][$chosen]];};
$guard=new HostedFundingGuard($read,$chain,$s->economicSummary()['genesis'],bin2hex($provider->getPublicKey()));rejects(fn()=>$guard->require($d),'funding must finalize before activation');$viewHeight=20162;
$auth=$guard->require($d);check($auth['commitment_id']===$id && !$auth['payment_finalized'],'Service authorization is distinct from payment');
$restartGuard=new HostedFundingGuard($read,$chain,$s->economicSummary()['genesis'],bin2hex($provider->getPublicKey()));check($restartGuard->require($d)['commitment_id']===$id,'Restart reconciles same interval without creating payment');
$viewHeight=20172;check($guard->require($d)['commitment_id']===$nextId,'Renewal selected at exact boundary');
$viewHeight=20182;$restricted=0;$stopped=0;$result=$guard->reconcile($d,function()use(&$restricted){$restricted++;},function()use(&$stopped){$stopped++;});check(!$result['authorized'] && $restricted===1 && $stopped===1,'Expired funding restricts and stops service');
$down=new HostedFundingGuard(fn()=>throw new RuntimeException('unavailable'),$chain,$s->economicSummary()['genesis'],bin2hex($provider->getPublicKey()));$result=$down->reconcile($d,function()use(&$restricted){$restricted++;},function()use(&$stopped){$stopped++;});check(!$result['authorized']&&$restricted===2&&$stopped===2,'Unavailable funding denies continuation');
// Real API DTO and actual WASM authority checks over an isolated finalized-state fixture.
// This is not a certificate-backed service-history or distributed-execution test.
if(extension_loaded('pdo_sqlite')){
 $dir=sys_get_temp_dir().'/greenwood-hosted-rpc-'.bin2hex(random_bytes(8));$index=new \Greenwood\Persistence\StateSqlite($dir.'/state.sqlite');
 try{
  $rpcChain=new \Greenwood\Node\Chain($f['g'],new \Greenwood\Persistence\BlockFilesystem($dir.'/blocks'),$index);$rpcState=clone $s;advance($rpcState,20162);
  $setView=function($state,$height)use($rpcChain,$chain,$f){$rpcChain->state=$state;$rpcChain->tip=new \Greenwood\Block\Block(new \Greenwood\Block\Header(1,$chain,$height,0,str_repeat('x',32),str_repeat('y',32),str_repeat('z',32),$state->getStateRoot(),addr(array_values($f['validators'])[0]),$f['g']->getGenesisHash(),0),[]);};$setView($rpcState,20162);
  $api=new \Greenwood\Rpc\HttpApi($rpcChain,fn($s)=>'',fn($s)=>false,fn()=>0,fn()=>'validator',fn()=>true,fn()=>0,new \Greenwood\Node\ChainConfig());
  $response=$api->handle('GET','/v1/economics/hosted/funding/'.$generation,'');$view=json_decode($response->body,true);check($response->status===200&&$view['commitment_id']===$id,'Actual RPC selects active hosted interval');
  $apiGuard=new HostedFundingGuard(function($gen)use($api){$r=$api->handle('GET','/v1/economics/hosted/funding/'.$gen,'');if($r->status!==200)throw new RuntimeException('Funding denied');return json_decode($r->body,true);},$chain,$s->economicSummary()['genesis'],bin2hex($provider->getPublicKey()));
  check($apiGuard->require($d)['commitment_id']===$id,'Runtime guard accepts actual RPC finalized-view fixture');
  $dto=json_decode($api->handle('GET','/v1/chain/status','')->body,true);check($dto['supply']['categories']['native_service_escrow']==='2000','Recurring escrows appear once in native supply');
  $call($rpcState,$f['beneficiary'],'pro',pack('N',1).addr($f['other']),20163);$call($rpcState,$f['other'],'acc',pack('N',1),20163);advance($rpcState,20163);$setView($rpcState,20163);
  rejects(fn()=>$apiGuard->require($d),'Actual RPC refuses continued service after WASM ownership revocation');
 }finally{$index->close();$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($dir);}
}
$e=['chain'=>$chain,'genesis'=>$s->economicSummary()['genesis'],'commitment'=>$id,'catalog_hash'=>$p['catalog_hash'],'assignment_generation'=>$generation,'start_height'=>20162,'end_height'=>$end,'cpu_ms'=>'750','measurement_hash'=>str_repeat('1',64),'runtime_lineage_hash'=>str_repeat('2',64)];$usage=['action'=>'verify_usage','commitment'=>$id,'evidence'=>$e,'approvals'=>signed($e,$attestors,'usage')];
rejects(fn()=>finance($s,$provider,$usage,20161,$chain),'unended interval cannot bill');advance($s,20172);
$bad=$usage;$bad['approvals']=signed($e,[$provider],'usage');rejects(fn()=>finance($s,$provider,$bad,20173,$chain),'provider cannot authorize own hosted consumption');
$bad=$usage;$bad['evidence']['cpu_ms']='1001';$bad['approvals']=signed($bad['evidence'],$attestors,'usage');rejects(fn()=>finance($s,$provider,$bad,20173,$chain),'hosted charge exceeds resource budget');
finance($s,$provider,$usage,20173,$chain);rejects(fn()=>finance($s,$provider,$usage,20173,$chain),'duplicate interval usage');$replica=clone $s;advance($s,24492);advance($replica,24492);
$settle=ServiceAuthorization::transaction($chain,$f['g']->getGenesisHash(),$s->getAccount(addr($provider))->getNonce(),['action'=>'settle','commitment'=>$id],$provider);$s->applyTransaction($settle,$chain,24493);$replica->applyTransaction($settle,$chain,24493);check($s->getStateRoot()===$replica->getStateRoot(),'Hosted settlement deterministic local replay');
check($s->getAccount(addr($provider))->getBalance()===750,'Shared engine pays hosted CPU charge');check($s->economicSummary()['services']['commitments'][$id]['payment']['subsidy_units']===600,'Hosted subsidy actual usage');rejects(fn()=>finance($s,$provider,['action'=>'settle','commitment'=>$id],24493,$chain),'provider crash/retry cannot pay twice');
// Next interval had no attested measurements; outage returns both funding sources.
advance($s,24502);finance($s,$f['beneficiary'],['action'=>'refund','commitment'=>$nextId],24503,$chain);check($s->getAccount(addr($f['beneficiary']))->getBalance()===9850,'Renewal outage returns unused user contribution');
check($s->economicSummary()['services']['programs'][$p['id']]['spent']===600,'Only one verified interval consumes protected program budget');
// Registry revocation is enforced by the actual contract, even with an otherwise valid quote.
$revoked=clone $s;$call($revoked,$f['beneficiary'],'pro',pack('N',1).addr($f['other']),24503);$call($revoked,$f['other'],'acc',pack('N',1),24503);$late=$h;$late['start_height']=24510;$fresh=$offer($late,24520,'revoked');$newQuote=json_decode($fresh['quote'],true);$newQuote['issued_height']=24503;$newQuote['expiry_height']=24530;$fresh['quote']=CanonicalJson::encode($newQuote);$fresh['quote_approvals']=signed($newQuote,[$provider],'quote');rejects(fn()=>finance($revoked,$f['beneficiary'],$fresh,24503,$chain),'actual WASM ownership transfer invalidates old hosted authorization');
echo json_encode(['status'=>'PASS','checks'=>$checks,'actual_registry_wasm'=>true,'distributed_execution_tested'=>false,'independent_metering_qualified'=>false,'mainnet_activation'=>false,'seconds'=>round(microtime(true)-$started,3)],JSON_PRETTY_PRINT)."\n";
}catch(Throwable $e){fwrite(STDERR,$e."\n");exit(1);}
