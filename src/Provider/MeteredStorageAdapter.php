<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Economics\{Amount,CanonicalJson,Policy};

/** Cooperating file-backed NAS/object/backup service adapter, not a public server.
 * The service authenticates its caller and supplies a previously funded scope.
 * Each instance has an exclusive private tenant root; no arbitrary path API.
 */
final class MeteredStorageAdapter
{
    private ServiceOperationMeter $meter;
    private string $identity;
    private string $scopeHash;
    public function __construct(private readonly string $root,string $journal,private readonly array $scope,private readonly string $asset,private readonly string $kind,private readonly int $quotaBytes,private readonly ?\Closure $authorize=null,private readonly array $meterAssets=[])
    {
        foreach($meterAssets as $resource=>$mapped){Policy::hex($mapped);if(!in_array($resource,['storage.nas.operations','storage.nas.transfer','storage.object.requests','storage.object.egress','backup.snapshot.restore'],true))throw new \InvalidArgumentException('Unsupported storage source mapping');}
        Policy::hex($asset);if(!in_array($kind,['nas','object','backup'],true)||$quotaBytes<1)throw new \InvalidArgumentException('Explicit storage kind and logical quota required');
        if(realpath($root)!==$root||!is_dir($root)||fileowner($root)!==posix_geteuid()||(fileperms($root)&0077))throw new \RuntimeException('Existing private tenant root required');
        $this->scopeHash=hash('sha256',CanonicalJson::encode($scope));$stat=stat($root);$this->identity=hash('sha256',$root.':'.$stat['dev'].':'.$stat['ino']);
        $this->meter=new ServiceOperationMeter($journal,$scope);
        $identity=['version'=>1,'workload_hash'=>hash('sha256',CanonicalJson::encode(array_intersect_key($scope,array_flip(['chain','genesis','provider_id','workload_id','assignment_generation'])))),'asset_id'=>$asset,'kind'=>$kind,'root_identity'=>$this->identity,'quota_bytes'=>(string)$quotaBytes,'meter_assets'=>$meterAssets,'capacity_basis'=>'logical_bytes','replicas'=>1,'compression'=>'none'];
        $path=$root.'/.greenwood-binding';
        if(is_link($path))throw new \RuntimeException('Unsafe tenant binding');
        if(!file_exists($path)){$f=@fopen($path,'x');if(!$f)throw new \RuntimeException('Tenant binding race; retry inspection');try{chmod($path,0600);$raw=CanonicalJson::encode($identity);if(fwrite($f,$raw)!==strlen($raw)||!fflush($f)||!fsync($f))throw new \RuntimeException('Tenant binding durability failure');}finally{fclose($f);}$this->syncDirectory();}
        if(!is_file($path)||fileowner($path)!==posix_geteuid()||(fileperms($path)&0077)||file_get_contents($path)!==CanonicalJson::encode($identity))throw new \RuntimeException('Tenant root scope or capacity policy changed');
    }
    private function syncDirectory(): void {$d=fopen($this->root,'r');if(!$d)throw new \RuntimeException('Storage directory unavailable');try{if(!fsync($d))throw new \RuntimeException('Storage directory sync failed');}finally{fclose($d);}}
    private function inspect(): array
    {
        clearstatcache();$stat=stat($this->root);if(realpath($this->root)!==$this->root||hash('sha256',$this->root.':'.$stat['dev'].':'.$stat['ino'])!==$this->identity)throw new \RuntimeException('Tenant volume replaced');
        $bytes=0;$count=0;
        foreach(new \DirectoryIterator($this->root) as $file){if($file->isDot()||in_array($file->getFilename(),['.greenwood-binding','.greenwood-lock'],true))continue;
            if(++$count>10000||!preg_match('/^[a-f0-9]{64}$/D',$file->getFilename())||$file->isLink()||!$file->isFile()||$file->getOwner()!==posix_geteuid()||($file->getPerms()&0077))throw new \RuntimeException('Unattributed storage entry or capacity scan limit');
            $st=lstat($file->getPathname());if($st['nlink']!==1)throw new \RuntimeException('Cross-root hard link denied');$bytes=Amount::add($bytes,$file->getSize());
        }
        if($bytes>$this->quotaBytes)throw new \RuntimeException('Logical storage quota exceeded');return ['bytes'=>$bytes,'objects'=>$count];
    }
    private function locked(\Closure $work): mixed
    {
        $path=$this->root.'/.greenwood-lock';if(is_link($path)||(file_exists($path)&&(!is_file($path)||fileowner($path)!==posix_geteuid()||(fileperms($path)&0077))))throw new \RuntimeException('Unsafe storage lock');
        $f=fopen($path,'c');if(!$f)throw new \RuntimeException('Storage lock unavailable');chmod($path,0600);
        try{if(!flock($f,LOCK_EX))throw new \RuntimeException('Storage lock failed');return $work();}finally{flock($f,LOCK_UN);fclose($f);}
    }
    /** Bounded actual I/O. Pending operations never retry a side effect automatically. */
    public function execute(string $event,string $operation,string $object,?string $bytes=null): array
    {
        if($this->authorize===null||($this->authorize)($this->scope)!==true)throw new \RuntimeException('Finalized funded storage authorization required');
        Policy::hex($event);Policy::hex($object);
        if(!in_array($operation,['put','get','delete','restore'],true)||($operation==='restore'&&$this->kind!=='backup')||(($operation==='put'||$operation==='restore')?($bytes===null||strlen($bytes)>16777216):$bytes!==null))throw new \InvalidArgumentException('Bounded exact storage operation required');
        // Bind idempotency key to all request semantics, including payload digest.
        $request=hash('sha256',CanonicalJson::encode(['event'=>$event,'operation'=>$operation,'object'=>$object,'content_hash'=>$bytes===null?null:hash('sha256',$bytes)]));
        return $this->locked(function()use($event,$operation,$object,$bytes,$request){
            if($this->authorize===null||($this->authorize)($this->scope)!==true)throw new \RuntimeException('Funding expired while waiting for storage lock');
            $usage=$this->inspect();if($bytes!==null&&$usage['objects']>=10000)throw new \RuntimeException('Storage object limit reached');$path=$this->root.'/'.$object;
            if(in_array($operation,['put','restore'],true)&&file_exists($path))throw new \RuntimeException('Immutable object already exists');
            if(in_array($operation,['get','delete'],true)&&!is_file($path))throw new \RuntimeException('Object unavailable');
            if($bytes!==null&&Amount::add($usage['bytes'],strlen($bytes))>$this->quotaBytes)throw new \RuntimeException('Storage reservation quota exceeded');
            $state=$this->meter->begin($event,$request);if($state!=='NEW')throw new \RuntimeException('Operation already recorded; inspect receipt instead of repeating I/O');
            $transferred=0;$data=null;
            if($bytes!==null){$f=fopen($path,'x');if(!$f)throw new \RuntimeException('Object creation failed');try{chmod($path,0600);$length=strlen($bytes);while($transferred<$length){$n=fwrite($f,substr($bytes,$transferred,1048576));if($n===false||$n===0)throw new \RuntimeException('Partial object write requires reconciliation');$transferred+=$n;}if(!fflush($f)||!fsync($f))throw new \RuntimeException('Object durability uncertain');}finally{fclose($f);}$this->syncDirectory();}
            elseif($operation==='get'){$data=file_get_contents($path,false,null,0,16777217);if($data===false||strlen($data)>16777216)throw new \RuntimeException('Read exceeds adapter response bound');$transferred=strlen($data);}
            else {if(!unlink($path))throw new \RuntimeException('Delete failed');$this->syncDirectory();}
            $quantities=match($this->kind){
                'nas'=>[['asset_id'=>$this->asset,'resource_id'=>'storage.nas.operations','quantity'=>'1'],['asset_id'=>$this->asset,'resource_id'=>'storage.nas.transfer','quantity'=>(string)$transferred]],
                'object'=>[['asset_id'=>$this->asset,'resource_id'=>'storage.object.egress','quantity'=>$operation==='get'?(string)$transferred:'0'],['asset_id'=>$this->asset,'resource_id'=>'storage.object.requests','quantity'=>'1']],
                'backup'=>$operation==='restore'?[['asset_id'=>$this->asset,'resource_id'=>'backup.snapshot.restore','quantity'=>'1']]:[],
            };
            foreach($quantities as &$quantity)$quantity['asset_id']=$this->meterAssets[$quantity['resource_id']]??$this->asset;unset($quantity);usort($quantities,fn($a,$b)=>strcmp($a['asset_id'].':'.$a['resource_id'],$b['asset_id'].':'.$b['resource_id']));
            $receipt=$this->meter->complete($event,$quantities);return ['receipt_hash'=>$receipt,'bytes'=>(string)$transferred,'data'=>$data,'payable'=>false,'verification_level'=>'C'];
        });
    }
    public function snapshot(array $manifest): array
    {
        return $this->locked(function()use($manifest){$usage=$this->inspect();$local=$manifest;$local['lines']=array_values(array_filter($manifest['lines'],fn($line)=>$line['asset_id']===($this->meterAssets[$line['resource_id']]??$this->asset)));$out=$this->meter->snapshot($local);
            $out['storage']=['asset_id'=>$this->asset,'root_identity'=>$this->identity,'logical_bytes'=>(string)$usage['bytes'],'quota_bytes'=>(string)$this->quotaBytes,'replicas'=>1,'compression'=>'none','retention'=>'until_explicit_delete'];
            // Capacity is the explicitly enforced reservation, not bytes compressed or replicated by backing media.
            $resource=$this->kind==='backup'?'backup.snapshot.capacity':'storage.'.$this->kind.'.capacity';
            foreach($manifest['lines'] as $line)if($line['asset_id']===$this->asset&&$line['resource_id']===$resource)$out['measurements'][]=['line_id'=>$line['line_id'],'asset_id'=>$this->asset,'value'=>(string)intdiv($this->quotaBytes,1048576),'available'=>true];
            return $out;
        });
    }
}
