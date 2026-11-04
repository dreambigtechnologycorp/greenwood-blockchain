<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Greenwood\DeploymentController\Assignment\{AssignmentDocument,AssignmentDistributor};
use Greenwood\DeploymentController\Provider\{TimerEdgeAdmission,EdgeAdmissionEndpoint};
function check(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function denied(Closure $f,string $m):void{try{$f();}catch(Throwable){return;}throw new RuntimeException($m);}
umask(0077);$root=sys_get_temp_dir().'/gw-edge-admission-'.bin2hex(random_bytes(8));mkdir($root,0700);
foreach(['assignments','content','apply','admissions']as$n)mkdir($root.'/'.$n,0700);
$allowed=true;$partial=false;$actions=[];
$a=['chain_id'=>'greenwood-physical-v1','registry_contract_id'=>\Greenwood\DeploymentController\Auth\DeploymentIdentity::REGISTRY,
    'application_id'=>1,'service'=>'web','release_id'=>1,'provider_id'=>str_repeat('a',64),'provider_address'=>'10.77.0.100',
    'ingress_port'=>21001,'instance_id'=>'app-1-web','image_digest'=>'sha256:'.str_repeat('b',64),
    'provider_endpoint'=>'tcp://10.77.0.100:19443','provider_tls_name'=>'provider.test'];
$d=['chain_id'=>$a['chain_id'],'registry_contract_id'=>$a['registry_contract_id'],'operation_id'=>str_repeat('c',32),
    'account_id'=>str_repeat('d',64),'version'=>1,'previous_generation'=>null,'assignment'=>$a];
$a['assignment_id']=AssignmentDocument::assignmentId($d);
$authorize=static fn($account,$intent)=>['authorized'=>true,'account_id'=>$account,'chain_id'=>$intent['chain_id'],'registry_contract'=>$intent['registry_contract_id']];
$db=new PDO('sqlite:'.$root.'/distribution.sqlite');
$distributor=new AssignmentDistributor($db,['node-1','node-4'],static function()use(&$allowed){return $allowed;},
    static function($node,$r)use(&$partial,&$actions):array{
        $actions[]=$r['action'];if($partial&&$node==='node-4')throw new RuntimeException('Receiver unavailable');
        return ['node_id'=>$node,'generation'=>AssignmentDocument::generation($r['document']),
            'assignment_id'=>AssignmentDocument::assignmentId($r['document']),'version'=>1,'state'=>'COMMITTED','nonce'=>$r['nonce'],'observed_at'=>time()];
    });
$timer=new TimerEdgeAdmission($root,$distributor,static fn()=>str_repeat('e',64),true);
$endpoint=new EdgeAdmissionEndpoint($timer,$authorize,static fn($r)=>['ready'=>false]);
$request=['action'=>'admit','operation_id'=>$d['operation_id'],'account_id'=>$d['account_id'],'assignment'=>$a];
try{
    denied(fn()=>$endpoint->handle('edge-admission',$request),'Unapproved principal accepted');
    $bad=$request;$bad['assignment']['ingress_port']=21002;
    denied(fn()=>$endpoint->handle('deployment-controller',$bad),'Assignment ID mismatch accepted');
    file_put_contents($root.'/assignments/1-web.json',json_encode($d['assignment']));
    $partial=true;denied(fn()=>$endpoint->handle('deployment-controller',$request),'Partial ACK admitted');
    check(!file_exists($root.'/admissions/1-web.json'),'Partial delivery wrote admission');
    $partial=false;$allowed=false;denied(fn()=>$endpoint->handle('deployment-controller',$request),'Revoked allocation admitted');
    $allowed=true;check($endpoint->handle('deployment-controller',$request)['admitted'],'Valid admission denied');
    check($endpoint->handle('deployment-controller',$request)['admitted'],'Idempotent admission failed');
    check(array_unique($actions)===['status'],'Edge attempted assignment mutation');
    $request['action']='inspect';check(!$endpoint->handle('deployment-controller',$request)['confirmed'],'Missing apply became ready');
    echo "Edge admission: principal, identity, allocation, partial ACK, protected install, idempotency and status-only verification passed\n";
}finally{
    unset($db,$distributor,$timer,$endpoint);
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as$f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($root);
}
