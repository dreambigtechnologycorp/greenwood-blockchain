<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
use Greenwood\Network\InfrastructureCostModel;
$p=['version'=>1,'unit'=>'GWD_BASE_UNITS_PER_FINALIZED_BLOCK','window_blocks'=>'43200','max_operators'=>'3','max_attestations'=>'10000','base_units_per_block'=>'2','units_per_attestation'=>'7','window_cap_units'=>'400000'];
$result=InfrastructureCostModel::project($p,43200,10000,3);check($result['maximum_gwd_units']==='329200'&&!$result['payable']&&!$result['issuance_change'],'Availability and activity fees bounded in existing GWD with no autonomous invoice');
rejects(fn()=>InfrastructureCostModel::project($p,43201,0,1),'Availability cannot exceed declared window');
rejects(fn()=>InfrastructureCostModel::project($p,1,0,4),'Additional keys cannot expand approved operator budget');
rejects(fn()=>InfrastructureCostModel::project($p,1,10001,1),'Attestation frequency bounded');
$bad=$p;$bad['window_cap_units']='1';rejects(fn()=>InfrastructureCostModel::project($bad,1,1,1),'Budget exposure must be funded before approval');
$bad=$p;$bad['base_units_per_block']=(string)PHP_INT_MAX;rejects(fn()=>InfrastructureCostModel::project($bad,2,0,1),'Overflow rejected');
check(InfrastructureCostModel::project($p,0,0,0)['maximum_gwd_units']==='0','No qualified work does not manufacture compensation');
echo json_encode(['status'=>'PASS','checks'=>$checks,'policy_ratified'=>false,'automatic_payments'=>false],JSON_PRETTY_PRINT)."\n";
