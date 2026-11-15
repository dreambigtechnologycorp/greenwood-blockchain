<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
use Greenwood\Provider\MeteringService;
use Greenwood\Resources\Metering\SignedObservation;
use Greenwood\Crypto\Ed25519KeyPair;
$dir=sys_get_temp_dir().'/greenwood-checkpoints-'.bin2hex(random_bytes(8));$commit=str_repeat('a',64);
$sample=fn($cpu,$io)=>['version'=>1,'verification_level'=>'C','source'=>'local_cgroup_v2','runtime_identity'=>str_repeat('1',64),'cpu_ms'=>(string)$cpu,'memory_current_bytes'=>'12','memory_peak_bytes'=>'20','io_devices'=>['8:0'=>['rbytes'=>(string)$io,'wbytes'=>'0','rios'=>'0','wios'=>'0']]];
try{
 $service=new MeteringService($dir);$service->begin($commit,$sample(10,20));$first=$service->checkpoint($commit,$sample(20,30));unset($service);
 $service=new MeteringService($dir);$second=$service->checkpoint($commit,$sample(30,40));check($second['sequence']===1&&$second['previous_hash']===$first['digest'],'Crash recovery preserves sequence and hash lineage');
 rejects(fn()=>$service->checkpoint($commit,$sample(25,50)),'CPU checkpoint reset rejected');
 rejects(fn()=>$service->checkpoint($commit,$sample(40,35)),'Block counter reset rejected');
 $missing=$sample(40,50);$missing['io_devices']=null;rejects(fn()=>$service->checkpoint($commit,$missing),'Unavailable measurement cannot silently replace previous device');
 rejects(fn()=>$service->finish($commit,$sample(25,50)),'Final sample cannot erase observed CPU');
 $service->finish($commit,$sample(50,60));$bundle=$service->bundle($commit);check($bundle['cpu_ms']==='40','Periodic samples do not bill cumulative counters repeatedly');
 rejects(fn()=>$service->checkpoint($commit,$sample(60,70)),'Closed interval immutable');
 $scope=['chain'=>'greenwood-meter-local-fixture','genesis'=>str_repeat('2',64),'provider_id'=>str_repeat('3',64),'workload_id'=>str_repeat('4',64),'assignment_generation'=>str_repeat('5',64),'commitment'=>$commit,'interval_start_height'=>10,'interval_end_height'=>20,'catalog_hash'=>str_repeat('6',64),'manifest_hash'=>str_repeat('7',64)];
 $key=Ed25519KeyPair::generate();$signed=SignedObservation::sign($scope,$bundle,$key);$verified=SignedObservation::verify($signed,$scope,bin2hex($key->getPublicKey()));check($verified['payable']===false&&$verified['verification_level']==='C','Signature does not promote physical trust');
 $wrong=$scope;$wrong['chain']='another-chain';rejects(fn()=>SignedObservation::verify($signed,$wrong,bin2hex($key->getPublicKey())),'Signed observation cannot cross chain');
 $wrong=$scope;$wrong['assignment_generation']=str_repeat('0',64);rejects(fn()=>SignedObservation::verify($signed,$wrong,bin2hex($key->getPublicKey())),'Assignment restart/reassignment cannot inherit signed observation');
 $bad=$bundle;$bad['bundle']['verification_level']='A';rejects(fn()=>SignedObservation::sign($scope,$bad,$key),'No trust promotion through provider signing');
 $signed['signature']=str_repeat('0',128);rejects(fn()=>SignedObservation::verify($signed,$scope,bin2hex($key->getPublicKey())),'Forged signature rejected');
 $db=new PDO('sqlite:'.$dir.'/meter-segments.sqlite');$db->exec("UPDATE checkpoints SET digest='".str_repeat('0',64)."' WHERE sequence=0");
 rejects(fn()=>$service->bundle($commit),'Corrupt persistent checkpoint lineage cannot produce a signed bundle');unset($db);
 echo json_encode(['status'=>'PASS','checks'=>$checks,'physical_qualification'=>false],JSON_PRETTY_PRINT)."\n";
}finally{unset($service);foreach(glob($dir.'/*')?:[] as $file)unlink($file);if(is_dir($dir))rmdir($dir);}
