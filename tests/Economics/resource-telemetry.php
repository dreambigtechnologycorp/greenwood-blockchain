<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
use Greenwood\Resources\{ResourceCatalog,ResourceManifest};
use Greenwood\Resources\Metering\{TelemetryFrame,AllocationAccounting,LinuxCollectors};
use Greenwood\Provider\{ResourceTelemetryJournal,ServiceOperationMeter};
use Greenwood\Economics\CanonicalJson;
use Greenwood\Crypto\Ed25519KeyPair;
$dir=sys_get_temp_dir().'/greenwood-universal-telemetry-'.bin2hex(random_bytes(8));$key=Ed25519KeyPair::generate();$id=str_repeat('a',64);$runtime=str_repeat('b',64);$incarnation=str_repeat('c',64);
$manifest=['version'=>1,'rounding_policy'=>'ceil_line_gru_then_ceil_gwd_v1','duration_ms'=>'1000','lines'=>[]];$i=0;
foreach(ResourceCatalog::definitions() as $resource=>$definition){$manifest['lines'][]=['line_id'=>'line'.str_pad((string)$i++,2,'0',STR_PAD_LEFT),'resource_id'=>$resource,'asset_id'=>str_repeat('d',64),'meter_version'=>$definition['meter_version'],'reserved_units'=>'2','maximum_quantity'=>'2000','rate_num'=>'1','rate_den'=>'1','included_quantity'=>'0','overage_of'=>null];}
// Transport limit is 32 lines per commitment; exercise every resource in bounded groups.
$groups=array_chunk($manifest['lines'],16);$scope=['chain'=>'telemetry-fixture','genesis'=>str_repeat('1',64),'provider_id'=>str_repeat('2',64),'workload_id'=>str_repeat('3',64),'assignment_generation'=>str_repeat('4',64),'commitment'=>$id,'interval_start_height'=>1,'interval_end_height'=>100,'catalog_hash'=>str_repeat('5',64),'manifest_hash'=>''];
try{
 foreach($groups as $index=>$lines){$m=$manifest;$m['lines']=$lines;$s=$scope;$s['commitment']=hash('sha256','group'.$index);$s['manifest_hash']=ResourceManifest::hash($m);$journal=new ResourceTelemetryJournal($dir.'/group'.$index);$journal->bind($s,$m,bin2hex($key->getPublicKey()),500);
 $frame=static function($seq,$time,$counter=0)use($s,$lines,$runtime,$incarnation){return ['version'=>1,'scope_hash'=>hash('sha256',CanonicalJson::encode($s)),'runtime_identity'=>$runtime,'source_incarnation'=>$incarnation,'sequence'=>$seq,'monotonic_ms'=>(string)$time,'measurements'=>array_map(fn($line)=>['line_id'=>$line['line_id'],'asset_id'=>$line['asset_id'],'value'=>ResourceCatalog::definition($line['resource_id'])['measurement_type']==='reserved'?'2':(string)$counter,'available'=>true],$lines)];};
 $first=TelemetryFrame::sign($frame(0,0),$key);$journal->append($s['commitment'],$first);
 rejects(fn()=>$journal->append($s['commitment'],$first),'Duplicate source sequence cannot bill twice');
 $bad=TelemetryFrame::sign($frame(1,500,5),Ed25519KeyPair::generate());rejects(fn()=>$journal->append($s['commitment'],$bad),'Wrong source key denied');
 rejects(fn()=>$journal->append($s['commitment'],TelemetryFrame::sign($frame(1,501,5),$key)),'Source sampling gap fails closed');
 $bad=$frame(1,500,5);$bad['runtime_identity']=str_repeat('0',64);rejects(fn()=>$journal->append($s['commitment'],TelemetryFrame::sign($bad,$key)),'Runtime replacement cannot continue interval');
 $journal->append($s['commitment'],TelemetryFrame::sign($frame(1,500,5),$key));$last=$journal->append($s['commitment'],TelemetryFrame::sign($frame(2,1000,10),$key),true);$e=$journal->evidence($s['commitment']);
 foreach($lines as $j=>$line)check($e['measurements'][$j]['quantity']===(ResourceCatalog::definition($line['resource_id'])['measurement_type']==='reserved'?'2000':'10'),'Typed reservation/consumption accounting for '.$line['resource_id']);
 $archive=$journal->archiveClosed($s['commitment'],$last['head']);unset($journal);$journal=new ResourceTelemetryJournal($dir.'/group'.$index);check(CanonicalJson::encode($journal->evidence($s['commitment']))===CanonicalJson::encode($e),'All-family evidence survives archive/restart');
 rejects(fn()=>$journal->bind($s,$m,bin2hex($key->getPublicKey()),500),'Archived interval cannot be rebound');
 file_put_contents($dir.'/group'.$index.'/archive/'.$last['head'].'.json','corrupt');rejects(fn()=>$journal->evidence($s['commitment']),'Signed telemetry provenance cannot disappear silently');
 }
 $m=$manifest;$m['lines']=array_values(array_filter($manifest['lines'],fn($l)=>str_starts_with($l['resource_id'],'storage.object.')));$s=$scope;$s['manifest_hash']=ResourceManifest::hash($m);$meter=new ServiceOperationMeter($dir.'/operations',$s);
 $event=str_repeat('e',64);check($meter->begin($event)==='NEW','Operation intent persists before service I/O');rejects(fn()=>$meter->snapshot($m),'Unresolved operation cannot become zero usage');
 $quantities=[['asset_id'=>str_repeat('d',64),'resource_id'=>'storage.object.egress','quantity'=>'512'],['asset_id'=>str_repeat('d',64),'resource_id'=>'storage.object.requests','quantity'=>'1']];$digest=$meter->complete($event,$quantities);check($meter->complete($event,$quantities)===$digest,'Idempotent completion never increments counters twice');
 $snap=$meter->snapshot($m);check(!$snap['payable']&&in_array('512',array_column($snap['measurements'],'value'),true),'Service adapter retains attributed actual receipt counters');
 $bad=$quantities;$bad[0]['quantity']='513';rejects(fn()=>$meter->complete($event,$bad),'Conflicting repeated service receipt denied');
 $linux=LinuxCollectors::measurements($m,[]);check(count(array_filter($linux,fn($r)=>$r['available']))===0,'Missing hardware/service adapters are unavailable, not payable zero');
 $reserved=array_values(array_filter($manifest['lines'],fn($l)=>ResourceCatalog::definition($l['resource_id'])['measurement_type']==='reserved'));$m['lines']=$reserved;$leases=[];
 foreach($reserved as $line)$leases[]=['asset_id'=>$line['asset_id'],'resource_id'=>$line['resource_id'],'units'=>'2','workload_id'=>$s['workload_id'],'assignment_generation'=>$s['assignment_generation'],'runtime_identity'=>$runtime,'start_ms'=>'0','end_ms'=>'1000'];
 $rt=['runtime_identity'=>$runtime,'workload_id'=>$s['workload_id'],'assignment_generation'=>$s['assignment_generation'],'ready'=>true];$alloc=AllocationAccounting::measurements($m,$s,$leases,$rt,500);check(count(array_filter($alloc,fn($v)=>$v['available']))===count($reserved),'Allocator adapter covers GPU/NPU/VRAM/storage/bandwidth/IP/node/backup reservations');
 $rt['ready']=false;$alloc=AllocationAccounting::measurements($m,$s,$leases,$rt,500);check(count(array_filter($alloc,fn($v)=>$v['available']))===0,'Unavailable runtime cannot claim reserved availability');
 echo json_encode(['status'=>'PASS','checks'=>$checks,'resource_definitions'=>count(ResourceCatalog::definitions()),'hardware_qualification'=>false,'activation'=>false],JSON_PRETTY_PRINT)."\n";
}finally{unset($journal,$meter);if(is_dir($dir)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $p){if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir($dir);}}
