<?php
declare(strict_types=1);
namespace Greenwood\Edge;

/** Fixed-scope Linux Apache effects. No caller commands, paths, or incoming-host backends. */
final class ApacheSystemAdapter
{
    public const MANAGED_FILE='/etc/apache2/sites-enabled/greenwood-managed-ingress.conf';
    private array $commands=[];
    public function __construct(private readonly string $directory, private readonly ?string $sourceAddress = null,
        private readonly ?\Closure $contentVerifier = null) {
        if(PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==0)throw new \RuntimeException('Privileged Linux Apache adapter required');
    }
    public function candidate(string $candidate):bool {
        if(realpath(dirname($candidate))!==realpath($this->directory)||!preg_match('/^candidate-[a-f0-9]{64}\.conf$/D',basename($candidate)))throw new \RuntimeException('Candidate outside managed scope');
        $main=file_get_contents('/etc/apache2/apache2.conf');
        $pattern='/^IncludeOptional sites-enabled\/\*\.conf\s*$/m';
        if(preg_match_all($pattern,$main)!==1)throw new \RuntimeException('Unsupported Apache include layout');
        $includes='';$files=glob('/etc/apache2/sites-enabled/*.conf');sort($files);
        foreach($files as $file){if($file!==self::MANAGED_FILE)$includes.='Include "'.$file.'"'."\n";}
        $includes.='Include "'.$candidate.'"';
        $staged=preg_replace($pattern,$includes,$main);
        $path=$this->directory.'/candidate-main.conf';file_put_contents($path,$staged);chmod($path,0600);
        return $this->run(['/usr/sbin/apache2ctl','-t','-f',$path])['exit']===0;
    }
    public function active():bool{return $this->run(['/usr/sbin/apache2ctl','-t'])['exit']===0;}
    public function reload():bool{return $this->run(['/usr/bin/systemctl','reload','apache2'])['exit']===0;}
    public function commands():array{return $this->commands;}
    public function probe(array $routes,array $withdrawn,string $marker):array {
        $dump=$this->run(['/usr/sbin/apache2ctl','-S']);
        $ordered=$dump['exit']===0&&preg_match('/127\.0\.0\.1:8081\s+(?:is a NameVirtualHost\s+default server )?greenwood-unpublished\.invalid/',$dump['output'])===1;
        $listeners=$this->run(['/usr/bin/ss','-lntH','sport = :8081']);
        $lines=array_values(array_filter(explode("\n",trim($listeners['output']))));
        $loopback=count($lines)===1&&str_contains($lines[0],'127.0.0.1:8081');
        $result=['ok'=>false,'default_first'=>$ordered,'loopback_only'=>$loopback,'vhosts'=>$dump['output'],'listeners'=>$listeners['output'],'managed'=>[],'denied'=>[]];
        for($try=0;$try<8;$try++){
            $ok=$ordered&&$loopback;$result['managed']=[];$result['denied']=[];
            foreach($routes as $r){
                $direct=$this->http('http://'.$r['binding']['address'].':'.$r['binding']['port'].'/',$r['hostname'],$r,$this->sourceAddress);
                $edge=$this->http('http://127.0.0.1:8081/',$r['hostname'],$r);
                $matches=$direct['status']>=200&&$direct['status']<300&&$edge['status']===$direct['status']&&$edge['body_sha256']===$direct['body_sha256'];
                $result['managed'][$r['hostname']]=['match'=>$matches,'backend'=>$direct,'loopback'=>$edge];$ok=$ok&&$matches;
            }
            foreach(array_unique(array_merge($withdrawn,['unknown-greenwood.invalid']))as$host){
                $r=$this->http('http://127.0.0.1:8081/',$host);
                $denied=$r['status']===403&&str_contains($r['body'],'greenwood-generation-'.$marker);
                $result['denied'][$host]=['confirmed'=>$denied]+$r;$ok=$ok&&$denied;
            }
            if($ok){$result['ok']=true;return $result;}
            usleep(150000);
        }
        return $result;
    }
    private function http(string$url,string$host,?array$route=null,?string$source=null):array {
        try {
            [$status,$body]=BoundHttpProbe::fetch($url,$host,$source);
            $hash=$route!==null&&$this->contentVerifier!==null
                ?($this->contentVerifier)($route,$body,static fn(string $path):array=>BoundHttpProbe::fetch(rtrim($url,'/').$path,$host,$source))
                :hash('sha256',$body);
            return ['status'=>$status,'body_sha256'=>$hash,'body'=>substr($body,0,1024)];
        } catch(\Throwable) {
            return ['status'=>0,'body_sha256'=>'','body'=>''];
        }
    }
    private function run(array$args):array {
        $p=proc_open($args,[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new \RuntimeException('Apache command unavailable');
        foreach($pipes as$f)stream_set_blocking($f,false);$output='';$deadline=microtime(true)+10;
        do{$output.=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);$state=proc_get_status($p);if(!$state['running'])break;if(microtime(true)>$deadline){proc_terminate($p);throw new \RuntimeException('Apache command timed out');}usleep(10000);}while(true);
        $output.=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);foreach($pipes as$f)fclose($f);$closed=proc_close($p);
        $result=['exit'=>$state['exitcode']>=0?$state['exitcode']:$closed,'output'=>$output];$this->commands[]=['argv'=>$args]+$result;return $result;
    }
}
