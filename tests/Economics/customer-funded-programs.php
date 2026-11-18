<?php
declare(strict_types=1);
// Reuse keyless policy, assertions and direct Ledger governance helpers only.
require __DIR__.'/zero-reserve-budgets.php';
use Greenwood\Economics\{CanonicalJson,Ledger,Policy,ServiceSettlement};
use Greenwood\Resources\ResourceCatalog;
use Greenwood\State\State;
use Greenwood\Transaction\Envelope;
$checks=0;
$customer=$program;$customer['subsidy_percent']=0;
$customer['budget_units']='0';$customer['beneficiary_budget_units']='0';
$legacy=$p;unset($legacy['service_trust_policy']);
check(ServiceSettlement::validateProgram($customer,$legacy)===$customer,'Legacy customer-only program accepts zero budgets');
$entries=[];
foreach(['compute.cpu.consumed','memory.ram.reserved'] as $resource){
    $definition=ResourceCatalog::definition($resource);
    $entries[]=['resource_id'=>$resource,'unit'=>$definition['unit'],'measurement_type'=>$definition['measurement_type'],
        'meter_version'=>$definition['meter_version'],'enabled'=>true,'verification_level'=>'B','qualification_hash'=>identity(40)];
}
$customer['resource_catalog']=['version'=>2,'entries'=>$entries];
$customer+=['trust_profile'=>'BOOTSTRAP_OPERATOR_ATTESTED','evidence_profile'=>identity(41),
    'provider_operator'=>identity(42),'meter_operator'=>identity(42)];
$customer['attestors']=[$customer['provider_key']];$customer['threshold']=1;
$customer['attestor_operators']=[$customer['provider_key']=>identity(42)];
foreach(['compute_resources_v2','hosted_resources_v2'] as $adapter){
    $customer['adapter']=$adapter;
    if($adapter==='hosted_resources_v2')$customer+=['registry_contract_id'=>identity(43),'registry_code_hash'=>identity(44)];
    $customer['catalog_hash']=ServiceSettlement::catalogHash($customer);
    check(ServiceSettlement::validateProgram($customer,$p)===$customer,'Production '.$adapter.' permits customer funding');
    $old=$customer;$old['budget_units']='100';$old['beneficiary_budget_units']='50';
    check(ServiceSettlement::validateProgram($old,$p)===$old,'Existing positive unused subsidy budgets remain compatible');
    foreach(['budget_units','beneficiary_budget_units'] as $field){
        foreach([null,0,'-1','00','0.0'] as $bad){$q=$customer;$q[$field]=$bad;rejects(fn()=>ServiceSettlement::validateProgram($q,$p),'base units');}
        $q=$customer;unset($q[$field]);rejects(fn()=>ServiceSettlement::validateProgram($q,$p),'base units');
    }
    $q=$customer;$q['max_gru_units']='0';rejects(fn()=>ServiceSettlement::validateProgram($q,$p),'program budget');
    $q=$customer;$q['beneficiary_budget_units']='1';rejects(fn()=>ServiceSettlement::validateProgram($q,$p),'subsidy ceiling');
    $q=$customer;$q['subsidy_percent']=1;rejects(fn()=>ServiceSettlement::validateProgram($q,$p),'Bootstrap operator services cannot draw subsidies');
    $q=$customer;$q['trust_profile']='INDEPENDENT_ATTESTED';$q['attestors']=[identity(33),identity(34)];$q['threshold']=2;
    $q['attestor_operators']=[identity(33)=>identity(45),identity(34)=>identity(46)];
    check(ServiceSettlement::validateProgram($q,$p)===$q,'Independent customer-only profile still valid');
    $q['subsidy_percent']=1;rejects(fn()=>ServiceSettlement::validateProgram($q,$p),'program budget');
    $q['budget_units']='1';$q['beneficiary_budget_units']='1';rejects(fn()=>ServiceSettlement::validateProgram($q,$p),'subsidy ceiling');
    $open=$p;$open['limits']['subsidies']='1';
    check(ServiceSettlement::validateProgram($q,$open)===$q,'Positive subsidy program requires positive authorized ceiling');
}
// No automatic program approval: exercise the existing policy threshold and delay.
$ledger=new Ledger($p,'zero-budget-unit-test',hex2bin(identity(20)),array_map(fn($n)=>hex2bin(identity($n)),[21,22,23]));
$state=new State();$state->restoreDerivedEconomics($ledger->snapshot());
action($ledger,$state,['action'=>'propose','kind'=>'service_program','reserve'=>'subsidies','nonce'=>0,'expires'=>60000,'program'=>$customer],1);
$id=array_key_last($ledger->snapshot()['proposals']);
foreach([1,2,3] as $signer)action($ledger,$state,['action'=>'approve','proposal'=>$id],1,$signer);
rejects(fn()=>action($ledger,$state,['action'=>'execute','proposal'=>$id],20161),'threshold/timelock');
action($ledger,$state,['action'=>'approve','proposal'=>$id],1,4);
rejects(fn()=>action($ledger,$state,['action'=>'execute','proposal'=>$id],20160),'threshold/timelock');
action($ledger,$state,['action'=>'execute','proposal'=>$id],20161);
check(isset($ledger->snapshot()['services']['programs'][$customer['id']]),'Customer program installed with zero reserve budget');
$ledger->assertSupply($state);
$before=$ledger->snapshot();$ledger->lockSubsidy(0,20162);
check($ledger->snapshot()===$before,'Zero reserve reservation is a no-op');
rejects(fn()=>$ledger->lockSubsidy(1,20162),'budget');
rejects(fn()=>$ledger->lockSubsidy(-1,20162),'Invalid subsidy amount');
// Reserve pause, historical spend and outstanding subsidies cannot tax customer-only funding.
$snapshot=$ledger->snapshot();$snapshot['paused']['subsidies']=100000;
$snapshot['spent'][]=['reserve'=>'subsidies','group'=>'project','height'=>20161,'amount'=>1];
$snapshot['services']['commitments'][identity(50)]=['status'=>'funded','subsidy_locked'=>1];
$busy=Ledger::restore($snapshot);$before=$busy->snapshot();$busy->lockSubsidy(0,20162);
check($busy->snapshot()===$before,'Customer-only reservation unaffected by paused or committed reserve');
rejects(fn()=>$busy->lockSubsidy(1,20162),'Subsidy paused');

// Settlement boundary tests restore explicit funded/verified fixtures; they do not
// simulate signature verification, quote acceptance or hardware attestation.
foreach(['settle','refund'] as $operation){
    $snapshot=$ledger->snapshot();$cid=identity(51);$pid=$customer['id'];
    $snapshot['balances']['grants']-=1000; // Historical customer distribution, now escrowed.
    $snapshot['services']['programs'][$pid]['gru_committed']=10;
    $snapshot['services']['programs'][$pid]['beneficiaries']['project']=0;
    $snapshot['services']['commitments'][$cid]=['id'=>$cid,'program'=>$pid,'payer'=>identity(8),'provider'=>identity(52),
        'group'=>'project','status'=>$operation==='settle'?'verified':'funded','payer_locked'=>1000,'subsidy_locked'=>0,
        'max_gru'=>10,'end_height'=>20163,'evidence_deadline'=>20164,
        'claim'=>['ready'=>20165,'gru'=>6,'cost'=>['payer_units'=>600,'provider_units'=>600,'subsidy_units'=>0]]];
    $test=Ledger::restore($snapshot);$accounts=new State();$accounts->restoreDerivedEconomics($snapshot);
    $before=$test->snapshot()['balances'];
    $tx=new Envelope(1,'zero-budget-unit-test',hex2bin(identity(8)),0,Envelope::TYPE_SERVICE,
        CanonicalJson::encode(['action'=>$operation,'commitment'=>$cid,'version'=>1,'genesis'=>$snapshot['genesis']]),'',hex2bin(identity(1)));
    if($operation==='settle')rejects(fn()=>$test->applyService($accounts,$tx,20164),'challenge delay');
    else rejects(fn()=>$test->applyService($accounts,$tx,20164),'Refund not eligible');
    $test->applyService($accounts,$tx,20165);$test->assertSupply($accounts);
    check($test->snapshot()['balances']===$before,'Customer '.$operation.' leaves all reserve balances unchanged');
    check($accounts->getAccount(hex2bin(identity(8)))->getBalance()===($operation==='settle'?400:1000),'Customer refund conserved');
    check($accounts->getAccount(hex2bin(identity(52)))->getBalance()===($operation==='settle'?600:0),'Provider paid only from customer escrow');
    check($test->snapshot()['services']['commitments'][$cid]['payment']['subsidy_units']===0,'No subsidy paid');
    rejects(fn()=>$test->applyService($accounts,$tx,20166),'terminal service commitment');
}
echo "customer-funded-programs: $checks checks passed (keyless boundary tests)\n";
