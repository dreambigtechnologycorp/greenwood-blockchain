<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
use Greenwood\Economics\CanonicalJson;
$dir=sys_get_temp_dir().'/greenwood-resource-worker-'.bin2hex(random_bytes(8));mkdir($dir,0700);
file_put_contents($dir.'/config.json','{"enabled":false}');chmod($dir.'/config.json',0600);$process=null;$pipes=[];
$command=[PHP_BINARY,dirname(__DIR__,2).'/system-services/runtime/resource-worker.php',$dir];
$start=static function()use(&$process,&$pipes,$command,$dir):void{
 $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$deadline=microtime(true)+5;
 while(microtime(true)<$deadline){$socket=@stream_socket_client('unix://'.$dir.'/workflow.sock',$errno,$error,0.05);if($socket){fclose($socket);return;}if(!proc_get_status($process)['running'])throw new RuntimeException(stream_get_contents($pipes[2]));usleep(10000);}throw new RuntimeException('Worker startup timed out');
};
$stop=static function(int $signal=15)use(&$process,&$pipes):void{if(is_resource($process)){proc_terminate($process,$signal);foreach($pipes as $p)if(is_resource($p))fclose($p);proc_close($process);$process=null;}};
$call=static function(string $raw)use($dir):array{$socket=stream_socket_client('unix://'.$dir.'/workflow.sock',$errno,$error,2);stream_set_timeout($socket,5);fwrite($socket,$raw);$response=fgets($socket,1048577);fclose($socket);if(!$response)throw new RuntimeException('Missing response');return json_decode($response,true,32,JSON_THROW_ON_ERROR);};
try{
 $start();check((fileperms($dir.'/workflow.sock')&0077)===0,'Socket is private');
 $request=CanonicalJson::encode(['operation'=>'prepare-bootstrap','request'=>[]])."\n";
 check($call($request)['state']==='DISABLED','Worker subprocess honors disabled configuration');
 check($call(CanonicalJson::encode(['operation'=>'shell','request'=>[]])."\n")['status']==='UNVERIFIED','Arbitrary command dispatch rejected');
 check($call("{bad json}\n")['status']==='UNVERIFIED','Malformed request cannot terminate the service');
 $other=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$otherPipes);foreach($otherPipes as $p)fclose($p);check(proc_close($other)!==0,'Exclusive process ownership prevents a second worker');
 file_put_contents($dir.'/config.json',CanonicalJson::encode(['enabled'=>true,'allowed_operations'=>[]]));
 check($call($request)['status']==='UNVERIFIED','Per-operation local consent is required');
 file_put_contents($dir.'/config.json','{"enabled":false}');$stop(9);$start();
 check($call($request)['state']==='DISABLED','SIGKILL recovery reconciles stale socket without widening authority');
 $stop();check(!file_exists($dir.'/workflow.sock'),'Clean shutdown removes owned socket');
 echo json_encode(['status'=>'PASS','checks'=>$checks,'actual_unix_ipc'=>true,'actual_subprocess'=>true,'oci_execution'=>false,'live_transactions'=>0],JSON_PRETTY_PRINT)."\n";
}finally{$stop();foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
