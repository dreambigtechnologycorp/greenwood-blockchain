<?php
declare(strict_types=1);
namespace Greenwood\Edge;

/** Privileged internal adapter. Invoke under the EdgeRouteRegistry exclusive lock. */
final class ApacheApplyAdapter
{
    public function __construct(
        private readonly string $directory,
        private readonly string $managedFile,
        private readonly ApacheIngressConfiguration $renderer,
        private readonly \Closure $validateCandidate,
        private readonly \Closure $validateActive,
        private readonly \Closure $reload,
        private readonly \Closure $probe,
        private readonly ?\Closure $checkpoint = null,
    ) {
        if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new \RuntimeException('Cannot create apply journal');
        foreach ([$directory, dirname($managedFile)] as $path) {
            if(is_link($path)||!is_dir($path)||(fileperms($path)&0022)!==0) throw new \RuntimeException('Unsafe Apache apply directory');
        }
        if(is_link($managedFile))throw new \RuntimeException('Managed Apache file must not be a symlink');
    }
    public function state(): array {
        $p=$this->directory.'/apply.json';
        return is_file($p)?json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR):[];
    }
    public function apply(array $allRoutes): array {
        $routes=array_values(array_filter($allRoutes,static fn($r)=>($r['desired']??'')==='PUBLISHED'&&($r['state']??'')==='HEALTHY'));
        usort($routes,static fn($a,$b)=>strcmp($a['hostname'],$b['hostname']));
        // Generation depends on stable authorized identity, not changing observation timestamps.
        $identities=array_map(static fn($r)=>array_intersect_key($r,array_flip(['hostname','application_id','service','release_id','assignment_id','binding'])),$routes);
        $marker=hash('sha256',json_encode($identities,JSON_THROW_ON_ERROR));
        $config=$this->renderer->render($routes,$marker);$hash=hash('sha256',$config);
        $old=$this->state();
        if ($old===[]&&is_file($this->managedFile)) throw new \RuntimeException('Existing managed file requires provenance reconciliation');
        $effective=$old['effective']??null;
        $previousRoutes=$effective['routes']??[];
        $possiblyExposed=$previousRoutes;
        if (($old['status']??'')!=='CONFIRMED') $possiblyExposed=array_merge($possiblyExposed,$old['attempted_routes']??[],$old['possibly_exposed_routes']??[]);
        // Include all known hosts in denial probes, including pending generations after a crash.
        $known=array_unique(array_merge(array_column($allRoutes,'hostname'),array_column($previousRoutes,'hostname'),$old['known_hosts']??[]));
        $withdrawn=array_values(array_diff($known,array_column($routes,'hostname')));
        $safeRollback=$effective!==null;
        foreach($previousRoutes as $prior){
            $matching=array_values(array_filter($routes,static fn($r)=>$r['hostname']===$prior['hostname']&&($r['assignment_id']??null)===($prior['assignment_id']??null)&&$r['binding']===$prior['binding']));
            if(count($matching)!==1)$safeRollback=false;
        }
        $securityWithdrawal=false;
        foreach($possiblyExposed as $prior){
            $stillApproved=array_filter($routes,static fn($r)=>$r['hostname']===$prior['hostname']&&($r['assignment_id']??null)===($prior['assignment_id']??null)&&$r['binding']===$prior['binding']);
            if($stillApproved===[])$securityWithdrawal=true;
        }
        if($securityWithdrawal)$safeRollback=false;
        $exposures=[];
        foreach(array_merge($possiblyExposed,$routes)as$exposed){$v=array_intersect_key($exposed,array_flip(['hostname','assignment_id','binding']));$exposures[hash('sha256',json_encode($v,JSON_THROW_ON_ERROR))]=$v;}
        $journal=['possibly_exposed_routes'=>array_values($exposures),'status'=>'APPLY_PENDING','generation'=>$hash,'marker'=>$marker,'effective'=>$effective,'attempted_routes'=>$routes,'known_hosts'=>array_values($known),'security_withdrawal'=>$securityWithdrawal,'disk_before'=>is_file($this->managedFile)?hash_file('sha256',$this->managedFile):null,'confirmed'=>false];
        $activated=false;
        try {
            if(($old['status']??'')==='CONFIRMED'&&($old['generation']??'')===$hash&&is_file($this->managedFile)&&hash_file('sha256',$this->managedFile)===$hash){
                $evidence=($this->probe)($routes,$withdrawn,$marker);
                if(($evidence['ok']??false)===true){$old['probe']=$evidence;$old['idempotent']=true;$this->persist($old);return $old;}
            }
            $this->persist($journal);
            $candidate=$this->directory.'/candidate-'.$hash.'.conf';$this->atomic($candidate,$config);
            if(($this->validateCandidate)($candidate)!==true)throw new \RuntimeException('Candidate Apache configuration invalid');
            $this->atomic($this->managedFile,$config);$activated=true;
            $journal['status']='ACTIVATED_UNCONFIRMED';$this->persist($journal);$this->point('activated');
            if(($this->validateActive)()!==true)throw new \RuntimeException('Active Apache syntax invalid');
            if(($this->reload)()!==true)throw new \RuntimeException('Apache reload not confirmed');
            $journal['status']='RELOADED_UNCONFIRMED';$this->persist($journal);$this->point('reloaded');
            $evidence=($this->probe)($routes,$withdrawn,$marker);
            if(($evidence['ok']??false)!==true)throw new \RuntimeException('Effective Apache probes failed');
            $journal['status']='CONFIRMED';$journal['confirmed']=true;$journal['probe']=$evidence;
            $journal['effective']=['generation'=>$hash,'marker'=>$marker,'routes'=>$routes,'configuration'=>$config];
            $journal['idempotent']=false;$this->persist($journal);return $journal;
        }catch(\Throwable $e){
            $journal['error']=$e->getMessage();$journal['confirmed']=false;
            $journal['status']=$securityWithdrawal?'SECURITY_WITHDRAWAL_UNCONFIRMED':($activated?'APPLY_UNCERTAIN':'REJECTED');
            // Never restore a route which was removed by current authorization/health policy.
            if($activated&&$safeRollback){
                try{
                    $this->atomic($this->managedFile,$effective['configuration']);
                    if(($this->validateActive)()!==true||($this->reload)()!==true)throw new \RuntimeException('Rollback reload failed');
                    $proof=($this->probe)($previousRoutes,array_values(array_diff($known,array_column($previousRoutes,'hostname'))),$effective['marker']);
                    if(($proof['ok']??false)!==true)throw new \RuntimeException('Rollback probes failed');
                    $journal['status']='ROLLED_BACK_VERIFIED';$journal['rollback_probe']=$proof;
                }catch(\Throwable $rollback){$journal['rollback_error']=$rollback->getMessage();}
            }
            $journal['disk_after']=is_file($this->managedFile)?hash_file('sha256',$this->managedFile):null;
            try{$journal['observed_after_failure']=($this->probe)($routes,$withdrawn,$marker);}catch(\Throwable$probe){$journal['probe_error']=$probe->getMessage();}
            $this->persist($journal);return $journal;
        }
    }
    private function point(string $point):void {if($this->checkpoint!==null)($this->checkpoint)($point);}
    private function persist(array $state):void {
        $state['recorded_at']=time();$this->atomic($this->directory.'/apply.json',json_encode($state,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
        $file=fopen($this->directory.'/events.jsonl','ab');if($file===false)throw new \RuntimeException('Journal unavailable');
        try{$data=json_encode($state,JSON_THROW_ON_ERROR)."\n";if(fwrite($file,$data)!==strlen($data)||!fflush($file)||!fsync($file))throw new \RuntimeException('Journal sync failed');}finally{fclose($file);}
    }
    private function atomic(string $path,string $bytes):void {
        if(is_link($path))throw new \RuntimeException('Refusing symlink target');
        $tmp=dirname($path).'/.gw-'.bin2hex(random_bytes(8)).'.tmp';$f=fopen($tmp,'xb');
        if($f===false)throw new \RuntimeException('Cannot stage configuration');
        try{chmod($tmp,0600);if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!fsync($f))throw new \RuntimeException('Configuration sync failed');}finally{fclose($f);}
        if(!rename($tmp,$path)){unlink($tmp);throw new \RuntimeException('Atomic activation failed');}
        $dir=fopen(dirname($path),'r');if($dir===false)throw new \RuntimeException('Directory sync unavailable');
        try{if(!fsync($dir))throw new \RuntimeException('Directory sync failed');}finally{fclose($dir);}
    }
}
