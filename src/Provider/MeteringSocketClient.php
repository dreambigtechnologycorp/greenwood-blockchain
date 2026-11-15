<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Economics\CanonicalJson;

/** Host collector -> private nonroot OCI meter IPC. Socket credentials are never public RPC credentials. */
final class MeteringSocketClient implements MeterJournal
{
    public function __construct(private readonly string $socket)
    {
        $dir=dirname($socket);
        if(basename($socket)!=='meter.sock'||realpath($dir)!==$dir||!is_dir($dir)||is_link($dir)||(fileperms($dir)&0077)!==0||!in_array(fileowner($dir),[posix_geteuid(),65532],true))throw new \InvalidArgumentException('Private canonical meter IPC directory required');
    }
    private function request(array $command): array
    {
        clearstatcache(true,$this->socket);
        if(is_link($this->socket)||!file_exists($this->socket)||filetype($this->socket)!=='socket'||(fileperms($this->socket)&0077)!==0||!in_array(fileowner($this->socket),[posix_geteuid(),65532],true))throw new \RuntimeException('Private meter socket unavailable');
        $raw=CanonicalJson::encode($command)."\n";if(strlen($raw)>65536)throw new \InvalidArgumentException('Meter command too large');
        $peer=@stream_socket_client('unix://'.$this->socket,$errno,$error,2);if(!$peer)throw new \RuntimeException('Meter service unavailable');stream_set_timeout($peer,2);
        try{$written=0;while($written<strlen($raw)){$n=fwrite($peer,substr($raw,$written));if($n===false||$n===0)throw new \RuntimeException('Meter IPC write failed');$written+=$n;}fflush($peer);
            $reply=fgets($peer,65538);if($reply===false||strlen($reply)>65536||!str_ends_with($reply,"\n"))throw new \RuntimeException('Incomplete meter response');$r=CanonicalJson::decode(substr($reply,0,-1));
            if(($r['status']??null)!=='OK'||!is_array($r['result']??null))throw new \RuntimeException('Meter evidence is UNVERIFIED');return $r['result'];
        }finally{fclose($peer);}
    }
    public function bind(array $scope): void {$this->request(['action'=>'bind','scope'=>$scope]);}
    public function segmentState(string $commitment,string $runtime): ?string {$r=$this->request(['action'=>'segment-state','commitment'=>$commitment,'runtime'=>$runtime]);return $r['state'];}
    public function openSegments(string $runtime): array {return $this->request(['action'=>'open-segments','runtime'=>$runtime])['segments'];}
    public function closeBoundary(string $commitment,array $sample): void {$this->request(['action'=>'close-boundary','commitment'=>$commitment,'sample'=>$sample]);}
    public function begin(string $commitment,array $sample): void {$this->request(['action'=>'begin','commitment'=>$commitment,'sample'=>$sample]);}
    public function checkpoint(string $commitment,array $sample): array {return $this->request(['action'=>'checkpoint','commitment'=>$commitment,'sample'=>$sample]);}
    public function finish(string $commitment,array $sample): void {$this->request(['action'=>'finish','commitment'=>$commitment,'sample'=>$sample]);}
    public function bundle(string $commitment): array {return $this->request(['action'=>'bundle','commitment'=>$commitment]);}
}
