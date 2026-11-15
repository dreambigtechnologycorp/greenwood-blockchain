<?php
declare(strict_types=1);
require __DIR__.'/resource-fixtures.php';
use Greenwood\Crypto\{Ed25519KeyPair,ProtocolHash};
use Greenwood\Economics\{CanonicalJson,ServiceAuthorization,HostedServiceBinding,HostedFundingGuard};
use Greenwood\Resources\{ResourceCatalog,ResourceManifest};
use Greenwood\Network\AttestationTrust;
use Greenwood\Contracts\ContractId;
use Greenwood\Transaction\{DeployContract,CallContract,TransferU64};

// Reviewed roles are synthetic fixture facts. Real native governance/registry/finance execute below.
$f=fixture(true);$s=$f['g']->initialState();$chain=$f['g']->getChainId();$genesis=$f['g']->getGenesisHash();
$provider=Ed25519KeyPair::generate();$provider2=Ed25519KeyPair::generate();$meter=Ed25519KeyPair::generate();$witnesses=[Ed25519KeyPair::generate(),Ed25519KeyPair::generate()];
$catalog=resourceCatalog();$manifest=resourceManifest();$cap=resourceCapability($provider,bin2hex($genesis));registerResources($s,$provider,$cap,1,$chain);
$wasm=file_get_contents($argv[1]??'/tmp/greenwood-hosted-registry.wasm');$registry=ContractId::fromWasm($wasm)->bytes;
$s->applyTransaction(resourceTx($s,$f['beneficiary'],2,(new DeployContract($wasm,'',0,1000000))->serialize(),$chain),$chain,1);
foreach([['cre',''],['pub',pack('Nn',1,1).chr(3).'web'.str_repeat('d',32)],['act',pack('NN',1,1)]] as [$method,$data])$s->applyTransaction(resourceTx($s,$f['beneficiary'],3,(new CallContract($registry,$method,$data,0,1000000))->serialize(),$chain),$chain,1);
advance($s,100);
$govern=static function(array $record,int $height)use(&$s,$f,$chain):string{
 $nonce=$s->economicSummary()['nonces']['institute_treasury']??0;
 act($s,$f['signers'][0],['action'=>'propose','kind'=>'resource_network','reserve'=>'institute_treasury','nonce'=>$nonce,'expires'=>$height+50000,'record'=>$record],$height,$chain);$id=array_key_last($s->economicSummary()['proposals']);approve($s,$f,$id,4,$height);return $id;
};
$operatorRecords=[];$operatorProposals=[];
foreach([[$provider,'PROVIDER'],[$provider2,'PROVIDER'],[$meter,'METERING'],[$witnesses[0],'ATTESTOR'],[$witnesses[1],'ATTESTOR']] as $i=>[$key,$role]){
 $id=str_repeat((string)($i+1),64);$operatorRecords[$id]=['version'=>1,'kind'=>'operator','id'=>$id,'revision'=>1,'activation_height'=>20262,'expires_height'=>60000,'status'=>'active','controller_key'=>bin2hex($key->getPublicKey()),'domain'=>str_repeat((string)($i+1),64),'independence_evidence_hash'=>str_repeat('e',64),'reviewer_key'=>$f['p']['signers'][0],'roles'=>[$role],'qualified_profiles'=>[str_repeat('d',64)],'independent'=>true,'participation_start_height'=>1,'participation_end_height'=>100,'participation_evidence_hash'=>str_repeat('a',64)];
 $operatorProposals[]=$govern($operatorRecords[$id],101);
}
$p=['id'=>str_repeat('a',64),'adapter'=>'hosted_resources_v2','registry_contract_id'=>bin2hex($registry),'registry_code_hash'=>bin2hex($registry),'provider_key'=>$cap['provider_key'],'service_id'=>str_repeat('b',64),'catalog_hash'=>ResourceCatalog::hash($catalog),'resource_catalog'=>$catalog,'start_height'=>40424,'end_height'=>60000,'subsidy_percent'=>80,'threshold'=>2,'gru_scale'=>1,'budget_units'=>'10000','beneficiary_budget_units'=>'10000','max_gru_units'=>'10000','beneficiaries'=>[bin2hex(addr($f['beneficiary']))],'attestors'=>array_map(fn($k)=>bin2hex($k->getPublicKey()),$witnesses),
 'trust_profile'=>'INDEPENDENT_ATTESTED','evidence_profile'=>str_repeat('d',64),'provider_operator'=>str_repeat('1',64),'meter_operator'=>str_repeat('3',64),'attestor_operators'=>[bin2hex($witnesses[0]->getPublicKey())=>str_repeat('4',64),bin2hex($witnesses[1]->getPublicKey())=>str_repeat('5',64)]];
act($s,$f['signers'][0],['action'=>'propose','kind'=>'service_program','reserve'=>'subsidies','nonce'=>0,'expires'=>60000,'program'=>$p],101,$chain);$programProposal=array_key_last($s->economicSummary()['proposals']);approve($s,$f,$programProposal,4,101);
advance($s,20260);foreach(array_merge($operatorProposals,[$programProposal]) as $id)act($s,$f['signers'][0],['action'=>'execute','proposal'=>$id],20261,$chain);
advance($s,20261);
$epoch=['version'=>1,'kind'=>'epoch','id'=>str_repeat('9',64),'revision'=>1,'activation_height'=>40423,'expires_height'=>60000,'status'=>'active','stage'=>'E2_VERIFICATION_EXPANSION','sustained_blocks'=>1,'notice_blocks'=>1,'provider_domains'=>2,'attestor_domains'=>2,'participation_max_age_blocks'=>50000,'evidence_profile'=>str_repeat('d',64)];$epochProposal=$govern($epoch,20262);
$revoked=$operatorRecords[str_repeat('5',64)];$revoked['revision']=2;$revoked['activation_height']=40429;$revoked['status']='revoked';$revocation=$govern($revoked,20262);
advance($s,40421);foreach([$epochProposal,$revocation] as $id)act($s,$f['signers'][0],['action'=>'execute','proposal'=>$id],40422,$chain);
advance($s,40423);
$s->applyTransaction(resourceTx($s,$f['ops'],11,(new TransferU64(addr($f['beneficiary']),10000))->serialize(),$chain),$chain,40424);
$assignment=['chain_id'=>$chain,'registry_contract_id'=>bin2hex($registry),'application_id'=>1,'service'=>'web','release_id'=>1,'provider_id'=>$cap['provider_id'],'provider_address'=>'10.1.2.3','ingress_port'=>11000,'instance_id'=>'app-1-web','image_digest'=>'sha256:'.bin2hex(str_repeat('d',32)),'provider_endpoint'=>'tcp://10.1.2.3:5000','provider_tls_name'=>'provider.fixture'];
$document=['chain_id'=>$chain,'registry_contract_id'=>bin2hex($registry),'account_id'=>bin2hex(addr($f['beneficiary'])),'operation_id'=>str_repeat('e',32),'version'=>1,'previous_generation'=>null,'assignment'=>$assignment];$ids=[];
foreach([[40425,40427],[40427,40429]] as [$start,$end]){
 $hosted=['document'=>$document,'start_height'=>$start,'cpu_budget_ms'=>'1000','max_payment_units'=>'2000','authority_epoch'=>1];
 $q=['chain'=>$chain,'genesis'=>bin2hex($genesis),'gru_scale'=>1,'issued_height'=>40424,'expiry_height'=>40430,'payer'=>bin2hex(addr($f['beneficiary'])),'provider'=>bin2hex(addr($provider)),'service_id'=>$p['service_id'],'catalog_hash'=>$p['catalog_hash'],'price_kind'=>'provider_gwd','gwd_units_per_gru_num'=>'1','gwd_units_per_gru_den'=>'1','max_gru_units'=>'2000','max_gwd_units'=>'2000','binding_hash'=>HostedServiceBinding::hash($hosted+['end_height'=>$end]),'offer_nonce'=>(string)$start,'capability_nonce'=>1,'capability_hash'=>\Greenwood\Resources\ProviderCapabilityV2::hash($cap),'resource_quote_version'=>2,'resource_manifest'=>$manifest,'resource_manifest_hash'=>ResourceManifest::hash($manifest),'trust_profile'=>'INDEPENDENT_ATTESTED','program_policy_hash'=>AttestationTrust::programHash($p)];
 $fund=['action'=>'fund','program'=>$p['id'],'hosted'=>$hosted,'end_height'=>$end,'quote'=>CanonicalJson::encode($q),'quote_approvals'=>[ServiceAuthorization::approval('quote',$q,$provider)['approval']]];
 $bad=$q;unset($bad['trust_profile']);$badFund=$fund;$badFund['quote']=CanonicalJson::encode($bad);$badFund['quote_approvals']=[ServiceAuthorization::approval('quote',$bad,$provider)['approval']];
 rejects(fn()=>resourceFinance($s,$f['beneficiary'],$badFund,40424,$chain),'Native funding rejects an offer omitting accepted trust mode');
 resourceFinance($s,$f['beneficiary'],$fund,40424,$chain);$ids[]=array_key_last($s->economicSummary()['services']['commitments']);
}
// Compose actual native funding, existing application registry and persistent meter.
// Runtime observations/handles here are explicit fixtures, not a containerd execution claim.
advance($s,40425);$meterRoot=sys_get_temp_dir().'/greenwood-funded-meter-'.bin2hex(random_bytes(8));mkdir($meterRoot,0700);
try{
 $apps=new \Greenwood\Provider\ProviderApplicationRegistry($meterRoot.'/apps');$image='registry.fixture/app@'.$assignment['image_digest'];
 $apps->beginStart(1,'web','app-1-web',$image,$assignment['image_digest']);$apps->markRunning(1,'web','app-1-web','view','parent','gc-lease',123);
 $meterHeight=40425;$meterCommit=$ids[0];
 $read=static function(string $generation)use(&$s,$chain,$genesis,&$meterHeight,&$meterCommit):array{return ['chain_id'=>$chain,'genesis_hash'=>bin2hex($genesis),'finalized_height'=>$meterHeight,'state_root'=>bin2hex($s->getStateRoot()),'finalized_hash'=>str_repeat('f',64),'commitment_id'=>$meterCommit,'commitment'=>$s->economicSummary()['services']['commitments'][$meterCommit]];};
 $guard=new HostedFundingGuard($read,$chain,bin2hex($genesis),$cap['provider_key']);$scope=$guard->require($document)['meter_scope'];
 check($scope['commitment']===$ids[0]&&$scope['manifest_hash']===ResourceManifest::hash($manifest),'Meter scope comes from exact accepted native resource commitment');
 $journal=new \Greenwood\Provider\MeteringService($meterRoot.'/meter');$counter=-10;$now=1000;
 $observe=static function(string $container)use(&$counter):array{if($container!=='app-1-web')throw new RuntimeException('Foreign task');$counter+=20;return ['version'=>1,'verification_level'=>'C','source'=>'local_cgroup_v2','runtime_identity'=>str_repeat('a',64),'cpu_ms'=>(string)$counter];};
 $clock=static function()use(&$now):int{return $now;};
 $monitor=new \Greenwood\Provider\HostedMeteringMonitor($journal,$apps->active(...),static fn($app,$service,$image)=>$guard->require($document),$observe,1000,$clock);
 check($monitor->tick()[0]['status']==='OBSERVED_PROVIDER_ORIGIN','Funded running application begins attributed segment');check($monitor->tick()===[],'Sampling cadence is bounded without busy collection');
 $now=2000;$monitor->tick();$bundle=$monitor->finish($ids[0],'app-1-web');check($bundle['cpu_ms']==='40'&&$bundle['bundle']['funding_scope']['assignment_generation']===HostedServiceBinding::generation($document),'Periodic samples and explicit final sample retain exact assignment and cumulative delta');
 unset($monitor,$journal);$journal=new \Greenwood\Provider\MeteringService($meterRoot.'/meter');check($journal->bundle($ids[0])['evidence_bundle_hash']===$bundle['evidence_bundle_hash'],'Restart preserves funded scope and evidence digest');
 $badScope=$scope;$badScope['assignment_generation']=str_repeat('0',64);rejects(fn()=>$journal->bind($badScope),'Restart cannot rebind old counters to a different assignment');
 $reads=0;$denied=new \Greenwood\Provider\HostedMeteringMonitor($journal,$apps->active(...),fn()=>throw new RuntimeException('Expired funding'),static function()use(&$reads){$reads++;return [];},1000,$clock);
 check($denied->tick()[0]['status']==='UNVERIFIED'&&$reads===0,'Expired funding denies host reads and never fabricates zero usage');
 $rolling=new \Greenwood\Provider\MeteringService($meterRoot.'/rolling');$rawCpu=-20;$now=3000;
 $rollObserve=static function()use(&$rawCpu):array{$rawCpu+=20;return ['version'=>1,'verification_level'=>'C','source'=>'local_cgroup_v2','runtime_identity'=>str_repeat('b',64),'cpu_ms'=>(string)$rawCpu];};
 $rollMonitor=new \Greenwood\Provider\HostedMeteringMonitor($rolling,$apps->active(...),static fn($app,$service,$image)=>$guard->require($document),$rollObserve,1000,$clock);
 $rollMonitor->tick();$now=4000;$rollMonitor->tick();advance($s,40427);$meterHeight=40427;$meterCommit=$ids[1];$now=5000;
 check($rollMonitor->tick()[0]['status']==='OBSERVED_PROVIDER_ORIGIN','Recurring funded interval can continue sampling the same runtime');
 $oldBoundary=$rolling->bundle($ids[0]);check($oldBoundary['cpu_ms']===null&&$oldBoundary['measurement_status']==='BOUNDARY_RECONCILIATION_REQUIRED','Polling across interval boundary never fabricates an exact old-interval bill');
 $now=6000;$rollMonitor->tick();$nextBundle=$rollMonitor->finish($ids[1],'app-1-web');check($nextBundle['cpu_ms']==='40'&&$oldBoundary['bundle']['segments'][0]['cpu_ms']==='40','Adjacent interval journals preserve raw counter conservation without rebilling the prior range');

}finally{unset($journal,$monitor,$apps,$denied,$rolling,$rollMonitor);$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($meterRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($meterRoot);}
$evidence=static function(string $id)use(&$s,$chain,$genesis,$cap,$p,$manifest,$document):array{return ['version'=>1,'chain'=>$chain,'genesis'=>bin2hex($genesis),'commitment'=>$id,'provider_id'=>$cap['provider_id'],'workload_id'=>$s->economicSummary()['services']['commitments'][$id]['request_id'],'assignment_generation'=>HostedServiceBinding::generation($document),'billing_interval_id'=>$id,'resource_catalog_hash'=>$p['catalog_hash'],'resource_manifest_hash'=>ResourceManifest::hash($manifest),'clock'=>'attested_monotonic_ms_v1','measurement_start_ms'=>'0','measurement_end_ms'=>'1000','runtime_identity'=>str_repeat('a',64),'device_identities'=>[str_repeat('1',64),str_repeat('2',64)],'evidence_bundle_hash'=>str_repeat('b',64),'measurements'=>[['line_id'=>'cpu','asset_id'=>str_repeat('1',64),'meter_version'=>'cgroup.cpu.v1','quantity'=>'750','reserved_units'=>null,'available_ms'=>null,'source_hash'=>str_repeat('c',64)],['line_id'=>'ram','asset_id'=>str_repeat('2',64),'meter_version'=>'allocation.interval.v1','quantity'=>'4000','reserved_units'=>'4','available_ms'=>'1000','source_hash'=>str_repeat('d',64)]]];};
// Source remains a synthetic local collector; exercise the same persisted conversion used by operators.
$fixtureEvidence=$evidence;
$evidence=static function(string $id)use($fixtureEvidence,&$s,$p,$chain,$genesis):array{
 $fixture=$fixtureEvidence($id);$c=$s->economicSummary()['services']['commitments'][$id];
 $scope=['chain'=>$chain,'genesis'=>bin2hex($genesis),'provider_id'=>$fixture['provider_id'],'workload_id'=>$fixture['workload_id'],'assignment_generation'=>$fixture['assignment_generation'],'commitment'=>$id,'interval_start_height'=>$c['hosted']['start_height'],'interval_end_height'=>$c['end_height'],'catalog_hash'=>$p['catalog_hash'],'manifest_hash'=>$fixture['resource_manifest_hash']];
 $dir=sys_get_temp_dir().'/greenwood-claim-source-'.bin2hex(random_bytes(8));
 try{
  $meter=new \Greenwood\Provider\MeteringService($dir);$meter->bind($scope);
  $sample=['version'=>1,'verification_level'=>'C','source'=>'local_cgroup_v2','runtime_identity'=>$fixture['runtime_identity'],'cpu_ms'=>'0','monotonic_ms'=>'0','memory_limit_bytes'=>'4194304'];
  $meter->begin($id,$sample);$sample['cpu_ms']='750';$sample['monotonic_ms']='1000';$meter->finish($id,$sample);
  unset($meter);$meter=new \Greenwood\Provider\MeteringService($dir);
  return \Greenwood\Provider\LocalResourceEvidence::prepare($meter->bundle($id),$c,$p,$chain,bin2hex($genesis))['evidence'];
 }finally{unset($meter);foreach(glob($dir.'/*')?:[] as $file)unlink($file);if(is_dir($dir))rmdir($dir);}
};
$claim=static function(string $id)use($evidence,$witnesses):array{$e=$evidence($id);return ['action'=>'verify_usage','commitment'=>$id,'evidence'=>$e,'approvals'=>array_map(fn($k)=>ServiceAuthorization::approval('resource-usage',$e,$k)['approval'],$witnesses)];};
advance($s,40427);$signedClaim=$claim($ids[0]);$source=\Greenwood\Provider\QualifiedSourceAttestor::sourceApproval($signedClaim['evidence'],$meter);
$signedClaim['approvals']=array_map(fn($key)=>\Greenwood\Provider\QualifiedSourceAttestor::approve($s,$ids[0],$signedClaim['evidence'],$source,$key,$chain,$genesis,40428),$witnesses);
$wrongSource=\Greenwood\Provider\QualifiedSourceAttestor::sourceApproval($signedClaim['evidence'],$provider);
rejects(fn()=>\Greenwood\Provider\QualifiedSourceAttestor::approve($s,$ids[0],$signedClaim['evidence'],$wrongSource,$witnesses[0],$chain,$genesis,40428),'Witness workflow cannot re-sign provider-origin telemetry as independent source truth');
$altered=$signedClaim['evidence'];$altered['measurements'][0]['quantity']='751';rejects(fn()=>\Greenwood\Provider\QualifiedSourceAttestor::approve($s,$ids[0],$altered,$source,$witnesses[0],$chain,$genesis,40428),'Qualified source signature commits exact resource measurements');
$prepared=\Greenwood\Provider\ResourceSettlementWorkflow::usageAction($s,$ids[0],$signedClaim['evidence'],$signedClaim['approvals'],$chain,$genesis,40428);
check($prepared['preview']['provider_units']===1750&&$prepared['signing_required']&&!$prepared['payment_finalized'],'Coordinator uses native validation/pricing and cannot sign or authorize payment');
$badApprovals=$signedClaim['approvals'];$badApprovals[1]=$badApprovals[0];rejects(fn()=>\Greenwood\Provider\ResourceSettlementWorkflow::usageAction($s,$ids[0],$signedClaim['evidence'],$badApprovals,$chain,$genesis,40428),'Coordinator rejects duplicate witness signatures before requesting a transaction signature');
resourceFinance($s,$provider,$prepared['action'],40428,$chain);check($s->getAccount(addr($provider))->getBalance()===0,'Qualified claim still waits the financial challenge delay');
advance($s,40429);$root=$s->getStateRoot();rejects(fn()=>resourceFinance($s,$provider,$claim($ids[1]),40430,$chain),'Revocation removes new claims without bootstrap downgrade');check($s->getStateRoot()===$root,'Rejected quorum loss cannot mutate escrow');
advance($s,44749);resourceFinance($s,$provider,['action'=>'settle','commitment'=>$ids[0]],44750,$chain);resourceFinance($s,$f['beneficiary'],['action'=>'refund','commitment'=>$ids[1]],44750,$chain);
check($s->getAccount(addr($provider))->getBalance()===1750,'Previously valid claim settles under its accepted policy');check($s->getAccount(addr($f['beneficiary']))->getBalance()===9650,'Unattested renewal refunds customer escrow exactly');
check($s->economicSummary()['services']['programs'][$p['id']]['spent']===1400,'Only approved first interval consumes protected subsidy');
 $quoteProtection=$s->economicSummary()['services']['quotes'];$archiveRows=[];foreach($ids as $id)$archiveRows[]=['commitment'=>$id,'record_hash'=>bin2hex(ProtocolHash::hash('GREENWOOD_SERVICE_ARCHIVE_V1',CanonicalJson::encode($s->economicSummary()['services']['commitments'][$id])))];
 $beforeArchive=clone $s;
 resourceFinance($s,$provider,['action'=>'archive_v2','records'=>$archiveRows],44750,$chain);$archived=$s->economicSummary()['services']['archives'][$ids[0]];
 check($archived['archive_version']===2&&$archived['application_id']===1&&$archived['assignment_id']===HostedServiceBinding::assignmentId($document),'Explicit archive_v2 retains authenticated product lookup identity without changing archive_v1');
 check($s->economicSummary()['services']['quotes']===$quoteProtection,'Archival view metadata cannot remove paid-quote protection');
require __DIR__.'/archive-rpc-checks.php';
echo json_encode(['status'=>'PASS','checks'=>$checks,'actual_registry_wasm'=>true,'native_governance_and_finance'=>true,'allocation'=>getenv('GWD_ALLOCATION_CANDIDATE')?:'15-25-30-25-5','physical_independence'=>false,'qualification_facts'=>'SYNTHETIC_SINGLE_ADMIN_FIXTURES','live_transactions'=>0,'seconds'=>round(microtime(true)-$started,3)],JSON_PRETTY_PRINT)."\n";
