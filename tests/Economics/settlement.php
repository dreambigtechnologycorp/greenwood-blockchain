<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
use Greenwood\Crypto\{Ed25519KeyPair,ProtocolHash};
use Greenwood\Economics\{CanonicalJson,Amount,ServiceSettlement};
use Greenwood\Compute\Protocol\{ComputeRequest,ComputeAssign,ComputeReceipt,ComputeReceiptV2,ComputeStatus,ProviderId};
use Greenwood\Transaction\{Envelope,TransferU64};
function signedTx($s,$key,$type,$payload,$chain): Envelope {
    $tx=new Envelope(1,$chain,addr($key),$s->getAccount(addr($key))->getNonce(),$type,$payload,'',$key->getPublicKey());return $tx->setSignature($key->sign($tx->getSigningDigest()));
}
function service($s,$key,$a,$height,$chain): void {
    $s->applyTransaction(\Greenwood\Economics\ServiceAuthorization::transaction($chain,hex2bin($s->economicSummary()['genesis']),$s->getAccount(addr($key))->getNonce(),$a,$key),$chain,$height);
}
function signatures(array $body,array $keys,string $domain): array {
    $digest=ProtocolHash::hash($domain,CanonicalJson::encode($body));return array_map(fn($key)=>['public_key'=>bin2hex($key->getPublicKey()),'signature'=>bin2hex($key->sign($digest))],$keys);
}
try {
$f=fixture();$s=$f['g']->initialState();$chain=$f['g']->getChainId();$provider=Ed25519KeyPair::generate();$attestors=[Ed25519KeyPair::generate(),Ed25519KeyPair::generate(),Ed25519KeyPair::generate()];
$p=['id'=>str_repeat('a',64),'adapter'=>'compute_cpu_v1','provider_key'=>bin2hex($provider->getPublicKey()),'service_id'=>str_repeat('b',64),'catalog_hash'=>str_repeat('c',64),
 'start_height'=>20161,'end_height'=>50000,'subsidy_percent'=>80,'threshold'=>2,'gru_scale'=>1,'budget_units'=>'3000','beneficiary_budget_units'=>'2000','max_gru_units'=>'10000',
 'gru_per_cpu_ms_num'=>'1','gru_per_cpu_ms_den'=>'1','beneficiaries'=>[bin2hex(addr($f['beneficiary'])),bin2hex(addr($f['other']))],'attestors'=>array_map(fn($k)=>bin2hex($k->getPublicKey()),$attestors)];
$p['catalog_hash']=ServiceSettlement::catalogHash($p);
$bad=$p;$bad['gru_per_cpu_ms_num']='100';rejects(fn()=>ServiceSettlement::validateProgram($bad,$f['p']),'catalog conversion cannot change behind quoted hash');
$bad=$p;$bad['attestors'][0]=$p['provider_key'];rejects(fn()=>\Greenwood\Economics\ServiceSettlement::validateProgram($bad,$f['p']),'self attestation');
$bad=$p;$bad['adapter']='hosted';rejects(fn()=>\Greenwood\Economics\ServiceSettlement::validateProgram($bad,$f['p']),'unqualified hosting meter');
act($s,$f['signers'][0],['action'=>'propose','kind'=>'service_program','reserve'=>'subsidies','nonce'=>0,'expires'=>50000,'program'=>$p],1,$chain);
$proposal=array_key_last($s->economicSummary()['proposals']);approve($s,$f,$proposal,3,1);check($s->economicSummary()['proposals'][$proposal]['status']==='pending','Program requires four approvals');approve($s,['signers'=>[$f['signers'][3]],'g'=>$f['g']],$proposal,1,1);
rejects(fn()=>act($s,$f['signers'][0],['action'=>'execute','proposal'=>$proposal],1,$chain),'program timelock');
advance($s,20160);act($s,$f['signers'][0],['action'=>'execute','proposal'=>$proposal],20161,$chain);
check(isset($s->economicSummary()['services']['programs'][$p['id']]),'Governed program installed');
$s->applyTransaction(signedTx($s,$f['ops'],11,(new TransferU64(addr($f['beneficiary']),10000))->serialize(),$chain),$chain,20161);
$req=new ComputeRequest(addr($f['beneficiary']),$s->getAccount(addr($f['beneficiary']))->getNonce(),str_repeat('w',32),'',str_repeat('i',32),'',0,1000,1048576,10,10000,1000,1000,21000);
$s->applyTransaction(signedTx($s,$f['beneficiary'],7,$req->toBytes(),$chain),$chain,20161);
$assignment=new ComputeAssign($req->getId(),ProviderId::fromPublicKey($provider->getPublicKey())->toBytes(),$provider->getPublicKey(),1,21000);
$before=$s->getStateRoot();rejects(fn()=>$s->applyTransaction(signedTx($s,$f['beneficiary'],8,$assignment->toBytes(),$chain),$chain,20161),'unfunded assignment');check($s->getStateRoot()===$before,'Unfunded assignment atomic');
$q=['chain'=>$chain,'genesis'=>$s->economicSummary()['genesis'],'gru_scale'=>1,'issued_height'=>20161,'expiry_height'=>20170,'payer'=>bin2hex(addr($f['beneficiary'])),'provider'=>bin2hex(addr($provider)),
 'service_id'=>$p['service_id'],'catalog_hash'=>$p['catalog_hash'],'price_kind'=>'provider_gwd','gwd_units_per_gru_num'=>'1','gwd_units_per_gru_den'=>'1','max_gru_units'=>'1000','max_gwd_units'=>'1000'];
$quote=CanonicalJson::encode($q);$qa=signatures($q,[$provider],'GREENWOOD_GRU_QUOTE_V1');
$fund=['action'=>'fund','program'=>$p['id'],'request_id'=>bin2hex($req->getId()),'generation'=>1,'end_height'=>21000,'quote'=>$quote,'quote_approvals'=>$qa];
$bad=$fund;$bad['quote_approvals'][0]['signature']=str_repeat('0',128);rejects(fn()=>service($s,$f['beneficiary'],$bad,20161,$chain),'forged provider quote');
rejects(fn()=>service($s,$f['other'],$fund,20161,$chain),'quote customer mismatch');
$unfunded=clone $s;$subsidyBefore=$s->economicSummary()['balances']['subsidies'];service($s,$f['beneficiary'],$fund,20161,$chain);$id=array_key_last($s->economicSummary()['services']['commitments']);
check($s->getAccount(addr($f['beneficiary']))->getBalance()===9800,'Customer max escrow locked');check($s->economicSummary()['balances']['subsidies']===$subsidyBefore-800,'Native subsidy max escrow locked');
check($s->economicSummary()['services']['programs'][$p['id']]['committed']===800,'Program commitment reserved');
rejects(fn()=>service($s,$f['beneficiary'],$fund,20161,$chain),'duplicate funding');
rejects(fn()=>$s->applyTransaction(signedTx($s,$f['beneficiary'],8,$assignment->toBytes(),$chain),$chain,20161),'funding must be finalized before assignment');
$fundedCandidate=clone $s;$funded=clone $s;advance($funded,20161);advance($s,20161);$s->applyTransaction(signedTx($s,$f['beneficiary'],8,$assignment->toBytes(),$chain),$chain,20162);
$wrong=new ComputeAssign($req->getId(),ProviderId::fromPublicKey($f['other']->getPublicKey())->toBytes(),$f['other']->getPublicKey(),2,21000);
rejects(fn()=>$s->applyTransaction(signedTx($s,$f['beneficiary'],8,$wrong->toBytes(),$chain),$chain,20162),'unfunded reassignment');
$assigned=clone $s;
$receipt=(new ComputeReceiptV2(new ComputeReceipt($req->getId(),$assignment->providerId,str_repeat('e',32),$req->getWorkloadDigest(),$req->getInputDigest(),str_repeat('o',32),'',ComputeStatus::COMPLETED,0,750,1024,800,null,0,false,false),1))->sign($provider);
$evidence=['chain'=>$chain,'genesis'=>$s->economicSummary()['genesis'],'commitment'=>$id,'receipt_id'=>bin2hex($receipt->getId()),'catalog_hash'=>$p['catalog_hash'],'cpu_ms'=>'750'];
$usage=['action'=>'verify_usage','commitment'=>$id,'evidence'=>$evidence,'approvals'=>signatures($evidence,array_slice($attestors,0,2),'GREENWOOD_USAGE_ATTEST_V1')];
rejects(fn()=>service($s,$provider,$usage,20162,$chain),'no finalized receipt');
$s->applyTransaction(signedTx($s,$provider,9,$receipt->toSignedBytes(),$chain),$chain,20162);check($s->getComputeState()->terminal($req->getId()),'Execution receipt finalized');
check($s->getAccount(addr($provider))->getBalance()===0,'Receipt finality does not pay');
rejects(fn()=>$s->applyTransaction(signedTx($s,$provider,9,$receipt->toSignedBytes(),$chain),$chain,20162),'duplicate terminal receipt');
$bad=$usage;$bad['approvals']=signatures($evidence,[$provider],'GREENWOOD_USAGE_ATTEST_V1');rejects(fn()=>service($s,$provider,$bad,20162,$chain),'provider cannot attest own usage');
$bad=$usage;$bad['approvals']=array_slice($usage['approvals'],0,1);rejects(fn()=>service($s,$provider,$bad,20162,$chain),'missing usage quorum');
$bad=$usage;$bad['evidence']['cpu_ms']='751';$bad['approvals']=signatures($bad['evidence'],array_slice($attestors,0,2),'GREENWOOD_USAGE_ATTEST_V1');rejects(fn()=>service($s,$provider,$bad,20162,$chain),'inflated usage conflicts with receipt');
$root=$s->getStateRoot();service($s,$provider,$usage,20162,$chain);check($s->getStateRoot()!==$root,'Usage consensus state root committed');
$prepayment=clone $s;$verified=clone $s;rejects(fn()=>service($s,$provider,$usage,20162,$chain),'duplicate usage claim');
rejects(fn()=>service($s,$provider,['action'=>'settle','commitment'=>$id],20162,$chain),'challenge window');
$tx=signedTx($verified,$provider,13,CanonicalJson::encode(['action'=>'settle','commitment'=>$id,'version'=>1,'genesis'=>$s->economicSummary()['genesis']]),$chain);
advance($s,24481);advance($verified,24481);$s->applyTransaction($tx,$chain,24482);$verified->applyTransaction($tx,$chain,24482);
check($s->getStateRoot()===$verified->getStateRoot(),'Deterministic replay settlement root');check($s->getAccount(addr($provider))->getBalance()===750,'Verified provider payment');
check($s->getAccount(addr($f['beneficiary']))->getBalance()===9850,'Unused customer escrow refunded');check($s->economicSummary()['balances']['subsidies']===$subsidyBefore-600,'Only verified subsidy consumed');
$payment=$s->economicSummary()['services']['commitments'][$id]['payment'];check($payment['payer_units']===150 && $payment['subsidy_units']===600 && $payment['subsidy_released']===200,'Two funding sources reconcile');
$before=$s->getStateRoot();rejects(fn()=>service($s,$provider,['action'=>'settle','commitment'=>$id],24482,$chain),'duplicate payment after restart');check($s->getStateRoot()===$before,'Duplicate settlement atomic');
// Related beneficiaries cannot evade program caps by accepting fresh offers on new jobs.
$budget=clone $fundedCandidate;
for($i=0;$i<2;$i++){
 $new=new ComputeRequest(addr($f['beneficiary']),$budget->getAccount(addr($f['beneficiary']))->getNonce(),str_repeat('w',32),'',str_repeat('i',32),'',0,1000,1048576,10,10000,1000,1000,21000);
 $budget->applyTransaction(signedTx($budget,$f['beneficiary'],7,$new->toBytes(),$chain),$chain,20161);
 $offer=$fund;$offer['request_id']=bin2hex($new->getId());
 if($i===0){$before=$budget->getStateRoot();rejects(fn()=>service($budget,$f['beneficiary'],$offer,20161,$chain),'quote replay across different requests');check($budget->getStateRoot()===$before,'Quote replay leaves financial state unchanged');}
 $fresh=$q;$fresh['offer_nonce']=str_repeat((string)($i+1),64);$offer['quote']=CanonicalJson::encode($fresh);$offer['quote_approvals']=signatures($fresh,[$provider],'GREENWOOD_GRU_QUOTE_V1');
 if($i===0)service($budget,$f['beneficiary'],$offer,20161,$chain);
 else{ $before=$budget->getStateRoot();rejects(fn()=>service($budget,$f['beneficiary'],$offer,20161,$chain),'aggregate beneficiary allowance');check($budget->getStateRoot()===$before,'Exhausted program admission atomic'); }
}
// Independent branch: withholding usage evidence eventually returns both sources.
$noEvidence=clone $funded;advance($noEvidence,25320);service($noEvidence,$f['beneficiary'],['action'=>'refund','commitment'=>$id],25321,$chain);
check($noEvidence->getAccount(addr($f['beneficiary']))->getBalance()===10000,'No evidence returns customer escrow');check($noEvidence->economicSummary()['balances']['subsidies']===$subsidyBefore,'No evidence returns protected subsidy');
// Independent branch: challenge blocks payment; quorum can deny a false charge.
$d=clone $prepayment;$reason=str_repeat('d',64);
rejects(fn()=>service($d,$f['other'],['action'=>'dispute','commitment'=>$id,'reason_hash'=>$reason],20162,$chain),'unrelated party dispute');
service($d,$f['beneficiary'],['action'=>'dispute','commitment'=>$id,'reason_hash'=>$reason],20162,$chain);
check($d->economicSummary()['services']['commitments'][$id]['status']==='disputed','Customer challenge freezes payment');
rejects(fn()=>service($d,$provider,['action'=>'settle','commitment'=>$id],20162,$chain),'disputed payment');
$resolution=['chain'=>$chain,'genesis'=>$d->economicSummary()['genesis'],'commitment'=>$id,'evidence'=>$evidence,'dispute'=>['reason'=>$reason,'height'=>20162],'pay'=>false];
$resolve=['action'=>'resolve','commitment'=>$id,'pay'=>false,'approvals'=>signatures($resolution,array_slice($attestors,0,2),'GREENWOOD_USAGE_RESOLVE_V1')];
$bad=$resolve;$bad['pay']=true;rejects(fn()=>service($d,$provider,$bad,20162,$chain),'resolution signatures bind decision');
service($d,$provider,$resolve,20162,$chain);check($d->getAccount(addr($f['beneficiary']))->getBalance()===10000,'Denied usage refunds payer');
check($d->economicSummary()['balances']['subsidies']===$subsidyBefore,'Denied usage returns subsidy');
// Approving a challenged claim starts a fresh delay and cannot be challenged indefinitely.
$d=clone $prepayment;service($d,$f['beneficiary'],['action'=>'dispute','commitment'=>$id,'reason_hash'=>$reason],20162,$chain);
$resolution['pay']=true;$resolve['pay']=true;$resolve['approvals']=signatures($resolution,array_slice($attestors,0,2),'GREENWOOD_USAGE_RESOLVE_V1');service($d,$provider,$resolve,20162,$chain);
rejects(fn()=>service($d,$provider,['action'=>'settle','commitment'=>$id],20162,$chain),'resolved claim still delayed');
rejects(fn()=>service($d,$f['beneficiary'],['action'=>'dispute','commitment'=>$id,'reason_hash'=>$reason],20162,$chain),'resolution cannot loop challenges');
advance($d,24481);service($d,$provider,['action'=>'settle','commitment'=>$id],24482,$chain);check($d->getAccount(addr($provider))->getBalance()===750,'Quorum resolution pays exact charge');
// An unavailable attestation council cannot keep disputed escrow locked forever.
$outage=clone $prepayment;service($outage,$f['beneficiary'],['action'=>'dispute','commitment'=>$id,'reason_hash'=>$reason],20162,$chain);
advance($outage,63362);rejects(fn()=>service($outage,$provider,$resolve,63363,$chain),'Late attestors cannot reset the expired dispute refund deadline');
service($outage,$f['beneficiary'],['action'=>'refund','commitment'=>$id],63363,$chain);
check($outage->getAccount(addr($f['beneficiary']))->getBalance()===10000,'Unresolved dispute timeout returns customer funds');
check($outage->economicSummary()['balances']['subsidies']===$subsidyBefore,'Unresolved dispute timeout restores protected subsidy');
// Zero work is not paid; a timeout bills only independently approved consumed CPU.
foreach([[ComputeStatus::RUNTIME_ERROR,null,0],[ComputeStatus::TIMED_OUT,null,250]] as [$status,$exit,$cpu]){
 $branch=clone $assigned;$r=(new ComputeReceiptV2(new ComputeReceipt($req->getId(),$assignment->providerId,str_repeat('z',32),$req->getWorkloadDigest(),$req->getInputDigest(),str_repeat('o',32),'',$status,$exit,$cpu,1024,800,null,0,false,false),1))->sign($provider);
 $branch->applyTransaction(signedTx($branch,$provider,9,$r->toSignedBytes(),$chain),$chain,20162);
 $e=$evidence;$e['cpu_ms']=(string)$cpu;$e['receipt_id']=bin2hex($r->getId());
 service($branch,$provider,['action'=>'verify_usage','commitment'=>$id,'evidence'=>$e,'approvals'=>signatures($e,array_slice($attestors,0,2),'GREENWOOD_USAGE_ATTEST_V1')],20162,$chain);
 advance($branch,24481);service($branch,$provider,['action'=>'settle','commitment'=>$id],24482,$chain);
 check($branch->getAccount(addr($provider))->getBalance()===$cpu,'Terminal status pays consumed CPU only');
 check($branch->getAccount(addr($f['beneficiary']))->getBalance()===10000-intdiv($cpu,5),'Terminal status exact customer contribution');
}
// Persist a funded checkpoint through the actual derived SQLite codec, then reject tampering.
if(extension_loaded('pdo_sqlite')){
 $path=sys_get_temp_dir().'/greenwood-service-cache-'.bin2hex(random_bytes(8)).'.sqlite';$index=new \Greenwood\Persistence\StateSqlite($path);
 try{
  $block=new \Greenwood\Block\Block(new \Greenwood\Block\Header(1,$chain,20161,0,str_repeat('x',32),str_repeat('y',32),str_repeat('z',32),$funded->getStateRoot(),addr(array_values($f['validators'])[0]),$f['g']->getGenesisHash(),0),[]);
  $index->commitState($funded,$block,$f['g']->getValidators(),false);
  $loaded=$index->loadState();check($loaded->getStateRoot()===$funded->getStateRoot(),'Funded escrow survives actual SQLite roundtrip');
  check(CanonicalJson::encode($loaded->economicSummary()['services'])===CanonicalJson::encode($funded->economicSummary()['services']),'Funding and program budgets survive cache reload');
  // RPC DTO component fixture over a real hydrated checkpoint; not certified service history.
  $rpcDir=$path.'.rpc';$rpcIndex=new \Greenwood\Persistence\StateSqlite($rpcDir.'/index.sqlite');
  $rpcChain=new \Greenwood\Node\Chain($f['g'],new \Greenwood\Persistence\BlockFilesystem($rpcDir.'/blocks'),$rpcIndex);
  try{
   $rpcChain->state=$loaded;$rpcChain->tip=$block;
   $api=new \Greenwood\Rpc\HttpApi($rpcChain,fn($s)=>'',fn($s)=>false,fn()=>0,fn()=>'validator',fn()=>true,fn()=>0,new \Greenwood\Node\ChainConfig());
   $response=$api->handle('GET','/v1/chain/status','');$dto=json_decode($response->body,true);
   check($response->status===200 && $dto['supply']['total_supply']===(string)Amount::ISSUANCE,'RPC includes funded native escrow in gross supply');
   $categories=$dto['supply']['categories'];check($categories['native_service_escrow']==='1000','RPC reports actual funded service escrow');
   $sum=0;foreach(['uncommitted_protected_reserves','queued_protected_withdrawals','restricted_operations_accounts','unrestricted_accounts','contract_held','native_service_escrow'] as $name)$sum=Amount::add($sum,(int)$categories[$name]);check($sum===Amount::ISSUANCE,'Funded RPC categories conserve supply without overlap');
   $response=$api->handle('GET','/v1/economics/services/'.$id,'');check($response->status===200 && json_decode($response->body,true)['commitment']['status']==='funded','Public commitment detail exposes financial state');
   check($api->handle('GET','/v1/economics/services?limit=1','')->status===200,'Paginated public financial history');
   check($api->handle('GET','/v1/economics/programs?limit=1','')->status===200,'Paginated public program budgets');
  }finally{
   $rpcIndex->close();$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rpcDir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($rpcDir);
  }
  $db=new PDO('sqlite:'.$path);$snapshot=json_decode($db->query('SELECT value FROM economics_state')->fetchColumn(),true);$snapshot['services']['commitments'][$id]['payer_locked']++;$q=$db->prepare('UPDATE economics_state SET value=?');$q->execute([CanonicalJson::encode($snapshot)]);
  rejects(fn()=>$index->loadState(),'tampered escrow cache');unset($db);
 }finally{$index->close();foreach([$path,$path.'-wal',$path.'-shm'] as $file)if(is_file($file))unlink($file);}
}
// Wrong-genesis acceptance is rejected before any balance mutation.
$bad=$fund;$bad['genesis']=str_repeat('f',64);$before=$unfunded->getStateRoot();rejects(fn()=>service($unfunded,$f['beneficiary'],$bad,20161,$chain),'cross-genesis acceptance');check($unfunded->getStateRoot()===$before,'Cross-genesis action atomic');
check($s->economicSummary()['services']['programs'][$p['id']]['spent']===600,'Program paid usage counted once');
check($s->economicSummary()['services']['programs'][$p['id']]['committed']===0,'Program escrow commitment released');
echo json_encode(['status'=>'PASS','checks'=>$checks,'adapters'=>['compute_cpu_v1'],'hosted_adapter_tested'=>false,'live_transactions'=>0,'permanent_keys_generated'=>0,'seconds'=>round(microtime(true)-$started,3)],JSON_PRETTY_PRINT)."\n";
}catch(Throwable $e){fwrite(STDERR,$e."\n");exit(1);}
