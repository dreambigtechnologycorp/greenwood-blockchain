<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
use Greenwood\Provider\MeteringSocketClient;
use Greenwood\Economics\CanonicalJson;
$directory=sys_get_temp_dir().'/greenwood-meter-ipc-'.bin2hex(random_bytes(8));mkdir($directory,0700);$process=null;$pipes=[];
$start=static function()use($directory,&$process,&$pipes):void{
 $command=[PHP_BINARY];
 // Reuse the exact already-loaded external extension when PHP's default configuration lacks it.
 $extensionPath=getenv('GREENWOOD_SQLITE_EXTENSION')?:'/tmp/intown-php-sqlite/usr/lib/php/20250925/pdo_sqlite.so';
 if(is_file($extensionPath))$command=array_merge($command,['-d','extension='.$extensionPath]);
 $command=array_merge($command,[dirname(__DIR__,2).'/system-services/metering/meter.php',$directory,'--unix']);
 $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Meter test process unavailable');
 $deadline=microtime(true)+5;while(microtime(true)<$deadline){clearstatcache(true,$directory.'/meter.sock');if(file_exists($directory.'/meter.sock')){
   $probe=@stream_socket_client('unix://'.$directory.'/meter.sock',$errno,$error,0.1);if($probe){fclose($probe);return;}}
  if(!proc_get_status($process)['running'])throw new RuntimeException('Meter failed: '.stream_get_contents($pipes[2]));usleep(10000);
 }throw new RuntimeException('Meter IPC startup deadline');
};
$stop=static function(int $signal=15)use(&$process,&$pipes):void{if(is_resource($process)){proc_terminate($process,$signal);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);$process=null;$pipes=[];}};
$commitment=str_repeat('a',64);$runtime=str_repeat('b',64);$sample=fn(int $cpu)=>['version'=>1,'verification_level'=>'C','source'=>'local_cgroup_v2','runtime_identity'=>$runtime,'cpu_ms'=>(string)$cpu];
try{
 $start();$client=new MeteringSocketClient($directory.'/meter.sock');$client->begin($commitment,$sample(10));$client->checkpoint($commitment,$sample(30));check($client->segmentState($commitment,$runtime)==='OPEN','Real private service stores open segment');
 $stop(9);$start();check($client->segmentState($commitment,$runtime)==='OPEN','SIGKILL/restart preserves FULL/WAL segment and reconciles stale socket');
 rejects(fn()=>$client->begin($commitment,$sample(40)),'Service restart cannot reset open CPU counters');rejects(fn()=>$client->bundle($commitment),'Crash leaves missing terminal telemetry unverified');
 $client->finish($commitment,$sample(50));$bundle=$client->bundle($commitment);check($bundle['cpu_ms']==='40'&&!$bundle['subsidy_eligible'],'Actual IPC service returns conserved nonpayable counter delta');
 $next=str_repeat('e',64);$client->begin($next,$sample(60));check($client->openSegments($runtime)[0]['commitment']===$next,'Private IPC exposes bounded current runtime ownership');
 $client->closeBoundary($next,$sample(80));check($client->bundle($next)['cpu_ms']===null&&$client->bundle($next)['measurement_status']==='BOUNDARY_RECONCILIATION_REQUIRED','Cross-boundary samples never manufacture an exact payable CPU split');
 rejects(fn()=>$client->finish($commitment,$sample(80)),'Repeated IPC finish cannot mutate closed evidence');
 $bad=$sample(0);$bad['verification_level']='A';rejects(fn()=>$client->begin(str_repeat('c',64),$bad),'IPC cannot promote provider telemetry to independent');
 $peer=stream_socket_client('unix://'.$directory.'/meter.sock');fwrite($peer,CanonicalJson::encode(['action'=>'shell','secret'=>'PRIVATE_TEST_SECRET'])."\n");$reply=fgets($peer);fclose($peer);check(str_contains($reply,'UNVERIFIED')&&!str_contains($reply,'PRIVATE_TEST_SECRET'),'Protocol rejects arbitrary operations without echoing sensitive input');
 chmod($directory.'/meter.sock',0666);rejects(fn()=>$client->bundle($commitment),'Publicly accessible socket is rejected');chmod($directory.'/meter.sock',0600);
 $stop();$start();check($client->bundle($commitment)['evidence_bundle_hash']===$bundle['evidence_bundle_hash'],'Clean service restart preserves canonical evidence digest');
 echo json_encode(['status'=>'PASS','checks'=>$checks,'actual_private_unix_ipc'=>true,'actual_process_sigkill_recovery'=>true,'oci_container_executed'=>false,'physical_independence'=>false,'samples'=>'explicit_fixture_counters'],JSON_PRETTY_PRINT)."\n";
}finally{$stop();unset($client);foreach(glob($directory.'/*')?:[] as $path)unlink($path);rmdir($directory);}
