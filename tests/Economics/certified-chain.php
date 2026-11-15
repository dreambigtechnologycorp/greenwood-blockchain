<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
use Greenwood\Node\Chain;
use Greenwood\Persistence\{BlockFilesystem,StateSqlite};
use Greenwood\Consensus\Messages\{Certificate,Message};
use Greenwood\Transaction\{Envelope,TransferU64};
use Greenwood\Economics\{Amount,CanonicalJson};
use Greenwood\Block\{Block,Header};
$f=fixture();$g=$f['g'];$chains=[];$temp=sys_get_temp_dir().'/greenwood-economic-network-'.bin2hex(random_bytes(8));
$verify=static function(Block $b,?string $bytes)use($g):void {if($bytes===null)throw new InvalidArgumentException('Certificate required');Certificate::decode($bytes)->verify($g->getValidators(),$g->getChainId(),$g->getGenesisHash(),'precommit',$b->getHeight(),$b->getHash());};
$certificate=static function(Block $block)use($f,$g):string{$votes=[];foreach($f['validators'] as $id=>$key){$m=new Message('precommit',$g->getChainId(),$g->getGenesisHash(),$block->getHeight(),0,$id,$block->getHash());$votes[]=$m->signed($key->sign($m->digest()));}return(new Certificate($votes))->bytes();};
try{
 foreach(['A','B','C'] as $id)$chains[$id]=new Chain($g,new BlockFilesystem($temp.'/'.$id.'/blocks'),new StateSqlite($temp.'/'.$id.'/state.sqlite'),$verify);
 for($height=1;$height<=3;$height++){
  $transactions=[];
  if($height===2)$transactions[] = transaction($chains['A']->state,$f['signers'][0],['action'=>'propose','kind'=>'payment','reserve'=>'grants','nonce'=>0,'expires'=>10000,'recipient'=>bin2hex(addr($f['beneficiary'])),'amount'=>'20000000000'],$g->getChainId());
  if($height===3){$key=$f['ops'];$payload=(new TransferU64(addr($f['beneficiary']),100*Amount::SCALE))->serialize();$t=new Envelope(1,$g->getChainId(),addr($key),0,Envelope::TYPE_TRANSFER_U64,$payload,'',$key->getPublicKey());$transactions[]=$t->setSignature($key->sign($t->getSigningDigest()));}
  $proposer=addr(array_values($f['validators'])[$height%3]);$block=$chains['A']->build($transactions,0,$proposer);$cert=$certificate($block);
  foreach($chains as $chain)$chain->finalize($block,$cert);
  $roots=array_map(fn($c)=>bin2hex($c->state->getStateRoot()),$chains);check(count(array_unique($roots))===1,'Three certified validator state roots diverged');
  foreach($chains as $chain)check($chain->index->loadState()->getStateRoot()===$chain->state->getStateRoot(),'Derived economic index root mismatch');
 }
 $before=$chains['A']->state->getStateRoot();$candidate=$chains['A']->build([],0,addr($f['validators']['B']));$h=$candidate->getHeader();
 $bad=new Block(new Header(1,$g->getChainId(),4,0,$h->getParentHash(),$h->getPreviousStateRoot(),$h->getTransactionsRoot(),str_repeat("\xff",32),$h->getProposer(),$g->getGenesisHash(),0),[]);
 $badCert=$certificate($bad);foreach($chains as $chain)rejects(fn()=>$chain->finalize($bad,$badCert),'Certified block with manipulated economics');
 check($chains['A']->state->getStateRoot()===$before,'Invalid economic block mutated finalized state');
 $chains['C']->index->close();unset($chains['C']);
 $restart=new Chain($g,new BlockFilesystem($temp.'/C/blocks'),new StateSqlite($temp.'/C/state.sqlite'),$verify);
 check($restart->state->getStateRoot()===$before,'Certified history replay mismatch');
 check($restart->state->economicSummary()['released']===30000000,'Replay doubled/missed reserve release');
 check($restart->state->economicSummary()['fees_distributed']===7,'Replay lost fee accounting');
 $api=new \Greenwood\Rpc\HttpApi($restart,fn($s)=>'',fn($s)=>false,fn()=>0,fn()=>'validator',fn()=>true,fn()=>0,new \Greenwood\Node\ChainConfig());
 $status=$api->handle('GET','/v1/chain/status','');$dto=json_decode($status->body,true,32,JSON_THROW_ON_ERROR);check($status->status===200 && $dto['supply']['total_supply']==='1000000000000000','RPC supply includes protected reserves');check($dto['economy']['block_time_seconds']===60 && $dto['economy']['burn_percent']===0,'RPC confirmed policy');
 $categories=$dto['supply']['categories'];$sum=0;foreach(['uncommitted_protected_reserves','queued_protected_withdrawals','restricted_operations_accounts','unrestricted_accounts','contract_held'] as $name)$sum=Amount::add($sum,(int)$categories[$name]);check($sum===Amount::ISSUANCE,'Disjoint supply categories cannot double count pending withdrawals');check($categories['service_escrow_enabled']===true && $categories['native_service_escrow']==='0','Native escrow is enabled with no current commitments');
 $page=$api->handle('GET','/v1/economics/proposals?limit=1','');check($page->status===200 && count(json_decode($page->body,true)['proposals'])===1,'Treasury audit pagination');
 check($api->handle('GET','/v1/economics/policy','')->status===200,'Committed public policy route');
 foreach(['/v1/resources/operators','/v1/resources/system-services','/v1/resources/epochs'] as $route){$response=$api->handle('GET',$route.'?limit=1','');$view=json_decode($response->body,true,32,JSON_THROW_ON_ERROR);check($response->status===200&&$view['records']===[]&&$view['genesis_hash']===bin2hex($g->getGenesisHash())&&$view['state_root']===bin2hex($restart->state->getStateRoot()),'New native-policy RPC is bounded and pinned to actual certified state');}
 check($api->handle('GET','/v1/resources/operators?limit=100000','')->status===400,'Resource policy pagination cannot bypass RPC bounds');
 $fetch=static function(string $path)use($api):array{$response=$api->handle('GET',$path,'');if($response->status!==200)throw new RuntimeException('Native RPC request failed');return json_decode($response->body,true,32,JSON_THROW_ON_ERROR);};
 $reader=new \Greenwood\Provider\ResourcePolicyHttpReader('http://127.0.0.1',$g->getChainId(),bin2hex($g->getGenesisHash()),null,$fetch);$view=$reader->read();
 check($view->height===3&&$view->stateRoot===bin2hex($restart->state->getStateRoot())&&$view->source==='PINNED_NODE_ASSERTION','Policy reader uses actual native RPC and labels source trust honestly');
 rejects(fn()=>new \Greenwood\Provider\ResourcePolicyHttpReader('http://remote.invalid',$g->getChainId(),bin2hex($g->getGenesisHash())),'Remote unencrypted policy authority denied');
 rejects(fn()=>new \Greenwood\Provider\ResourcePolicyHttpReader('https://node.invalid',$g->getChainId(),bin2hex($g->getGenesisHash())),'Remote policy TLS certificate fingerprint must be explicit');
 $badFetch=static function(string $path)use($fetch):array{$r=$fetch($path);if(str_contains($path,'/v1/resources/'))$r['state_root']=str_repeat('0',64);return $r;};
 rejects(fn()=>(new \Greenwood\Provider\ResourcePolicyHttpReader('http://127.0.0.1',$g->getChainId(),bin2hex($g->getGenesisHash()),null,$badFetch))->read(),'Incoherent policy page cannot authorize image distribution');
 $badCursor=static function(string $path)use($fetch):array{$r=$fetch($path);if(str_contains($path,'/v1/resources/'))$r['next_cursor']=str_repeat('a',64);return $r;};
 rejects(fn()=>(new \Greenwood\Provider\ResourcePolicyHttpReader('http://127.0.0.1',$g->getChainId(),bin2hex($g->getGenesisHash()),null,$badCursor))->read(),'Empty/nonprogressing policy pages cannot create an unbounded watcher loop');
 // Packaged coordinator replays real certificates; it cannot trust an arbitrary state JSON.
 $workflowDir=$temp.'/workflow';mkdir($workflowDir,0700);mkdir($workflowDir.'/blocks',0700);
 foreach(glob($temp.'/C/blocks/*') as $file)copy($file,$workflowDir.'/blocks/'.basename($file));
 $genesisJson=json_decode($g->toJson(),true);foreach($genesisJson['validators'] as &$validator)$validator['endpoint']='tcp://127.0.0.1:1';unset($validator);
 file_put_contents($workflowDir.'/genesis.json',json_encode($genesisJson));chmod($workflowDir.'/genesis.json',0600);
 $workflowConfig=['enabled'=>true,'allowed_operations'=>['relay'],'chain_id'=>$g->getChainId(),'genesis_hash'=>bin2hex($g->getGenesisHash()),'finalized_height'=>3,'finalized_hash'=>bin2hex($restart->tip->getHash()),'state_root'=>bin2hex($restart->state->getStateRoot()),'relay_origin'=>'http://127.0.0.1:1'];
 $runWorkflow=static function(array $config)use($workflowDir):array{
  file_put_contents($workflowDir.'/config.json',json_encode($config));chmod($workflowDir.'/config.json',0600);
  $command=[PHP_BINARY,'-d','ffi.enable=true'];$extension=getenv('GREENWOOD_SQLITE_EXTENSION');if($extension)$command=array_merge($command,['-d','extension='.$extension]);
  $command=array_merge($command,[dirname(__DIR__,2).'/system-services/runtime/resource-workflow.php',$workflowDir,'relay']);
  $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($process),$out,$error];
 };
 [$code,$out,$error]=$runWorkflow($workflowConfig);check($code===0&&json_decode($out,true)['result']===[],'Packaged coordinator accepts certificate-replayed pinned history without broadcasting empty outbox: '.$error);
 $bad=$workflowConfig;$bad['state_root']=str_repeat('0',64);check($runWorkflow($bad)[0]===1,'Packaged coordinator rejects incorrect finalized state pin');
 $bad=$workflowConfig;$bad['enabled']=false;check(json_decode($runWorkflow($bad)[1],true)['state']==='DISABLED','Disabled service reads no signing key or history');
 unlink($workflowDir.'/blocks/0000000000000003-cert.bin');check($runWorkflow($workflowConfig)[0]===1,'Missing certificate prevents coordinator relay');
 echo json_encode(['status'=>'PASS','checks'=>$checks,'validators'=>3,'certified_blocks'=>3,'replay'=>true,'network_transport_tested'=>false,'seconds'=>round(microtime(true)-$started,3)],JSON_PRETTY_PRINT)."\n";
}finally{
 foreach($chains as $chain)$chain->index->close();if(isset($restart))$restart->index->close();
 if(is_dir($temp)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($temp);}
}
