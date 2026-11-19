<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Greenwood\Economics\{Amount,CanonicalJson,Ledger,Policy,ServiceSettlement};
use Greenwood\State\State;
use Greenwood\Transaction\Envelope;

// Ledger-level tests: fixed public identity placeholders, no private keys or signatures.
// Signature verification belongs to the enclosing transaction layer and is not exercised here.
$checks=0;
function check(bool $ok,string $label): void { global $checks; ++$checks; if(!$ok)throw new RuntimeException($label); }
function rejects(callable $fn,string $expected): void {
    try{$fn();}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),$expected),$e->getMessage());return;}
    throw new RuntimeException('Expected rejection: '.$expected);
}
function identity(int $n): string {return str_pad(dechex($n),64,'0',STR_PAD_LEFT);}
$p=['version'=>1,'profile'=>'production','block_seconds'=>60,'decimals'=>6,'burn_percent'=>0,'operator_percent'=>100,
    'signers'=>array_map('identity',range(1,5)),'guardians'=>[identity(6)],'pause_blocks'=>10,
    'allocations'=>Policy::PRODUCTION_ALLOCATION,
    'limits'=>array_fill_keys(['grants','subsidies','institute_treasury','working_operations'],'0'),
    'large_threshold'=>'100000000000','operations_balance'=>'100000000000','operations_payment'=>'5000000000','operations_window'=>'100000000000',
    'bootstrap_units'=>'0','operations_account'=>identity(7),'clock'=>'finalized_height','window_blocks'=>43200,
    'emission_distribution'=>'equal_genesis_validators','exhaustion'=>'partial_then_stop','failed_transaction_fee'=>'reject_without_charge',
    'fees'=>array_fill_keys(['transfer','governance','contract','compute'],'0'),
    'recipients'=>[identity(7)=>'operations',identity(8)=>'project'],
    'service_trust_policy'=>'explicit_profiles_v1','recovery_policy'=>['enabled'=>false]];
check(Policy::validateProduction($p)===$p,'Explicit zero budgets accepted');
foreach(array_keys($p['limits']) as $reserve){
    foreach([null,-1,'-1',0,0.0,'00','0.0',false,'9223372036854775808'] as $bad){
        $q=$p;$q['limits'][$reserve]=$bad;rejects(fn()=>Policy::validate($q),'base units');
    }
    $q=$p;unset($q['limits'][$reserve]);rejects(fn()=>Policy::validate($q),'base units');
    $q=$p;$q['limits'][$reserve]=(string)((int)$p['allocations'][$reserve]+1);rejects(fn()=>Policy::validate($q),'reserve spending limit');
    $q=$p;$q['limits'][$reserve]=$p['allocations'][$reserve];check(Policy::validate($q)===$q,'Allocation ceiling accepted');
}
foreach(['operations_balance','operations_payment','operations_window','large_threshold'] as $name){
    $q=$p;$q[$name]='0';rejects(fn()=>Policy::validate($q),'spending limit');
}
$q=$p;$q['bootstrap_units']='1';rejects(fn()=>Policy::validate($q),'bootstrap funding');
$q=$p;$q['fees']['governance']='1';rejects(fn()=>Policy::validate($q),'Bootstrap cannot fund');
$q=$p;$q['limits']['working_operations']='10000000000';$q['bootstrap_units']='1000000000';
check(Policy::validateProduction($q)===$q,'Mixed closed reserves and funded operations accepted');

$state=new State();$ledger=new Ledger($p,'zero-budget-unit-test',hex2bin(identity(20)),array_map(fn($n)=>hex2bin(identity($n)),[21,22,23]));
$state->restoreDerivedEconomics($ledger->snapshot());
$ledger->bootstrap($state);$ledger->assertSupply($state);
check($ledger->snapshot()['balances']===array_map('intval',$p['allocations']),'Zero bootstrap preserves reserves');
function action(Ledger $l,State $s,array $a,int $height,int $signer=1): void {
    $snapshot=$l->snapshot();$a+=['genesis'=>$snapshot['genesis'],'policy_version'=>$snapshot['policy_version']];
    // Clone to model State's transaction rollback when Ledger::apply throws.
    $candidate=clone $l;
    $tx=new Envelope(1,'zero-budget-unit-test',hex2bin(identity($signer)),0,Envelope::TYPE_TREASURY,CanonicalJson::encode($a),'',hex2bin(identity($signer)));
    $trialState=clone $s;
    $candidate->apply($trialState,$tx,$height);
    $l->apply($s,$tx,$height);
}
function proposal(Ledger $l,State $s,string $reserve,int $height,?array $replacement=null): string {
    $a=['action'=>'propose','kind'=>$replacement===null?'payment':'policy','reserve'=>$reserve,
        'nonce'=>$l->snapshot()['nonces'][$reserve]??0,'expires'=>$height+60000];
    if($replacement===null)$a+=['amount'=>'1','recipient'=>identity($reserve==='working_operations'?7:8)];
    else $a['replacement']=$replacement;
    action($l,$s,$a,$height);return array_key_last($l->snapshot()['proposals']);
}
foreach(['grants','institute_treasury','working_operations'] as $reserve){
    $id=proposal($ledger,$state,$reserve,1);
    foreach([1,2] as $signer)action($ledger,$state,['action'=>'approve','proposal'=>$id],1,$signer);
    $before=$ledger->snapshot();
    rejects(fn()=>action($ledger,$state,['action'=>'approve','proposal'=>$id],1,3),'Reserve budget');
    check($ledger->snapshot()===$before,'Failed authorization does not reserve funds');
    rejects(fn()=>action($ledger,$state,['action'=>'execute','proposal'=>$id],5000),'threshold/timelock');
}
rejects(fn()=>proposal($ledger,$state,'subsidies',1),'direct token grants prohibited');
// A previously supported program cannot bypass the zero subsidy ceiling.
$program=['id'=>identity(30),'provider_key'=>identity(31),'service_id'=>identity(32),
    'adapter'=>'compute_cpu_v1','start_height'=>1,'end_height'=>100,'subsidy_percent'=>50,
    'threshold'=>2,'gru_scale'=>1,'budget_units'=>'1','beneficiary_budget_units'=>'1',
    'max_gru_units'=>'1','gru_per_cpu_ms_num'=>'1','gru_per_cpu_ms_den'=>'1',
    'beneficiaries'=>[identity(8)],'attestors'=>[identity(33),identity(34)]];
$program['catalog_hash']=ServiceSettlement::catalogHash($program);
$legacy=$p;unset($legacy['service_trust_policy']);$legacy['limits']['subsidies']='1';
check(ServiceSettlement::validateProgram($program,$legacy)===$program,'Positive program control is valid');
$legacy['limits']['subsidies']='0';
rejects(fn()=>ServiceSettlement::validateProgram($program,$legacy),'Program exceeds subsidy ceiling');
rejects(fn()=>ServiceSettlement::validateProgram($program,$p),'explicit resource trust profiles');
$before=$ledger->snapshot();rejects(fn()=>$ledger->lockSubsidy(1,1),'budget');
check($ledger->snapshot()===$before,'Zero subsidy ceiling rejects reservation without debiting');
$ledger->assertSupply($state);
check(Ledger::restore($ledger->snapshot())->snapshot()===$ledger->snapshot(),'Zero policy cache round trip');

// Reopening a reserve requires the unchanged four approvals and policy timelock.
$replacement=$p;$replacement['limits']['grants']='1';
$id=proposal($ledger,$state,'institute_treasury',2,$replacement);
foreach([1,2,3] as $signer)action($ledger,$state,['action'=>'approve','proposal'=>$id],2,$signer);
rejects(fn()=>action($ledger,$state,['action'=>'execute','proposal'=>$id],20162),'threshold/timelock');
action($ledger,$state,['action'=>'approve','proposal'=>$id],2,4);
rejects(fn()=>action($ledger,$state,['action'=>'execute','proposal'=>$id],20161),'threshold/timelock');
action($ledger,$state,['action'=>'execute','proposal'=>$id],20162);
check($ledger->snapshot()['policy']['limits']['grants']==='1','Authorized policy reopens grant budget');
$id=proposal($ledger,$state,'grants',20163);
foreach([1,2,3] as $signer)action($ledger,$state,['action'=>'approve','proposal'=>$id],20163,$signer);
action($ledger,$state,['action'=>'execute','proposal'=>$id],24483);
check($state->getAccount(hex2bin(identity(8)))->getBalance()===1,'Authorized payment executes within reopened ceiling');
$ledger->assertSupply($state);
// Close again without discarding the payment history or altering reserve balances.
$id=proposal($ledger,$state,'institute_treasury',24484,$p);
foreach([1,2,3,4] as $signer)action($ledger,$state,['action'=>'approve','proposal'=>$id],24484,$signer);
$balances=$ledger->snapshot()['balances'];$spent=$ledger->snapshot()['spent'];
action($ledger,$state,['action'=>'execute','proposal'=>$id],44644);
check($ledger->snapshot()['balances']===$balances && $ledger->snapshot()['spent']===$spent,'Closing budget preserves accounting');
$id=proposal($ledger,$state,'grants',70000);
foreach([1,2] as $signer)action($ledger,$state,['action'=>'approve','proposal'=>$id],70000,$signer);
rejects(fn()=>action($ledger,$state,['action'=>'approve','proposal'=>$id],70000,3),'Reserve budget');
$ledger->finalize($state,1);
check($ledger->snapshot()['released']===10000000,'Zero discretionary budgets preserve scheduled validator distributions');
$ledger->assertSupply($state);
echo "zero-reserve-budgets: $checks checks passed (no key generation)\n";
