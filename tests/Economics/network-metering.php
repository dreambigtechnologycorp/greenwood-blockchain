<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
use Greenwood\Resources\Metering\NetworkAccounting;
$first=['version'=>1,'verification_level'=>'C','source'=>'owned_host_veth_v1','network_identity'=>str_repeat('a',64),'ingress_bytes'=>'100','egress_bytes'=>'200','internet_only'=>false,'payable'=>false];
$last=$first;$last['ingress_bytes']='130';$last['egress_bytes']='250';
check(NetworkAccounting::delta($first,$last)===['ingress_bytes'=>'30','egress_bytes'=>'50'],'Separate ingress and egress counters use exact deltas');
$bad=$last;$bad['network_identity']=str_repeat('b',64);rejects(fn()=>NetworkAccounting::delta($first,$bad),'Replaced interface cannot inherit counter lineage');
$bad=$last;$bad['egress_bytes']='199';rejects(fn()=>NetworkAccounting::delta($first,$bad),'Counter reset/wrap cannot manufacture a billable delta');
$bad=$last;$bad['verification_level']='A';rejects(fn()=>NetworkAccounting::delta($first,$bad),'Host counters cannot claim independent verification');
$bad=$last;$bad['payable']=true;rejects(fn()=>NetworkAccounting::delta($first,$bad),'Local network collector cannot grant payment authority');
$runtime=['application_id'=>1,'service'=>'web','state'=>'RUNNING','pid'=>getmypid(),'image_digest'=>'sha256:'.str_repeat('f',64)];
$binding=['application_id'=>1,'service'=>'web','state'=>'HEALTHY','ready'=>true,'pid'=>getmypid(),'image_digest'=>$runtime['image_digest'],'host_veth'=>'eth0'];
rejects(fn()=>NetworkAccounting::observe($binding,$runtime),'Host background interface rejected before counter read');
$binding['host_veth']='gwa'.substr(hash('sha256',"1\0web"),0,10);$binding['application_id']=2;rejects(fn()=>NetworkAccounting::observe($binding,$runtime),'Foreign tenant network binding rejected');
rejects(fn()=>\Greenwood\Provider\ProviderApplicationLinuxNetwork::namespaceIdentity(getmypid()),'Host shared namespace cannot masquerade as isolated workload network');
 $binding['application_id']=1;$binding['namespace_identity']='fixture';$rejectedForNamespace=false;
 try{NetworkAccounting::observe($binding,$runtime);}catch(RuntimeException $e){$rejectedForNamespace=str_contains($e->getMessage(),'isolated live network namespace');}
 check($rejectedForNamespace,'Valid integer application identity reaches actual isolated-namespace validation before network accounting');
echo json_encode(['status'=>'PASS','checks'=>$checks,'actual_workload_veth_tested'=>false,'physical_qualification'=>false,'counter_inputs'=>'explicit_fixture_values'],JSON_PRETTY_PRINT)."\n";
