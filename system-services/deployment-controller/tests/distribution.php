<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Greenwood\DeploymentController\Assignment\{AssignmentDocument,AssignmentReceiver,AssignmentDistributor};
function ensure(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function reject(Closure $work,string $label):void{$failed=false;try{$work();}catch(Throwable){$failed=true;}ensure($failed,$label);}
$root=sys_get_temp_dir().'/gw-distribution-'.bin2hex(random_bytes(8));mkdir($root,0700);
foreach(['node-1','node-4']as$n){mkdir($root.'/'.$n,0700);mkdir($root.'/'.$n.'/assignments',0700);}
$authorized=true;$allocated=true;$crashNode=null;$faultNode=null;$wrongAck=false;
$authority=static function($d)use(&$authorized):bool{return $authorized;};$allocation=static function($d)use(&$allocated):bool{return $allocated;};
$receivers=[];$make=function($node)use($root,$authority,$allocation,&$crashNode){return new AssignmentReceiver($node,$root.'/'.$node.'/assignments',$root.'/'.$node.'/ledger.sqlite',$authority,$allocation,static function()use($node,&$crashNode){if($crashNode===$node)throw new RuntimeException('crash after file fsync');},static fn()=>true);};
foreach(['node-1','node-4']as$n)$receivers[$n]=$make($n);
$send=function($node,$request)use(&$receivers,&$faultNode,&$wrongAck){if($faultNode===$node)throw new RuntimeException('link unavailable');$ack=$receivers[$node]->handle('deployment-controller',$request);if($wrongAck)$ack['generation']=str_repeat('f',64);return $ack;};
$db=new PDO('sqlite:'.$root.'/distribution.sqlite');$distr=new AssignmentDistributor($db,['node-1','node-4'],$authority,$send);
$a=['chain_id'=>'greenwood-physical-v1','registry_contract_id'=>\Greenwood\DeploymentController\Auth\DeploymentIdentity::REGISTRY,'application_id'=>1,'service'=>'web','release_id'=>1,'provider_id'=>str_repeat('a',64),'provider_address'=>'10.77.0.100','ingress_port'=>21001,'instance_id'=>'app-1-web','image_digest'=>'sha256:'.str_repeat('b',64),'provider_endpoint'=>'tcp://10.77.0.100:19443','provider_tls_name'=>'provider.greenwood'];
$d=['chain_id'=>'greenwood-physical-v1','registry_contract_id'=>\Greenwood\DeploymentController\Auth\DeploymentIdentity::REGISTRY,'operation_id'=>str_repeat('c',32),'account_id'=>str_repeat('d',64),'version'=>1,'previous_generation'=>null,'assignment'=>$a];
mkdir($root.'/node-4/admissions',0700);mkdir($root.'/node-4/content',0700);mkdir($root.'/node-4/apply',0700);
$edge=new \Greenwood\DeploymentController\Provider\TimerEdgeAdmission($root.'/node-4',$distr,static fn()=>str_repeat('e',64));
$stored=$a;ksort($stored);$stored['assignment_id']=AssignmentDocument::assignmentId($d);
$proof=['authorized'=>true,'registry_contract'=>\Greenwood\DeploymentController\Auth\DeploymentIdentity::REGISTRY,'chain_id'=>$d['chain_id'],'account_id'=>$d['account_id']];
$request=static fn($action,$doc)=>['action'=>$action,'document'=>$doc,'nonce'=>bin2hex(random_bytes(32)),'issued_at'=>time(),'expires_at'=>time()+30];
try{
    $faultNode='node-4';reject(fn()=>$edge->admit($d['operation_id'],$stored,$proof),'Partial prepare accepted');ensure(!is_file($root.'/node-4/content/1-web.json'),'Content published without both acknowledgments');ensure(!is_file($root.'/node-1/assignments/1-web.json'),'Prepare installed active assignment');
    $faultNode=null;$crashNode='node-4';reject(fn()=>$distr->distribute($d),'Crash after install accepted');ensure($db->query('SELECT state FROM distribution')->fetchColumn()==='PENDING','Partial commit was admitted');
    $crashNode=null;$receivers['node-4']=$make('node-4');$db=new PDO('sqlite:'.$root.'/distribution.sqlite');$distr=new AssignmentDistributor($db,['node-1','node-4'],$authority,$send);
    $edge=new \Greenwood\DeploymentController\Provider\TimerEdgeAdmission($root.'/node-4',$distr,static fn()=>str_repeat('e',64));
    $edge->admit($d['operation_id'],$stored,$proof);ensure(is_file($root.'/node-4/content/1-web.json'),'Acknowledged assignment did not admit content');
    $ack=$distr->distribute($d);ensure($ack['acknowledged']&&count($ack['nodes'])===2,'Restart failed to gather both acknowledgments');
    $timer=new \Greenwood\DeploymentController\Provider\TimerAssignmentAuthority($root.'/node-4/admissions',$distr,static fn($account,$intent)=>$intent+['registry_contract'=>$intent['registry_contract_id'],'authorized'=>true,'account_id'=>$account,'release_id'=>$intent['release_number'],'observed_at'=>time()]);
    $content=json_decode(file_get_contents($root.'/node-4/content/1-web.json'),true);
    ensure($timer->authorize($stored,$content),'Timer rejected matching authorized admission');
    $badContent=$content;$badContent['body_sha256']=str_repeat('0',64);ensure(!$timer->authorize($stored,$badContent),'Timer accepted mismatched content');
    $faultNode='node-1';reject(fn()=>$timer->authorize($stored,$content),'Timer accepted missing fresh node acknowledgment');$faultNode=null;
    $deniedTimer=new \Greenwood\DeploymentController\Provider\TimerAssignmentAuthority($root.'/node-4/admissions',$distr,static fn()=>['authorized'=>false]);
    ensure(!$deniedTimer->authorize($stored,$content),'Timer bypassed account authority');
    $route=['application_id'=>1,'service'=>'web','release_id'=>1,'assignment_id'=>hash('sha256',json_encode($stored,JSON_THROW_ON_ERROR)),
        'binding'=>['address'=>$a['provider_address'],'port'=>$a['ingress_port'],'image_digest'=>$a['image_digest']],'desired'=>'PUBLISHED','state'=>'HEALTHY'];
    $apply=['confirmed'=>true,'status'=>'CONFIRMED','generation'=>str_repeat('f',64),'effective'=>['generation'=>str_repeat('f',64),'routes'=>[$route]]];
    file_put_contents($root.'/node-4/apply/apply.json',json_encode($apply));chmod($root.'/node-4/apply/apply.json',0600);
    ensure($edge->inspect($d['operation_id'],$stored,$proof)['confirmed'],'Matching effective timer route rejected');
    $apply['effective']['routes'][0]['binding']['port']++;file_put_contents($root.'/node-4/apply/apply.json',json_encode($apply));
    ensure(!$edge->inspect($d['operation_id'],$stored,$proof)['confirmed'],'Wrong effective backend accepted');
    $one=file_get_contents($root.'/node-1/assignments/1-web.json');ensure($one===file_get_contents($root.'/node-4/assignments/1-web.json'),'Installed bytes differ');
    $again=$distr->distribute($d);ensure($again['generation']===$ack['generation'],'Retry changed immutable generation');
    ensure((fileperms($root.'/node-1/assignments/1-web.json')&0777)===0600,'Unsafe record mode');
    foreach(['77e7bbde37f96369591a49fa63b5ce5b0d4e67c3bc98fd8c8fbabdff815d1715',str_repeat('0',64)]as$registry){
        $bad=$d;$bad['registry_contract_id']=$registry;reject(fn()=>$distr->distribute($bad),'Outer registry mismatch accepted');
        $bad['assignment']['registry_contract_id']=$registry;reject(fn()=>$distr->distribute($bad),'Legacy scoped assignment accepted');
    }
    $bad=$d;unset($bad['assignment']['registry_contract_id']);reject(fn()=>$distr->distribute($bad),'Unscoped legacy assignment accepted');
    $bad=$d;$bad['assignment']['image_digest']='sha256:'.str_repeat('e',64);reject(fn()=>$distr->distribute($bad),'Conflicting generation accepted');
    $allocated=false;reject(fn()=>$distr->distribute($d),'Unapproved provider allocation accepted');$allocated=true;
    $authorized=false;reject(fn()=>$distr->distribute($d),'Revoked assignment admitted');$authorized=true;
    $wrongAck=true;reject(fn()=>$distr->distribute($d),'Wrong generation ack accepted');$wrongAck=false;
    $expired=$request('status',$d);$expired['issued_at']=time()-60;$expired['expires_at']=time()-1;reject(fn()=>$receivers['node-1']->handle('deployment-controller',$expired),'Expired challenge accepted');
    $r=$request('status',$d);$receivers['node-1']->handle('deployment-controller',$r);reject(fn()=>$receivers['node-1']->handle('deployment-controller',$r),'Replay accepted');
    reject(fn()=>$receivers['node-1']->handle('edge-controller',$request('status',$d)),'Wrong principal accepted');
    ensure($receivers['node-1']->handle('edge-admission',$request('status',$d))['state']==='COMMITTED','Read-only admission status rejected');
    foreach(['prepare','commit']as$action)reject(fn()=>$receivers['node-1']->handle('edge-admission',$request($action,$d)),'Admission identity installed assignment');
    // Monotonic versions require exact previous generation; old generations never replace newer ones.
    $next=$d;$next['version']=2;$next['previous_generation']=$ack['generation'];$next['assignment']['release_id']=2;
    $new=$distr->distribute($next);ensure($new['version']===2,'Second generation not committed');
    reject(fn()=>$distr->distribute($d),'Older assignment replaced newer generation');
    $bad=$next;$bad['version']=3;$bad['previous_generation']=str_repeat('0',64);reject(fn()=>$distr->distribute($bad),'Wrong parent generation accepted');
    $protected=$d;$protected['assignment']['registry_contract_id']=str_repeat('0',64);reject(fn()=>$distr->distribute($protected),'Wrong registry assignment accepted');
    echo "Assignment distribution: matching atomic installs, two-node ACK gate, partial delivery, restart-after-fsync, retry, conflict, revocation, allocation, wrong ACK, expiry, replay, principal and monotonic generation checks passed\n";
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as$p){if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir($root);
}
