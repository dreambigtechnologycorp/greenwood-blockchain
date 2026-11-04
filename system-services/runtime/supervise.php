<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Greenwood\Compute\Containerd\{ContainerdConnection,ContainerdClientSet,SystemServiceDefinition,SystemServiceRuntime};
use Greenwood\Compute\Containerd\Service\{ImageService,TaskService};
$path=$argv[1]??'';
if(PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==0||!str_starts_with($path,'/'))throw new RuntimeException('Protected Linux runtime required');
for($part=$path;;$part=dirname($part)){
    if(is_link($part)||!file_exists($part)||fileowner($part)!==0||(fileperms($part)&0022)!==0)throw new RuntimeException('Unsafe system-service configuration');
    if($part==='/')break;
}
if(!is_file($path)||(fileperms($path)&0077)!==0||filesize($path)>65536)throw new RuntimeException('Private bounded configuration required');
$config=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
if(!preg_match('/^sha256:[a-f0-9]{64}$/D',$config['expectedDigest']??'')
    ||!str_ends_with($config['imageRef']??'','@'.$config['expectedDigest']))throw new RuntimeException('Immutable system-service image required');
$clients=(new ContainerdClientSet(new ContainerdConnection('greenwood')))->setSnapshotter('erofs');
$image=(new ImageService($clients))->get($config['imageRef']);
if($image->getTarget()->getDigest()!==$config['expectedDigest']) throw new RuntimeException('Seed image digest mismatch');
unset($config['expectedDigest']);
$definition=new SystemServiceDefinition(...$config);
$runtime=new SystemServiceRuntime($clients);
$stopping=false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM,static function() use (&$stopping): void {$stopping=true;});
pcntl_signal(SIGINT,static function() use (&$stopping): void {$stopping=true;});
$handle=$runtime->start($definition);
fwrite(STDOUT,json_encode(['event'=>'STARTED','handle'=>$handle],JSON_THROW_ON_ERROR)."\n");
try {
    while(!$stopping){
        $process=(new TaskService($clients))->get($handle->containerId)->getProcess();
        if($process->getStatus()!==\Containerd\V1\Types\Status::RUNNING) throw new RuntimeException('Service task is not RUNNING');
        sleep(2);
    }
} finally {
    $runtime->stop($handle);
    fwrite(STDOUT,"STOPPED_CLEAN\n");
}
