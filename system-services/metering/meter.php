<?php
declare(strict_types=1);
require dirname(__DIR__).'/runtime/bootstrap.php';
use Greenwood\Provider\{MeteringService,MeteringProtocol};
umask(0077);
if($argc<2||$argc>4||!str_starts_with($argv[1],'/'))throw new RuntimeException('Explicit private journal directory required');
$directory=$argv[1];$meter=new MeteringService($directory);$telemetry=new \Greenwood\Provider\ResourceTelemetryJournal($directory.'/resources');
if(($argv[2]??null)!=='--unix'){
    if($argc!==2)throw new RuntimeException('Unsupported metering invocation');
    while(($raw=fgets(STDIN,65538))!==false){fwrite(STDOUT,MeteringProtocol::handle($meter,$raw,$telemetry));fflush(STDOUT);if(strlen($raw)>65536||!str_ends_with($raw,"\n"))break;}exit(0);
}
$once=($argv[3]??null)==='--once';if($argc===4&&!$once)throw new RuntimeException('Unsupported metering option');
$lockPath=$directory.'/meter-service.lock';if(is_link($lockPath)||(is_file($lockPath)&&(fileowner($lockPath)!==posix_geteuid()||(fileperms($lockPath)&0077)!==0)))throw new RuntimeException('Unsafe meter ownership lock');
$lock=fopen($lockPath,'c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Meter service already owns journal');chmod($lockPath,0600);
$socket=$directory.'/meter.sock';if(file_exists($socket)||is_link($socket)){
    if(is_link($socket)||filetype($socket)!=='socket'||fileowner($socket)!==posix_geteuid())throw new RuntimeException('Unsafe stale meter socket');
    if(!unlink($socket))throw new RuntimeException('Cannot reconcile stale meter socket');
}
$server=stream_socket_server('unix://'.$socket,$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);if(!$server)throw new RuntimeException('Private meter IPC unavailable');chmod($socket,0600);
$running=true;if(function_exists('pcntl_async_signals')){pcntl_async_signals(true);pcntl_signal(SIGTERM,static function()use(&$running){$running=false;});pcntl_signal(SIGINT,static function()use(&$running){$running=false;});}
try{
    while($running){$peer=@stream_socket_accept($server,1);if(!$peer)continue;stream_set_timeout($peer,2);
        try{for($i=0;$i<100;$i++){$raw=fgets($peer,65538);if($raw===false)break;$reply=MeteringProtocol::handle($meter,$raw,$telemetry);$written=0;while($written<strlen($reply)){$n=fwrite($peer,substr($reply,$written));if($n===false||$n===0)throw new RuntimeException('Meter peer disconnected');$written+=$n;}fflush($peer);if(!str_ends_with($raw,"\n")||strlen($raw)>65536)break;}}
        finally{fclose($peer);}if($once)break;
    }
}finally{fclose($server);if(file_exists($socket)||is_link($socket))unlink($socket);flock($lock,LOCK_UN);fclose($lock);}
