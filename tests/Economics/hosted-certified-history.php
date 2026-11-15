<?php
declare(strict_types=1);
$bootstrapMode=getenv('GWD_BOOTSTRAP_TRUST')==='1';
$resourceMode=getenv('GWD_RESOURCE_V2')==='1';
$networkPolicyMode=getenv('GWD_NETWORK_POLICY_V1')==='1';
$archiveMode=getenv('GWD_ARCHIVE_V1')==='1';
if($bootstrapMode&&(!$resourceMode||!$networkPolicyMode))throw new InvalidArgumentException('Bootstrap history requires resources and network governance');
require __DIR__.($resourceMode?'/resource-fixtures.php':'/fixtures.php');
use Greenwood\Crypto\{Ed25519KeyPair,ProtocolHash};
use Greenwood\Economics\{CanonicalJson,ServiceSettlement,ServiceAuthorization,HostedServiceBinding};
use Greenwood\Contracts\ContractId;
use Greenwood\Transaction\{Envelope,DeployContract,CallContract,TransferU64};
use Greenwood\Compute\Protocol\ProviderId;
use Greenwood\Node\Chain;
use Greenwood\Persistence\{BlockFilesystem,StateSqlite};
use Greenwood\Consensus\Messages\{Certificate,Message};
$f=fixture($bootstrapMode);$g=$f['g'];$chain=$g->getChainId();$provider=Ed25519KeyPair::generate();$attestors=[Ed25519KeyPair::generate(),Ed25519KeyPair::generate()];$wasm=file_get_contents($argv[1]??'/tmp/greenwood-hosted-registry.wasm');$registry=ContractId::fromWasm($wasm)->bytes;
$temp=sys_get_temp_dir().'/greenwood-hosted-certified-'.bin2hex(random_bytes(8));
$verify=static function($block,$bytes)use($g){if($bytes===null)throw new InvalidArgumentException('Certificate required');Certificate::decode($bytes)->verify($g->getValidators(),$g->getChainId(),$g->getGenesisHash(),'precommit',$block->getHeight(),$block->getHash());};
$certificate=static function($block)use($f,$g){$votes=[];foreach($f['validators'] as $id=>$key){$m=new Message('precommit',$g->getChainId(),$g->getGenesisHash(),$block->getHeight(),0,$id,$block->getHash());$votes[]=$m->signed($key->sign($m->digest()));}return(new Certificate($votes))->bytes();};
try{
 $node=new Chain($g,new BlockFilesystem($temp.'/blocks'),new StateSqlite($temp.'/state.sqlite'),$verify);
 $p=['id'=>str_repeat('a',64),'adapter'=>'hosted_cpu_v1','registry_contract_id'=>bin2hex($registry),'registry_code_hash'=>bin2hex($registry),'provider_key'=>bin2hex($provider->getPublicKey()),'service_id'=>str_repeat('b',64),'catalog_hash'=>'','start_height'=>20161,'end_height'=>50000,'subsidy_percent'=>80,'threshold'=>2,'gru_scale'=>1,'budget_units'=>'10000','beneficiary_budget_units'=>'10000','max_gru_units'=>'10000','gru_per_cpu_ms_num'=>'1','gru_per_cpu_ms_den'=>'1','beneficiaries'=>[bin2hex(addr($f['beneficiary']))],'attestors'=>array_map(fn($k)=>bin2hex($k->getPublicKey()),$attestors)];$p['catalog_hash']=ServiceSettlement::catalogHash($p);
 if($resourceMode){$p['adapter']='hosted_resources_v2';$p['resource_catalog']=resourceCatalog();unset($p['gru_per_cpu_ms_num'],$p['gru_per_cpu_ms_den']);$p['catalog_hash']=\Greenwood\Resources\ResourceCatalog::hash($p['resource_catalog']);$resourceCap=resourceCapability($provider,bin2hex($g->getGenesisHash()));$resourceManifest=resourceManifest();}
 if($bootstrapMode){foreach($p['resource_catalog']['entries'] as &$entry)$entry['verification_level']='C';unset($entry);$p['catalog_hash']=\Greenwood\Resources\ResourceCatalog::hash($p['resource_catalog']);$attestors=[$provider];$p['attestors']=[$p['provider_key']];$p['threshold']=1;$p['subsidy_percent']=0;$p+=['trust_profile'=>'BOOTSTRAP_OPERATOR_ATTESTED','evidence_profile'=>str_repeat('4',64),'provider_operator'=>str_repeat('1',64),'meter_operator'=>str_repeat('1',64),'attestor_operators'=>[$p['provider_key']=>str_repeat('1',64)]];}
 $assignment=['chain_id'=>$chain,'registry_contract_id'=>bin2hex($registry),'application_id'=>1,'service'=>'web','release_id'=>1,'provider_id'=>bin2hex(ProviderId::fromPublicKey($provider->getPublicKey())->toBytes()),'provider_address'=>'10.1.2.3','ingress_port'=>11000,'instance_id'=>'app-1-web','image_digest'=>'sha256:'.bin2hex(str_repeat('d',32)),'provider_endpoint'=>'tcp://10.1.2.3:5000','provider_tls_name'=>'provider.local'];
 $document=['chain_id'=>$chain,'registry_contract_id'=>bin2hex($registry),'account_id'=>bin2hex(addr($f['beneficiary'])),'operation_id'=>str_repeat('e',32),'version'=>1,'previous_generation'=>null,'assignment'=>$assignment];
 for($height=1;$height<=24488;$height++){
  $shadow=clone $node->state;$transactions=[];
  $queue=function($key,$type,$payload)use(&$shadow,&$transactions,$height,$chain){$tx=new Envelope(1,$chain,addr($key),$shadow->getAccount(addr($key))->getNonce(),$type,$payload,'',$key->getPublicKey());$tx->setSignature($key->sign($tx->getSigningDigest()));$shadow->applyTransaction($tx,$chain,$height);$transactions[]=$tx;};
  $treasury=function($key,$a)use($queue,&$shadow){$a+=['genesis'=>$shadow->economicSummary()['genesis'],'policy_version'=>$shadow->economicSummary()['policy_version']];$queue($key,12,CanonicalJson::encode($a));};
  $finance=function($key,$a)use($queue,$g){$a+=['version'=>1,'genesis'=>bin2hex($g->getGenesisHash())];$queue($key,13,CanonicalJson::encode($a));};
  if($height===1){
   $queue($f['beneficiary'],2,(new DeployContract($wasm,'',0,1000000))->serialize());
   foreach([['cre',''],['pub',pack('Nn',1,1).chr(3).'web'.str_repeat('d',32)],['act',pack('NN',1,1)]] as [$method,$input])$queue($f['beneficiary'],3,(new CallContract($registry,$method,$input,0,1000000))->serialize());
   if($resourceMode){$providerBase=new \Greenwood\Compute\Protocol\ProviderCapability(hex2bin($resourceCap['provider_id']),$provider->getPublicKey(),0,1,['linux/amd64','linux/arm64/v8'],4000,8192*1048576,100,10);$queue($provider,10,$providerBase->toBytes());$queue($provider,14,CanonicalJson::encode($resourceCap));}
   HostedServiceBinding::authorize($shadow,['document'=>$document,'authority_epoch'=>1],$p,bin2hex(addr($f['beneficiary'])));check(true,'Early actual registry/assignment digest validation');
   $treasury($f['signers'][0],['action'=>'propose','kind'=>'service_program','reserve'=>'subsidies','nonce'=>0,'expires'=>50000,'program'=>$p]);$proposal=array_key_last($shadow->economicSummary()['proposals']);
   for($i=0;$i<4;$i++)$treasury($f['signers'][$i],['action'=>'approve','proposal'=>$proposal]);
   if($networkPolicyMode){
    $operatorRecord=['version'=>1,'kind'=>'operator','id'=>str_repeat('1',64),'revision'=>1,'activation_height'=>20162,'expires_height'=>50000,'status'=>'active','controller_key'=>bin2hex($provider->getPublicKey()),'domain'=>str_repeat('2',64),'independence_evidence_hash'=>str_repeat('0',64),'reviewer_key'=>$f['p']['signers'][0],'roles'=>$bootstrapMode?['PROVIDER','METERING','ATTESTOR']:['PROVIDER','METERING'],'qualified_profiles'=>[],'independent'=>false];
    $treasury($f['signers'][0],['action'=>'propose','kind'=>'resource_network','reserve'=>'institute_treasury','nonce'=>0,'expires'=>50000,'record'=>$operatorRecord]);$networkProposal=array_key_last($shadow->economicSummary()['proposals']);
    for($i=0;$i<4;$i++)$treasury($f['signers'][$i],['action'=>'approve','proposal'=>$networkProposal]);
    if($bootstrapMode){$epoch=['version'=>1,'kind'=>'epoch','id'=>str_repeat('3',64),'revision'=>1,'activation_height'=>20162,'expires_height'=>50000,'status'=>'active','stage'=>'E0_BOOTSTRAP','sustained_blocks'=>1,'notice_blocks'=>1,'provider_domains'=>1,'attestor_domains'=>1,'participation_max_age_blocks'=>1,'evidence_profile'=>$p['evidence_profile']];$treasury($f['signers'][0],['action'=>'propose','kind'=>'resource_network','reserve'=>'institute_treasury','nonce'=>1,'expires'=>50000,'record'=>$epoch]);$epochProposal=array_key_last($shadow->economicSummary()['proposals']);for($i=0;$i<4;$i++)$treasury($f['signers'][$i],['action'=>'approve','proposal'=>$epochProposal]);}
   }
   $queue($f['ops'],11,(new TransferU64(addr($f['beneficiary']),10000))->serialize());
  }
  if($height===20161){
   $treasury($f['signers'][0],['action'=>'execute','proposal'=>$proposal]);
   if($networkPolicyMode)$treasury($f['signers'][0],['action'=>'execute','proposal'=>$networkProposal]);
   if($bootstrapMode)$treasury($f['signers'][0],['action'=>'execute','proposal'=>$epochProposal]);
  }
  if($height===20162){
   foreach([[20163,20165,'first'],[20165,20167,'renewal']] as [$start,$end,$nonce]){
    $hosted=['document'=>$document,'start_height'=>$start,'cpu_budget_ms'=>'1000','max_payment_units'=>$resourceMode?'2000':'1000','authority_epoch'=>1];
    $quote=['chain'=>$chain,'genesis'=>bin2hex($g->getGenesisHash()),'gru_scale'=>1,'issued_height'=>$height,'expiry_height'=>20200,'payer'=>bin2hex(addr($f['beneficiary'])),'provider'=>bin2hex(addr($provider)),'service_id'=>$p['service_id'],'catalog_hash'=>$p['catalog_hash'],'price_kind'=>'provider_gwd','gwd_units_per_gru_num'=>'1','gwd_units_per_gru_den'=>'1','max_gru_units'=>'1000','max_gwd_units'=>'1000','binding_hash'=>HostedServiceBinding::hash($hosted+['end_height'=>$end]),'offer_nonce'=>$nonce];
    if($resourceMode){$quote['capability_nonce']=$resourceCap['nonce'];$quote['capability_hash']=\Greenwood\Resources\ProviderCapabilityV2::hash($resourceCap);$quote['resource_quote_version']=2;$quote['resource_manifest']=$resourceManifest;$quote['resource_manifest_hash']=\Greenwood\Resources\ResourceManifest::hash($resourceManifest);$quote['max_gru_units']='2000';$quote['max_gwd_units']='2000';}
    if($bootstrapMode){$quote['trust_profile']=$p['trust_profile'];$quote['program_policy_hash']=\Greenwood\Network\AttestationTrust::programHash($p);}
    $finance($f['beneficiary'],['action'=>'fund','program'=>$p['id'],'hosted'=>$hosted,'end_height'=>$end,'quote'=>CanonicalJson::encode($quote),'quote_approvals'=>[ServiceAuthorization::approval('quote',$quote,$provider)['approval']]]);
    $ids[$nonce]=array_key_last($shadow->economicSummary()['services']['commitments']);
   }
  }
  if($height===20166){
   $e=['chain'=>$chain,'genesis'=>bin2hex($g->getGenesisHash()),'commitment'=>$ids['first'],'catalog_hash'=>$p['catalog_hash'],'assignment_generation'=>HostedServiceBinding::generation($document),'start_height'=>20163,'end_height'=>20165,'cpu_ms'=>'750','measurement_hash'=>str_repeat('1',64),'runtime_lineage_hash'=>str_repeat('2',64)];
   if($resourceMode){$c=$shadow->economicSummary()['services']['commitments'][$ids['first']];$e=['version'=>1,'chain'=>$chain,'genesis'=>bin2hex($g->getGenesisHash()),'commitment'=>$ids['first'],'provider_id'=>$resourceCap['provider_id'],'workload_id'=>$c['request_id'],'assignment_generation'=>HostedServiceBinding::generation($document),'billing_interval_id'=>$ids['first'],'resource_catalog_hash'=>$p['catalog_hash'],'resource_manifest_hash'=>\Greenwood\Resources\ResourceManifest::hash($resourceManifest),'clock'=>'attested_monotonic_ms_v1','measurement_start_ms'=>'0','measurement_end_ms'=>'1000','runtime_identity'=>str_repeat('a',64),'device_identities'=>[str_repeat('1',64),str_repeat('2',64)],'evidence_bundle_hash'=>str_repeat('b',64),'measurements'=>[['line_id'=>'cpu','asset_id'=>str_repeat('1',64),'meter_version'=>'cgroup.cpu.v1','quantity'=>'750','reserved_units'=>null,'available_ms'=>null,'source_hash'=>str_repeat('c',64)],['line_id'=>'ram','asset_id'=>str_repeat('2',64),'meter_version'=>'allocation.interval.v1','quantity'=>'4000','reserved_units'=>'4','available_ms'=>'1000','source_hash'=>str_repeat('d',64)]]];}
   $finance($provider,['action'=>'verify_usage','commitment'=>$ids['first'],'evidence'=>$e,'approvals'=>array_map(fn($k)=>ServiceAuthorization::approval($resourceMode?'resource-usage':'usage',$e,$k)['approval'],$attestors)]);
  }
  if($height===24486)$finance($provider,['action'=>'settle','commitment'=>$ids['first']]);
  if($height===24488)$finance($f['beneficiary'],['action'=>'refund','commitment'=>$ids['renewal']]);
  if($height===24488&&$archiveMode){$records=[];foreach($ids as $id)$records[]=['commitment'=>$id,'record_hash'=>bin2hex(ProtocolHash::hash('GREENWOOD_SERVICE_ARCHIVE_V1',CanonicalJson::encode($shadow->economicSummary()['services']['commitments'][$id])))];$finance($provider,['action'=>'archive_v1','records'=>$records]);}
  $proposer=addr(array_values($f['validators'])[$height%3]);$block=$node->build($transactions,0,$proposer);$node->finalize($block,$certificate($block));
  if($height%5000===0)fwrite(STDERR,'Certified hosted history: '.$height." blocks\n");
 }
 if($networkPolicyMode)check(isset($node->state->economicSummary()['resource_policies']['operator'][str_repeat('1',64)]),'Governed bootstrap operator included in certified state');
 $root=$node->state->getStateRoot();check($node->state->getAccount(addr($provider))->getBalance()===($resourceMode?1750:750),'Certified provider payment');check($node->state->getAccount(addr($f['beneficiary']))->getBalance()===($bootstrapMode?8250:($resourceMode?9650:9850)),'Certified customer refund');check($node->index->loadState()->getStateRoot()===$root,'Certified escrow SQLite index agrees');
 $node->index->close();unset($node);
 // Actual Chain startup reconstructs every finalized block and verifies every certificate.
 $restart=new Chain($g,new BlockFilesystem($temp.'/blocks'),new StateSqlite($temp.'/replayed.sqlite'),$verify);
 $records=$restart->state->economicSummary()['services'][$archiveMode?'archives':'commitments'];
 check($restart->state->getStateRoot()===$root,'Full certified hosted history replays to identical root');check($records[$ids['first']]['status']==='paid','Replayed service payment finality');check($records[$ids['renewal']]['status']==='refunded','Replayed renewal outage refund');check($restart->state->economicSummary()['services']['programs'][$p['id']]['spent']===($bootstrapMode?0:($resourceMode?1400:600)),'Replayed subsidy not paid twice');
 echo json_encode(['status'=>'PASS','checks'=>$checks,'resource_v2'=>$resourceMode,'explicit_bootstrap_trust'=>$bootstrapMode,'allocation'=>str_replace('-','/',getenv('GWD_ALLOCATION_CANDIDATE')?:'15-25-30-25-5'),'certified_blocks'=>24488,'validator_signers'=>3,'full_service_history_replay'=>true,'actual_registry_wasm'=>true,'physical_network_tested'=>false,'independent_measurement_tested'=>false,'live_transactions'=>0,'seconds'=>round(microtime(true)-$started,3)],JSON_PRETTY_PRINT)."\n";
}finally{
 if(isset($node))$node->index->close();if(isset($restart))$restart->index->close();
 if(is_dir($temp)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($temp);}
}
