<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
use Greenwood\Provider\{MeteringService,JournalArchive};
$dir=sys_get_temp_dir().'/greenwood-retention-'.bin2hex(random_bytes(8));$id=str_repeat('a',64);$runtime=str_repeat('b',64);
$sample=fn($n)=>['version'=>1,'source'=>'local_cgroup_v2','verification_level'=>'C','runtime_identity'=>$runtime,'cpu_ms'=>(string)$n,'monotonic_ms'=>(string)($n+1000)];
try{
 $meter=new MeteringService($dir);$meter->begin($id,$sample(0));rejects(fn()=>$meter->archiveClosed($id,str_repeat('0',64)),'No archival of open observations');
 for($i=1;$i<=1100;$i++)$meter->checkpoint($id,$sample($i));$meter->finish($id,$sample(1101));
 $bundle=$meter->bundle($id);check($bundle['cpu_ms']==='1101'&&$bundle['bundle']['segments'][0]['checkpoint_count']===1101,'Automatic checkpoint rollover preserves full counter and hash lineage');
 $db=new PDO('sqlite:'.$dir.'/meter-segments.sqlite');check((int)$db->query('SELECT COUNT(*) FROM checkpoints')->fetchColumn()<512,'Hot checkpoint storage stays bounded across rollover');
 rejects(fn()=>$meter->archiveClosed($id,str_repeat('0',64)),'Archive requires exact reviewed bundle');
 $hash=$meter->archiveClosed($id,$bundle['evidence_bundle_hash']);check($meter->archiveClosed($id,$bundle['evidence_bundle_hash'])===$hash,'Closed archive operation is idempotent');
 check((int)$db->query('SELECT COUNT(*) FROM segments')->fetchColumn()===0,'Closed rows leave the active capacity pool');unset($meter,$db);
 $meter=new MeteringService($dir);check(\Greenwood\Economics\CanonicalJson::encode($meter->bundle($id))===\Greenwood\Economics\CanonicalJson::encode($bundle),'Archived evidence survives restart unchanged');
 rejects(fn()=>$meter->begin($id,$sample(1102)),'Archived commitment cannot reopen');
 rejects(fn()=>$meter->begin(str_repeat('c',64),$sample(1000)),'Archived runtime counters cannot be billed again');
 $meter->begin(str_repeat('c',64),$sample(1101));$meter->finish(str_repeat('c',64),$sample(1105));check($meter->bundle(str_repeat('c',64))['cpu_ms']==='4','New interval can continue at the archived high-water mark');
 $file=$dir.'/archive/'.$hash.'.json';file_put_contents($file,'corrupt');rejects(fn()=>$meter->bundle($id),'Missing or corrupt retained provenance fails closed');
 echo json_encode(['status'=>'PASS','checks'=>$checks,'actual_sqlite_rollover'=>true],JSON_PRETTY_PRINT)."\n";
}finally{unset($meter,$db);if(is_dir($dir)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $p){if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir($dir);}}
