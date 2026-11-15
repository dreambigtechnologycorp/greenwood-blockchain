<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
use Greenwood\Provider\MeteringService;
$dir=sys_get_temp_dir().'/greenwood-metering-'.bin2hex(random_bytes(8));$commit=str_repeat('a',64);
$sample=fn($runtime,$cpu)=>['version'=>1,'verification_level'=>'C','source'=>'local_cgroup_v2','runtime_identity'=>str_repeat($runtime,64),'cpu_ms'=>(string)$cpu,'memory_peak_bytes'=>'1048576','memory_current_bytes'=>'0','io_devices'=>null,'io_attribution'=>'cgroup_only'];
try{
 $service=new MeteringService($dir);$service->begin($commit,$sample('1',10));rejects(fn()=>$service->bundle($commit),'Open segment after collector crash is not zero consumption');unset($service);
 $restart=new MeteringService($dir);rejects(fn()=>$restart->begin($commit,$sample('1',20)),'Restart does not reset the same billing segment');rejects(fn()=>$restart->finish($commit,$sample('2',50)),'Changed runtime identity cannot close old counters');rejects(fn()=>$restart->finish($commit,$sample('1',5)),'Counter reset cannot manufacture negative usage');
 $restart->finish($commit,$sample('1',760));$first=$restart->bundle($commit);check($first['cpu_ms']==='750'&&!$first['subsidy_eligible'],'Actual counter difference remains provider-side level C');rejects(fn()=>$restart->finish($commit,$sample('1',800)),'Finished segment cannot be billed twice');
 $restart->begin($commit,$sample('2',0));$restart->finish($commit,$sample('2',250));$combined=$restart->bundle($commit);check($combined['cpu_ms']==='1000'&&count($combined['bundle']['segments'])===2,'Application restart retains commitment and distinct segment lineage');
 $other=str_repeat('b',64);rejects(fn()=>$restart->begin($other,$sample('1',100)),'A different tenant cannot rebill an old runtime counter range');$restart->begin($other,$sample('1',760));$restart->finish($other,$sample('1',800));check($restart->bundle($other)['cpu_ms']==='40','Adjacent interval charges only fresh counter delta');$unseen=str_repeat('c',64);rejects(fn()=>$restart->bundle($unseen),'Another tenant cannot inherit observations');$bad=$sample('3',0);$bad['verification_level']='A';rejects(fn()=>$restart->begin($commit,$bad),'A second local process cannot claim independent qualification');
 echo json_encode(['status'=>'PASS','checks'=>$checks,'independent_measurement_qualified'=>false,'container_execution_tested'=>false],JSON_PRETTY_PRINT)."\n";
}finally{unset($service,$restart);if(is_dir($dir)){foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}}
