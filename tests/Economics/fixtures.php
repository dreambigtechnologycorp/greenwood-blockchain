<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Greenwood\Economics\{Amount,CanonicalJson,GruQuote,Policy};
use Greenwood\Crypto\{Ed25519KeyPair,ProtocolHash};
use Greenwood\Genesis\Genesis;
use Greenwood\State\State;
use Greenwood\Transaction\{Envelope,TransferU64};

$checks=0;$started=microtime(true);
function check(bool $value,string $message): void {global $checks;$checks++;if(!$value)throw new RuntimeException($message);}
function rejects(callable $fn,string $message): void {global $checks;$checks++;try{$fn();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new RuntimeException('Accepted invalid action: '.$message);}
function addr(Ed25519KeyPair $k): string{return ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$k->getPublicKey());}
function fixture(bool $strictTrust=false): array {
    $signers=[];for($i=0;$i<6;$i++)$signers[]=Ed25519KeyPair::generate();
    $guardian=Ed25519KeyPair::generate();$ops=Ed25519KeyPair::generate();$beneficiary=Ed25519KeyPair::generate();$other=Ed25519KeyPair::generate();
    $p=['version'=>1,'profile'=>'production','block_seconds'=>60,'decimals'=>6,'burn_percent'=>0,'operator_percent'=>100,
        'signers'=>array_map(fn($k)=>bin2hex($k->getPublicKey()),array_slice($signers,0,5)), 'guardians'=>[bin2hex($guardian->getPublicKey())], 'pause_blocks'=>10,
        'allocations'=>['security_emissions'=>'400000000000000','grants'=>'200000000000000','subsidies'=>'150000000000000','institute_treasury'=>'240000000000000','working_operations'=>'10000000000000'],
        'limits'=>['grants'=>'1000000000000','subsidies'=>'750000000000','institute_treasury'=>'1200000000000','working_operations'=>'100000000000'],
        'large_threshold'=>'100000000000','operations_balance'=>'100000000000','operations_payment'=>'5000000000','operations_window'=>'100000000000',
        'bootstrap_units'=>'1000000000','operations_account'=>bin2hex(addr($ops)), 'clock'=>'finalized_height','window_blocks'=>43200,
        'emission_distribution'=>'equal_genesis_validators','exhaustion'=>'partial_then_stop','failed_transaction_fee'=>'reject_without_charge',
        'fees'=>['transfer'=>'7','governance'=>'0','contract'=>'0','compute'=>'0'],
        'recipients'=>[bin2hex(addr($beneficiary))=>'project_one',bin2hex(addr($other))=>'project_one',bin2hex(addr($ops))=>'operations']];
    $allocation=getenv('GWD_ALLOCATION_CANDIDATE')?:'15-25-30-25-5';
    if(!in_array($allocation,['15-25-30-25-5','40-20-15-24-1','10-25-30-34-1'],true))throw new InvalidArgumentException('Unknown allocation candidate');
    if($allocation==='15-25-30-25-5')$p['allocations']=Policy::PRODUCTION_ALLOCATION;
    if($allocation==='10-25-30-34-1'){
        $p['allocations']=['security_emissions'=>'100000000000000','grants'=>'250000000000000','subsidies'=>'300000000000000','institute_treasury'=>'340000000000000','working_operations'=>'10000000000000'];
        $p['limits']=['grants'=>'250000000000','subsidies'=>'300000000000','institute_treasury'=>'340000000000','working_operations'=>'100000000000'];
    }
    if($strictTrust){$p['service_trust_policy']='explicit_profiles_v1';$p['recovery_policy']=['enabled'=>false];}
    $g=new Genesis('greenwood-mainnet-economics-disposable-test');$validators=[];
    foreach(['A','B','C'] as $name){$k=Ed25519KeyPair::generate();$validators[$name]=$k;$g->addValidator($name,$k);}
    $g->configureEconomics($p);
    return compact('p','g','signers','guardian','ops','beneficiary','other','validators');
}
function transaction(State $s,Ed25519KeyPair $key,array $action,string $chain): Envelope {
    $action += ['genesis'=>$s->economicSummary()['genesis'],'policy_version'=>$s->economicSummary()['policy_version']];
    $t=new Envelope(1,$chain,addr($key),($s->getAccounts()[addr($key)]??new \Greenwood\State\Account(addr($key)))->getNonce(),Envelope::TYPE_TREASURY,CanonicalJson::encode($action),'',$key->getPublicKey());
    return $t->setSignature($key->sign($t->getSigningDigest()));
}
function act(State $s,Ed25519KeyPair $key,array $a,int $height,string $chain): void {$s->applyTransaction(transaction($s,$key,$a,$chain),$chain,$height);}
function advance(State $s,int $height): void {for($i=($s->economicSummary()['last_release_height']??0)+1;$i<=$height;$i++)$s->finalizeEconomicBlock($i);}
function propose(State $s,array $f,string $reserve,int $amount,int $height,string $group='beneficiary',string $kind='payment',?array $replacement=null): string {
    $nonce=$s->economicSummary()['nonces'][$reserve]??0;
    $a=['action'=>'propose','kind'=>$kind,'reserve'=>$reserve,'nonce'=>$nonce,'expires'=>$height+60000];
    if($kind==='payment'){$a['recipient']=bin2hex(addr($f[$group]));$a['amount']=(string)$amount;}
    if($kind==='policy')$a['replacement']=$replacement;
    act($s,$f['signers'][0],$a,$height,$f['g']->getChainId());$ids=array_keys($s->economicSummary()['proposals']);return end($ids);
}
function approve(State $s,array $f,string $id,int $count,int $height): void {for($i=0;$i<$count;$i++)act($s,$f['signers'][$i],['action'=>'approve','proposal'=>$id],$height,$f['g']->getChainId());}
