<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
spl_autoload_register(static function(string $class):void{$prefix='Greenwood\\DeploymentController\\';if(str_starts_with($class,$prefix)){$path=dirname(__DIR__,2).'/system-services/deployment-controller/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require $path;}});
use Greenwood\DeploymentController\Auth\DeploymentIdentity;
use Greenwood\DeploymentController\Assignment\{AssignmentDocument,AssignmentReceiver};
use Greenwood\Provider\{HostedAssignmentJournal,HostedServiceMonitor,ProviderApplicationRegistry,ProviderApplicationOperator,ProviderApplicationReconciler,ProviderApplicationRuntimeInspector};
use Greenwood\Compute\Containerd\RegistryTransferAuth;
use Greenwood\Economics\{HostedServiceBinding,HostedFundingHttpReader};
$scope=['chain_id'=>'greenwood-hosted-boundary-test','genesis_hash'=>str_repeat('1',64),'registry_contract_id'=>str_repeat('2',64),'registry_wasm_hash'=>str_repeat('3',64)];DeploymentIdentity::configure($scope);
$root=sys_get_temp_dir().'/greenwood-hosted-boundary-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/assignments',0700);
try{
 $a=['chain_id'=>$scope['chain_id'],'registry_contract_id'=>$scope['registry_contract_id'],'application_id'=>1,'service'=>'web','release_id'=>1,'provider_id'=>str_repeat('4',64),'provider_address'=>'10.1.2.3','ingress_port'=>11000,'instance_id'=>'app-1-web','image_digest'=>'sha256:'.str_repeat('5',64),'provider_endpoint'=>'tcp://10.1.2.3:5000','provider_tls_name'=>'provider.local'];
 $d=['chain_id'=>$scope['chain_id'],'registry_contract_id'=>$scope['registry_contract_id'],'operation_id'=>str_repeat('6',32),'account_id'=>str_repeat('7',64),'version'=>1,'previous_generation'=>null,'assignment'=>$a];
 check(HostedServiceBinding::generation($d)===AssignmentDocument::generation($d),'Native generation matches existing assignment format');
 check(HostedServiceBinding::assignmentId($d)===AssignmentDocument::assignmentId($d),'Financial status lookup preserves existing controller assignment identity');
 $receiver=new AssignmentReceiver('node-1',$root.'/assignments',$root.'/receiver.sqlite',fn()=>true,fn()=>true);
 $request=fn($action)=>['action'=>$action,'document'=>$d,'nonce'=>bin2hex(random_bytes(32)),'issued_at'=>time(),'expires_at'=>time()+30];
 $receiver->handle('deployment-controller',$request('prepare'));$journal=new HostedAssignmentJournal($root.'/receiver.sqlite');$image='registry/chain/apps/1/web@'.$a['image_digest'];
 rejects(fn()=>$journal->document(1,'web',$image),'Prepared assignment cannot authorize runtime finance');
 $receiver->handle('deployment-controller',$request('commit'));check($journal->document(1,'web',$image)===$d || HostedServiceBinding::generation($journal->document(1,'web',$image))===HostedServiceBinding::generation($d),'Actual receiver committed document recovered read-only');
 rejects(fn()=>$journal->document(1,'web','registry/app@sha256:'.str_repeat('0',64)),'Wrong runtime image denied');rejects(fn()=>$journal->document(2,'web',$image),'Cross-application journal denied');
 $events=[];$allowed=false;$active=fn()=>[['application_id'=>1,'service'=>'web','image_ref'=>$image,'state'=>'RUNNING']];
 $authorize=function()use(&$allowed){if(!$allowed)throw new RuntimeException('expired');};
 $monitor=new HostedServiceMonitor($active,$authorize,function()use(&$events){$events[]='restrict';},function()use(&$events){$events[]='stop';});
 check($monitor->tick()[0]['outcome']==='RESTRICTED_STOPPED'&&$events===['restrict','stop'],'Expiry monitor restricts before stopping');
 $allowed=true;$events=[];check($monitor->tick()[0]['outcome']==='FUNDED'&&$events===[],'Valid renewal avoids stop');
 $failed=new HostedServiceMonitor($active,fn()=>throw new RuntimeException('outage'),fn()=>throw new RuntimeException('detach failed'),function()use(&$events){$events[]='stop';});
 check($failed->tick()[0]['outcome']==='RESTRICTION_RETRY_REQUIRED'&&$events===['stop'],'Stop still attempted on restriction failure');
 // Actual operator object: denied funding must precede any image/runtime mutation.
 $registry=new ProviderApplicationRegistry($root.'/apps');$inspector=new ProviderApplicationRuntimeInspector(fn()=>throw new RuntimeException('No inspect expected'),fn()=>throw new RuntimeException('No inspect expected'),fn()=>throw new RuntimeException('No inspect expected'));
 $reconciler=new ProviderApplicationReconciler($registry,$inspector);$mutations=0;
 $operator=new ProviderApplicationOperator($registry,$reconciler,function()use(&$mutations){$mutations++;},function()use(&$mutations){$mutations++;},function()use(&$mutations){$mutations++;},fundingGuard:fn()=>throw new RuntimeException('No finalized funding'));
 rejects(fn()=>$operator->start(1,'web',$image,new RegistryTransferAuth('local-test-token')),'Operator start requires funding');check($mutations===0,'Operator denies before image or container side effect');
 // Actual recovery object: expired finance detaches managed networking before task inspection/resume.
 $registry->beginStart(1,'web','app-1-web',$image,$a['image_digest']);$registry->markRunning(1,'web','app-1-web','view','parent','containerd-lease',100);
 $bindings=new \Greenwood\Provider\ProviderApplicationBindingRegistry($root.'/network','10.44.0.0/24',12000,12010);$detached=0;$resumes=0;$inspections=0;
 $network=new \Greenwood\Provider\ProviderApplicationNetwork($root.'/network',$bindings,fn()=>true,fn()=>true,function()use(&$detached){$detached++;return true;});
 $network->reserve(1,'web',new \Greenwood\Compute\Containerd\OciImageRuntimeConfig(['app'],[],'/',['8080/tcp']),$a['image_digest']);
 $recovery=new \Greenwood\Provider\ProviderApplicationRestorer($registry,function()use(&$inspections){$inspections++;throw new RuntimeException('No inspect expected');},function()use(&$resumes){$resumes++;},$network,fn()=>null,fn()=>throw new RuntimeException('Expired funding'));
 $result=$recovery->tick();check($result[0]['outcome']==='RETRY_REQUIRED'&&$detached===1,'Expired recovery cleans up actual managed network state');
 check($resumes===0&&$inspections===0,'Unfunded recovery cannot inspect or resume a task');check($network->find(1,'web')['state']==='DETACHED','Recovery denial leaves detached journal state');
 foreach(['http://user:pass@localhost:1','http://localhost:1/path','file:///tmp/test','http://localhost:1?url=evil'] as $origin)rejects(fn()=>new HostedFundingHttpReader($origin),'Unsafe funding node origin');
 echo json_encode(['status'=>'PASS','checks'=>$checks,'actual_receiver_sqlite'=>true,'actual_operator_guard'=>true,'container_execution_tested'=>false],JSON_PRETTY_PRINT)."\n";
}finally{
 unset($receiver,$journal,$registry,$operator,$reconciler,$bindings,$network,$recovery);
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
