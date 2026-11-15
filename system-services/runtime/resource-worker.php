<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Greenwood\Economics\CanonicalJson;

// Private IPC only. The collector/host relay owns transport outside this service.
umask(0077);
if($argc!==2)throw new RuntimeException('Explicit private workflow directory required');
$directory=$argv[1];
if(realpath($directory)!==$directory||!is_dir($directory)||fileowner($directory)!==posix_geteuid()||(fileperms($directory)&0077)!==0)throw new RuntimeException('Private canonical workflow directory required');
$lockPath=$directory.'/worker.lock';
if(is_link($lockPath)||(file_exists($lockPath)&&(!is_file($lockPath)||fileowner($lockPath)!==posix_geteuid()||(fileperms($lockPath)&0077)!==0)))throw new RuntimeException('Unsafe ownership lock');
$lock=fopen($lockPath,'c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Workflow journal already owned');
$socket=$directory.'/workflow.sock';
if(file_exists($socket)||is_link($socket)){
    if(is_link($socket)||filetype($socket)!=='socket'||fileowner($socket)!==posix_geteuid())throw new RuntimeException('Unsafe stale workflow socket');
    unlink($socket);
}
$server=stream_socket_server('unix://'.$socket,$errno,$error);if(!$server)throw new RuntimeException('Private workflow IPC unavailable');chmod($socket,0600);
$running=true;pcntl_async_signals(true);pcntl_signal(SIGTERM,static function()use(&$running){$running=false;});pcntl_signal(SIGINT,static function()use(&$running){$running=false;});
try {
    while($running){
        $peer=@stream_socket_accept($server,1);if(!$peer)continue;stream_set_timeout($peer,2);$process=null;
        try {
            $raw=fgets($peer,1048578);
            if($raw===false||strlen($raw)>1048576||!str_ends_with($raw,"\n"))throw new RuntimeException('Bounded complete frame required');
            $frame=CanonicalJson::decode(trim($raw));$operation=$frame['operation']??'';
            if(!in_array($operation,['enqueue','prepare-local','prepare-bootstrap','prepare-independent','attest-independent','relay'],true)||!is_array($frame['request']??null))throw new RuntimeException('Explicit workflow request required');
            $process=proc_open([PHP_BINARY,'-d','ffi.enable=true',__DIR__.'/resource-workflow.php',$directory,$operation],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Workflow process unavailable');
            $input=CanonicalJson::encode($frame['request']);$offset=0;
            while($offset<strlen($input)){$n=fwrite($pipes[0],substr($input,$offset));if(!$n)throw new RuntimeException('Workflow input failed');$offset+=$n;}fclose($pipes[0]);
            stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$stdout='';$stderr='';$deadline=hrtime(true)+600_000_000_000;
            do{
                $stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);
                if(strlen($stdout)+strlen($stderr)>2097152)throw new RuntimeException('Workflow output bound exceeded');
                $status=proc_get_status($process);
                if(!$running||hrtime(true)>$deadline)throw new RuntimeException('Workflow interrupted or timed out');
                if($status['running'])usleep(10000);
            }while($status['running']);
            $stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);
            if($status['exitcode']!==0)throw new RuntimeException('Workflow rejected request; consult protected service inputs');
            $result=json_decode($stdout,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($result))throw new RuntimeException('Invalid workflow response');
            $response=CanonicalJson::encode($result)."\n";
        }catch(Throwable $error){$response=CanonicalJson::encode(['status'=>'UNVERIFIED','error'=>$error->getMessage()])."\n";}
        finally{
            if(is_resource($process)){
                if(proc_get_status($process)['running'])proc_terminate($process,9);
                foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);
            }
        }
        try{$offset=0;while($offset<strlen($response)){$n=@fwrite($peer,substr($response,$offset));if(!$n)break;$offset+=$n;}}finally{fclose($peer);}
    }
}finally{fclose($server);if(file_exists($socket))unlink($socket);flock($lock,LOCK_UN);fclose($lock);}
