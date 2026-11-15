<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
use Greenwood\Resources\{ResourceCatalog,ProviderCapabilityV2,ResourceManifest};
use Greenwood\Economics\{CanonicalJson,ServiceAuthorization};
use Greenwood\Compute\Protocol\{ProviderCapability,ProviderId};
use Greenwood\Transaction\Envelope;
function resourceCatalog(array $ids=['compute.cpu.consumed','memory.ram.reserved']): array {
 sort($ids,SORT_STRING);$entries=[];foreach($ids as $id){$d=ResourceCatalog::definition($id);$entries[]=['resource_id'=>$id,'unit'=>$d['unit'],'measurement_type'=>$d['measurement_type'],'meter_version'=>$d['meter_version'],'enabled'=>true,'verification_level'=>'B','qualification_hash'=>str_repeat('d',64)];}return ['version'=>2,'entries'=>$entries];
}
function resourceCapability($provider,string $genesis): array {
 $asset=function($id,$group,$capacity,$resources,$profiles,$class){sort($resources,SORT_STRING);sort($profiles,SORT_STRING);return ['asset_id'=>str_repeat($id,64),'billing_group'=>str_repeat(in_array($id,['1','2','6'],true)?'f':$id,64),'exclusivity_group'=>str_repeat($group,64),'group_capacity'=>(string)$capacity,'capacity'=>(string)$capacity,'capacity_class'=>$class,'vendor'=>'LOCAL_FIXTURE','model'=>'LOCAL_FIXTURE_ONLY','runtime'=>'fixture-runtime','partition_id'=>'whole','attributes'=>$id==='3'?['storage'=>['medium'=>'ssd','persistence'=>'persistent','durability'=>'single_copy','protocols'=>['nfs4','posix']]]:(in_array($id,['4','5'],true)?['accelerator'=>['memory_model'=>'device_local','memory_mib'=>'8192','partitioning'=>'exclusive']]:[]),'resources'=>$resources,'meter_versions'=>$profiles];};
 return ['version'=>2,'genesis'=>$genesis,'provider_key'=>bin2hex($provider->getPublicKey()),'provider_id'=>bin2hex(ProviderId::fromPublicKey($provider->getPublicKey())->toBytes()),'base_nonce'=>1,'nonce'=>1,'region'=>'local-fixture',
 'assets'=>[$asset('1','a',4000,['compute.cpu.consumed','compute.cpu.reserved'],['allocation.interval.v1','cgroup.cpu.v1'],'cpu-fixture'),$asset('2','b',8192,['memory.ram.reserved'],['allocation.interval.v1'],'ram-fixture'),$asset('3','c',100000,['storage.nas.capacity','storage.ssd.capacity','storage.ssd.read','storage.ssd.write'],['allocation.interval.v1','cgroup.io.v1'],'storage-fixture'),$asset('4','d',1000,['compute.gpu.reserved'],['allocation.interval.v1'],'gpu-fixture'),$asset('5','e',1000,['compute.npu.reserved'],['allocation.interval.v1'],'npu-fixture'),$asset('6','f',1,['infrastructure.node.reserved'],['allocation.interval.v1'],'node-fixture')]];
}
function resourceLine(string $id,string $resource,string $asset,string $class,int $units,int $max,int $num=1,int $den=1,array $coverage=[],?string $overage=null,int $included=0): array {
 $d=ResourceCatalog::definition($resource);if(!$coverage)$coverage=[$d['domain']];sort($coverage,SORT_STRING);return ['line_id'=>$id,'resource_id'=>$resource,'asset_id'=>str_repeat($asset,64),'capacity_class'=>$class,'meter_version'=>$d['meter_version'],'reserved_units'=>(string)$units,'maximum_quantity'=>(string)$max,'rate_num'=>(string)$num,'rate_den'=>(string)$den,'coverage_domains'=>$coverage,'included_quantity'=>(string)$included,'overage_of'=>$overage];
}
function resourceManifest(int $duration=1000): array {return ['version'=>1,'rounding_policy'=>'ceil_line_gru_then_ceil_gwd_v1','duration_ms'=>(string)$duration,'lines'=>[resourceLine('cpu','compute.cpu.consumed','1','cpu-fixture',1000,1000),resourceLine('ram','memory.ram.reserved','2','ram-fixture',4,4*$duration,1,4)]];}
function resourceTx($s,$key,int $type,string $payload,string $chain): Envelope {$t=new Envelope(1,$chain,addr($key),$s->getAccount(addr($key))->getNonce(),$type,$payload,'',$key->getPublicKey());return $t->setSignature($key->sign($t->getSigningDigest()));}
function resourceFinance($s,$key,array $action,int $height,string $chain): void {$s->applyTransaction(ServiceAuthorization::transaction($chain,hex2bin($s->economicSummary()['genesis']),$s->getAccount(addr($key))->getNonce(),$action,$key),$chain,$height);}
function registerResources($s,$provider,array $cap,int $height,string $chain): void {
 $base=new ProviderCapability(hex2bin($cap['provider_id']),$provider->getPublicKey(),0,1,['linux/amd64','linux/arm64/v8'],4000,8192*1048576,100,10);
 $s->applyTransaction(resourceTx($s,$provider,10,$base->toBytes(),$chain),$chain,$height);$s->applyTransaction(resourceTx($s,$provider,14,CanonicalJson::encode($cap),$chain),$chain,$height);
}
