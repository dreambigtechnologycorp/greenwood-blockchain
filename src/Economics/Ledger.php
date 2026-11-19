<?php
declare(strict_types=1);
namespace Greenwood\Economics;

use Greenwood\Crypto\ProtocolHash;
use Greenwood\State\State;
use Greenwood\Transaction\Envelope;

/** Native, deterministic monetary ledger. Reserve balances are not ordinary accounts. */
final class Ledger
{
    private ServiceSettlement $services;
    private \Greenwood\Network\ResourcePolicyState $resourcePolicies;
    private array $balances = [];
    private array $proposals = [];
    private array $spent = [];
    private array $paused = [];
    private array $pauseUsed = [];
    private array $nonces = [];
    private array $recovery = ['nonce'=>0,'proposal'=>null,'last_event'=>null];
    private int $policyVersion = 1;
    private int $lastReleaseHeight = 0;
    private int $released = 0;
    private int $feesDistributed = 0;

    public function __construct(private array $policy, private readonly string $chain, private readonly string $genesis, private array $operators)
    {
        $this->services = new ServiceSettlement();
        $this->resourcePolicies = new \Greenwood\Network\ResourcePolicyState();
        $this->policy = Policy::validate($policy);
        sort($this->operators,SORT_STRING);
        if ($chain === '' || strlen($genesis) !== 32 || count($operators) < 3) throw new \InvalidArgumentException('Economic chain identity/operators required');
        if(count(array_unique($operators))!==count($operators))throw new \InvalidArgumentException('Duplicate economic operator');
        foreach($operators as $operator)if(bin2hex($operator)===($policy['operations_account']??null))throw new \InvalidArgumentException('Operations account must not be a validator payout account');
        foreach ($operators as $operator) if (strlen($operator) !== 32) throw new \InvalidArgumentException('Invalid fee operator');
        foreach ($policy['allocations'] as $id => $amount) $this->balances[$id] = Amount::integer($amount);
    }
    public function bootstrap(State $state): void
    {
        $amount=Amount::integer($this->policy['bootstrap_units']);if($amount===0)return;
        $to=hex2bin($this->policy['operations_account']);
        $state->creditEconomicAccount($to,Amount::add($state->getAccount($to)->getBalance(),$amount));
        $this->balances['working_operations']-=$amount;
        $this->spent[]=['reserve'=>'working_operations','group'=>$this->policy['recipients'][bin2hex($to)]??'operations','height'=>0,'amount'=>$amount];
    }
    public function snapshot(): array
    {
        return ['chain'=>$this->chain,'genesis'=>bin2hex($this->genesis),'operators'=>array_map('bin2hex',$this->operators),'version'=>1,'policy'=>$this->policy,'policy_version'=>$this->policyVersion,'balances'=>$this->balances,
            'proposals'=>$this->proposals,'spent'=>$this->spent,'paused'=>$this->paused,'pause_used'=>$this->pauseUsed,'nonces'=>$this->nonces,
            'services'=>$this->services->snapshot(),'last_release_height'=>$this->lastReleaseHeight,'released'=>$this->released,'fees_distributed'=>$this->feesDistributed]
            + (RecoveryPolicy::enabled($this->policy)?['recovery_state'=>$this->recovery]:[])
            + ($this->resourcePolicies->snapshot()?['resource_policies'=>$this->resourcePolicies->snapshot()]:[]);
    }
    /** Derived-cache restoration is checked against the finalized state root by StateSqlite. */
    public static function restore(array $snapshot): self
    {
        $operators=array_map(fn($v)=>hex2bin(Policy::hex($v)),$snapshot['operators']??[]);
        $ledger=new self($snapshot['policy']??[],$snapshot['chain']??'',hex2bin(Policy::hex($snapshot['genesis']??null)),$operators);
        foreach(['policy_version','last_release_height','released','fees_distributed'] as $name)if(!is_int($snapshot[$name]??null)||$snapshot[$name]<0)throw new \InvalidArgumentException('Invalid derived economics counter');
        foreach(['balances','proposals','spent','paused','pause_used','nonces'] as $name)if(!is_array($snapshot[$name]??null))throw new \InvalidArgumentException('Invalid derived economics collection');
        $ledger->balances=$snapshot['balances'];$ledger->proposals=$snapshot['proposals'];$ledger->spent=$snapshot['spent'];$ledger->paused=$snapshot['paused'];$ledger->pauseUsed=$snapshot['pause_used'];$ledger->nonces=$snapshot['nonces'];
        $ledger->policyVersion=$snapshot['policy_version'];$ledger->lastReleaseHeight=$snapshot['last_release_height'];$ledger->released=$snapshot['released'];$ledger->feesDistributed=$snapshot['fees_distributed'];
        $ledger->services=ServiceSettlement::restore($snapshot['services']??['programs'=>[],'commitments'=>[],'bindings'=>[],'quotes'=>[]]);
        $ledger->resourcePolicies=\Greenwood\Network\ResourcePolicyState::restore($snapshot['resource_policies']??[]);
        if(RecoveryPolicy::enabled($ledger->policy)){
            $r=$snapshot['recovery_state']??null;
            $fields=is_array($r)?array_keys($r):[];sort($fields);
            if(!is_array($r)||$fields!==['last_event','nonce','proposal']||!is_int($r['nonce'])||$r['nonce']<0)throw new \InvalidArgumentException('Missing or malformed recovery cache');
            $ledger->recovery=$r;
        }elseif(isset($snapshot['recovery_state']))throw new \InvalidArgumentException('Recovery state without genesis authority');
        return $ledger;
    }
    public function reserveAddress(string $id): string { return ProtocolHash::hash('GREENWOOD_RESERVE_V1', $id); }
    public function isReserveAddress(string $address): bool
    {
        foreach (Policy::RESERVES as $id) if ($this->reserveAddress($id) === $address) return true;
        return false;
    }
    public function validateAccountCredit(string $address, int $balance, bool $authorizedOperations = false): void
    {
        if ($this->isReserveAddress($address)) throw new \InvalidArgumentException('Reserve identifiers cannot be ordinary accounts');
        if (bin2hex($address) === ($this->policy['operations_account'] ?? null)
            && (!$authorizedOperations || $balance > Amount::integer($this->policy['operations_balance']))) throw new \InvalidArgumentException('Restricted operations credit');
    }
    public function validateDebit(Envelope $tx, int $height): void
    {
        if ($this->isReserveAddress($tx->getSender())) throw new \InvalidArgumentException('Ordinary reserve spending prohibited');
        if($tx->getType()===Envelope::TYPE_TRANSFER)throw new \InvalidArgumentException('Economic transfers require the u64 transaction type');
        if (bin2hex($tx->getSender()) === ($this->policy['operations_account'] ?? null)
            && !in_array($tx->getType(),[Envelope::TYPE_TRANSFER,Envelope::TYPE_TRANSFER_U64],true)) throw new \InvalidArgumentException('Operations account permits routine transfers only');
        if(bin2hex($tx->getSender())===($this->policy['operations_account']??null) && $tx->getType()===Envelope::TYPE_TRANSFER_U64){$r=new \Greenwood\Codec\Reader($tx->getPayload(),40);$r->take(32);$debit=Amount::add($r->u64(),Amount::integer($this->policy['fees']['transfer']));if($debit>Amount::integer($this->policy['operations_payment']))throw new \InvalidArgumentException('Routine payment including fee exceeds ceiling');}
        if ($height < 1 || $height !== $this->lastReleaseHeight + 1) throw new \InvalidArgumentException('Economic inclusion height mismatch');
    }
    public function recordRoutineDebit(string $sender, int $amount, int $height): void
    {
        if (bin2hex($sender) !== ($this->policy['operations_account'] ?? null)) return;
        if ($amount > Amount::integer($this->policy['operations_payment'])) throw new \InvalidArgumentException('Routine payment ceiling exceeded');
        $used = $this->windowSpent('operations_spend',null,$height);
        if (Amount::add($used,$amount) > Amount::integer($this->policy['operations_window'])) throw new \InvalidArgumentException('Operations window exhausted');
        $this->spent[] = ['reserve'=>'operations_spend','group'=>'operations','height'=>$height,'amount'=>$amount];
    }
    private function windowSpent(string $reserve, ?string $group, int $height): int
    {
        $sum = 0;
        foreach ($this->spent as $row) if ($row['height'] > $height-$this->policy['window_blocks'] && ($reserve==='*' || $row['reserve']===$reserve) && ($group===null || $row['group']===$group)) $sum=Amount::add($sum,$row['amount']);
        return $sum;
    }
    private function commitments(string $reserve, ?string $group, int $height, ?string $exclude = null): int
    {
        $sum=$this->windowSpent($reserve,$group,$height);
        foreach ($this->proposals as $id=>$p) if ($id!==$exclude && $p['status']==='queued' && $p['expires'] >= $height && $p['version']===$this->policyVersion && $p['kind']==='payment'
            && ($reserve==='*' || $p['reserve']===$reserve) && ($group===null || $p['group']===$group)) $sum=Amount::add($sum,$p['amount']);
        if($reserve==='subsidies' && $group===null)$sum=Amount::add($sum,$this->services->subsidyCommitted());
        return $sum;
    }
    private function signer(string $key): void
    {
        if (!in_array($key,$this->policy['signers'],true)) throw new \InvalidArgumentException('Unauthorized Institute signer');
    }
    private function tier(array $p, string $id, int $height): string
    {
        if ($p['kind']!=='payment') return 'policy';
        return Amount::add($this->commitments('*',$p['group'],$height,$id),$p['amount']) >= Amount::integer($this->policy['large_threshold']) ? 'large' : 'ordinary';
    }
    private function requirements(string $tier): array { return match($tier) {'ordinary'=>[3,4320],'large'=>[4,10080],default=>[4,20160]}; }
    private function schedule(string $id, int $height): void
    {
        $p=$this->proposals[$id];$tier=$this->tier($p,$id,$height);[$count,$delay]=$this->requirements($tier);
        if (count($p['approvals'])<$count) return;
        if ($p['kind']==='payment') {
            $reserved=$this->commitments($p['reserve'],null,$height,$id);
            if (Amount::add($reserved,$p['amount'])>Amount::integer($this->policy['limits'][$p['reserve']]) || Amount::add($reserved-$this->windowSpent($p['reserve'],null,$height),$p['amount'])>$this->balances[$p['reserve']]) throw new \InvalidArgumentException('Reserve budget/balance exhausted');
        }
        if ($p['status']!=='queued' || $p['tier']!==$tier) {
            $p['status']='queued';$p['tier']=$tier;$p['ready']=Amount::add($height,$delay);
            if ($p['ready']>$p['expires']) throw new \InvalidArgumentException('Proposal expires before timelock');
            $this->proposals[$id]=$p;
        }
    }
    /** The enclosing signed envelope authenticates approvals; proposal IDs bind genesis and policy. */
    public function apply(State $state, Envelope $tx, int $height): void
    {
        $a=CanonicalJson::decode($tx->getPayload());$action=$a['action']??null;if(($a['genesis']??null)!==bin2hex($this->genesis)||($a['policy_version']??null)!==$this->policyVersion)throw new \InvalidArgumentException('Treasury genesis/policy binding mismatch');$key=bin2hex($tx->getSenderPublicKey());
        if(is_string($action)&&str_starts_with($action,'recovery_')){$this->applyRecovery($a,$key,$height);return;}
        if ($action==='pause') {
            if (!in_array($key,$this->policy['guardians'],true)) throw new \InvalidArgumentException('Unauthorized guardian');
            $reserve=$a['reserve']??'';
            if (!isset($this->balances[$reserve]) || $reserve==='security_emissions' || (($this->paused[$reserve]??0)>=$height || ($this->pauseUsed[$reserve]??0)===$this->policyVersion)) throw new \InvalidArgumentException('Invalid/already-active discretionary pause');
            $this->paused[$reserve]=Amount::add($height,$this->policy['pause_blocks']);$this->pauseUsed[$reserve]=$this->policyVersion;return;
        }
        $this->signer($key);
        if ($action==='propose') {
            if (count($this->proposals)>=10000) throw new \InvalidArgumentException('Proposal capacity reached');
            $kind=$a['kind']??'';$reserve=$a['reserve']??'';$nonce=$a['nonce']??null;$expires=$a['expires']??null;
            if (!in_array($kind,['payment','policy','unpause','service_program','resource_network'],true) || !is_int($nonce) || $nonce<0 || $nonce!==($this->nonces[$reserve]??0)
                || !is_int($expires) || $expires<=$height || $expires>Amount::add($height,525960)) throw new \InvalidArgumentException('Invalid proposal nonce/expiry/type');
            if (!isset($this->balances[$reserve]) || $reserve==='security_emissions') throw new \InvalidArgumentException('Reserve cannot authorize discretionary spending');
            $p=['kind'=>$kind,'reserve'=>$reserve,'nonce'=>$nonce,'expires'=>$expires,'version'=>$this->policyVersion,'creator'=>$key,
                'status'=>'pending','approvals'=>[],'tier'=>null,'ready'=>null,'amount'=>0,'recipient'=>null,'group'=>null,'replacement'=>null];
            if ($kind==='payment') {
                if($reserve==='subsidies')throw new \InvalidArgumentException('Subsidies require qualified GRU metering and settlement; direct token grants prohibited');
                $recipient=Policy::hex($a['recipient']??null);$amount=Amount::integer($a['amount']??null);
                if ($amount<1 || !isset($this->policy['recipients'][$recipient]) || $this->isReserveAddress(hex2bin($recipient))) throw new \InvalidArgumentException('Unauthorized payment beneficiary');
                if ($recipient===($this->policy['operations_account']??null) && $reserve!=='working_operations') throw new \InvalidArgumentException('Operations replenishment must use its working reserve');
                if ($reserve==='working_operations' && $recipient!==($this->policy['operations_account']??null)) throw new \InvalidArgumentException('Working reserve only replenishes protected operations');
                $p['amount']=$amount;$p['recipient']=$recipient;$p['group']=$this->policy['recipients'][$recipient];
            } elseif ($kind==='resource_network') {
                if($reserve!=='institute_treasury')throw new \InvalidArgumentException('Network policy requires Institute policy authority');
                $p['replacement']=\Greenwood\Network\ResourcePolicyState::validate($a['record']??[]);
                if($p['replacement']['kind']==='operator'&&$p['replacement']['independent']&&$p['replacement']['participation_end_height']>=$height)throw new \InvalidArgumentException('Governance cannot preapprove future participation');
            } elseif ($kind==='service_program') {
                if($reserve!=='subsidies')throw new \InvalidArgumentException('Service program requires subsidy governance');
                $p['replacement']=ServiceSettlement::validateProgram($a['program']??[],$this->policy);
            } elseif ($kind==='policy') {
                $replacement=Policy::validate($a['replacement']??[]);
                if(CanonicalJson::encode([$replacement['recovery_policy']??null])!==CanonicalJson::encode([$this->policy['recovery_policy']??null]))throw new \InvalidArgumentException('Recovery authority is genesis-bound');
                // Allocation, emission/fee mechanism, operational exposure and clocks cannot be changed by this action.
                foreach ($this->policy as $name=>$value) if (!in_array($name,['signers','guardians','recipients','limits'],true) && CanonicalJson::encode([$replacement[$name]??null])!==CanonicalJson::encode([$value])) throw new \InvalidArgumentException('Immutable economic policy');
                $p['replacement']=$replacement;
            }
            $body=['chain'=>$this->chain,'genesis'=>bin2hex($this->genesis),'proposal'=>$p];
            $id=bin2hex(ProtocolHash::hash('GREENWOOD_TREASURY_PROPOSAL_V1',CanonicalJson::encode($body)));
            $this->proposals[$id]=$p;$this->nonces[$reserve]=$nonce+1;return;
        }
        $id=$a['proposal']??'';$p=$this->proposals[$id]??null;
        if (!$p || $p['version']!==$this->policyVersion || $p['expires']<$height || !in_array($p['status'],['pending','queued'],true)) throw new \InvalidArgumentException('Stale, expired or terminal proposal');
        if ($action==='cancel') {
            if ($p['creator']!==$key) throw new \InvalidArgumentException('Only proposal creator may cancel');
            $this->proposals[$id]['status']='cancelled';return;
        }
        if ($action==='approve') {
            if (in_array($key,$p['approvals'],true)) throw new \InvalidArgumentException('Duplicate approval');
            $this->proposals[$id]['approvals'][]=$key;$this->schedule($id,$height);return;
        }
        if ($action==='schedule') { $this->schedule($id,$height);return; }
        if ($action!=='execute') throw new \InvalidArgumentException('Unsupported treasury action');
        $tier=$this->tier($p,$id,$height);[$threshold]=$this->requirements($tier);
        if ($p['status']!=='queued' || $p['tier']!==$tier || count($p['approvals'])<$threshold || $height<$p['ready']) throw new \InvalidArgumentException('Approval threshold/timelock not satisfied');
        if (($this->paused[$p['reserve']]??0)>=$height && $p['kind']!=='unpause') throw new \InvalidArgumentException('Reserve paused');
        if ($p['kind']==='payment') {
            if (Amount::add($this->commitments($p['reserve'],null,$height,$id),$p['amount'])>Amount::integer($this->policy['limits'][$p['reserve']]) || $p['amount']>$this->balances[$p['reserve']]) throw new \InvalidArgumentException('Reserve budget/balance exhausted');
            $to=hex2bin($p['recipient']);$new=Amount::add($state->getAccount($to)->getBalance(),$p['amount']);
            $state->creditEconomicAccount($to,$new);
            $this->balances[$p['reserve']]-=$p['amount'];$this->spent[]=['reserve'=>$p['reserve'],'group'=>$p['group'],'height'=>$height,'amount'=>$p['amount']];
        } elseif ($p['kind']==='service_program') {$this->services->install(ServiceSettlement::validateProgram($p['replacement'],$this->policy));}
        elseif ($p['kind']==='resource_network') {$this->resourcePolicies->install($p['replacement'],$height,$this->policy['signers']);}
        elseif ($p['kind']==='unpause') {unset($this->paused[$p['reserve']],$this->pauseUsed[$p['reserve']]);}
        else {
            $this->policy=$p['replacement'];$this->policyVersion++;
            foreach ($this->proposals as &$other) if (in_array($other['status'],['pending','queued'],true)) $other['status']='invalidated';
            unset($other);
        }
        $this->proposals[$id]['status']='executed';
    }
    /** Recovery can replace governance keys only; it has no reserve payment operation. */
    private function applyRecovery(array $a,string $key,int $height): void
    {
        if(!RecoveryPolicy::enabled($this->policy))throw new \InvalidArgumentException('Recovery is not authorized by genesis');
        $r=$this->policy['recovery_policy'];$action=$a['action'];
        $allowed=['action','genesis','policy_version','proposal'];
        if($action==='recovery_propose')$allowed=['action','genesis','policy_version','nonce','signers','guardians','reason_hash'];
        if(array_diff(array_keys($a),$allowed))throw new \InvalidArgumentException('Recovery cannot alter financial policy or spend reserves');
        if($action==='recovery_veto')$this->signer($key);
        elseif(!in_array($key,$r['keys'],true))throw new \InvalidArgumentException('Unauthorized recovery signer');
        $p=$this->recovery['proposal'];
        if($action==='recovery_propose'){
            if($p&&$p['version']===$this->policyVersion&&$p['expires']>=$height)throw new \InvalidArgumentException('Recovery proposal already active');
            if(($a['nonce']??null)!==$this->recovery['nonce'])throw new \InvalidArgumentException('Recovery nonce mismatch');
            Policy::hex($a['reason_hash']??null);
            $replacement=$this->policy;$replacement['signers']=$a['signers']??[];$replacement['guardians']=$a['guardians']??[];Policy::validate($replacement);
            if($replacement['signers']===$this->policy['signers']&&$replacement['guardians']===$this->policy['guardians'])throw new \InvalidArgumentException('Recovery must replace authority');
            $body=['chain'=>$this->chain,'genesis'=>bin2hex($this->genesis),'version'=>$this->policyVersion,'nonce'=>$a['nonce'],
                'signers'=>$replacement['signers'],'guardians'=>$replacement['guardians'],'reason_hash'=>$a['reason_hash'],
                'created'=>$height,'expires'=>Amount::add($height,$r['proposal_lifetime_blocks'])];
            $body['id']=bin2hex(ProtocolHash::hash('GREENWOOD_GOVERNANCE_RECOVERY_V1',CanonicalJson::encode($body)));
            $this->recovery['proposal']=$body+['approvals'=>[],'vetoes'=>[],'ready'=>null];
            $this->recovery['nonce']=Amount::add($this->recovery['nonce'],1);return;
        }
        if(!$p||($a['proposal']??null)!==$p['id']||$p['version']!==$this->policyVersion||$height>$p['expires'])throw new \InvalidArgumentException('Unknown, stale or expired recovery');
        if($action==='recovery_approve'){
            if(in_array($key,$p['approvals'],true))throw new \InvalidArgumentException('Duplicate recovery approval');
            $p['approvals'][]=$key;
            if(count($p['approvals'])===$r['threshold']){
                $p['ready']=Amount::add($height,$r['timelock_blocks']);
                if($p['ready']>$p['expires'])throw new \InvalidArgumentException('Recovery expires before timelock');
            }
            $this->recovery['proposal']=$p;return;
        }
        if($action==='recovery_veto'){
            if(in_array($key,$p['vetoes'],true))throw new \InvalidArgumentException('Duplicate recovery veto');
            $p['vetoes'][]=$key;
            if(count($p['vetoes'])>=$r['veto_threshold']){$this->recovery['last_event']=['id'=>$p['id'],'status'=>'vetoed','height'=>$height];$this->recovery['proposal']=null;}
            else $this->recovery['proposal']=$p;
            return;
        }
        if($action!=='recovery_execute'||$p['ready']===null||$height<$p['ready']||count($p['approvals'])<$r['threshold'])throw new \InvalidArgumentException('Recovery quorum/timelock not satisfied');
        $replacement=$this->policy;$replacement['signers']=$p['signers'];$replacement['guardians']=$p['guardians'];Policy::validate($replacement);
        $this->policy=$replacement;$this->policyVersion=Amount::add($this->policyVersion,1);
        foreach($this->proposals as &$old)if(in_array($old['status'],['pending','queued'],true))$old['status']='invalidated';unset($old);
        $this->recovery['last_event']=['id'=>$p['id'],'status'=>'executed','height'=>$height];$this->recovery['proposal']=null;
    }
    public function chargeFee(State $state, Envelope $tx, int $height): void
    {
        $category=match($tx->getType()) {Envelope::TYPE_TREASURY=>'governance',Envelope::TYPE_TRANSFER,Envelope::TYPE_TRANSFER_U64=>'transfer',Envelope::TYPE_CALL_CONTRACT,Envelope::TYPE_DEPLOY_CONTRACT=>'contract',default=>'compute'};
        $fee=Amount::integer($this->policy['fees'][$category]);if ($fee===0) return;
        $sender=$tx->getSender();$balance=$state->getAccount($sender)->getBalance();
        if ($balance<$fee) throw new \InvalidArgumentException('Insufficient fee balance');
        $this->recordRoutineDebit($sender,$fee,$height);
        $state->debitEconomicAccount($sender,$balance-$fee);$this->distribute($state,$fee);
        $this->feesDistributed=Amount::add($this->feesDistributed,$fee);
    }
    private function distribute(State $state, int $amount): void
    {
        $operators=$this->operators;sort($operators,SORT_STRING);$each=intdiv($amount,count($operators));$remainder=$amount%count($operators);
        foreach ($operators as $i=>$to) $state->creditEconomicAccount($to,Amount::add($state->getAccount($to)->getBalance(),$each+($i<$remainder?1:0)));
    }
    public function finalize(State $state, int $height): void
    {
        if ($height!==$this->lastReleaseHeight+1) throw new \InvalidArgumentException('Duplicate or out-of-order economic block');
        $year=intdiv($height-1,525960);$reward=match(true) {$year<4=>10_000_000,$year<8=>7_500_000,$year<12=>5_000_000,$year<16=>2_500_000,default=>1_000_000};
        $amount=min($reward,$this->balances['security_emissions']);$this->balances['security_emissions']-=$amount;
        $this->distribute($state,$amount);$this->released=Amount::add($this->released,$amount);$this->lastReleaseHeight=$height;
        $this->spent=array_values(array_filter($this->spent,fn($r)=>$r['height']>$height-$this->policy['window_blocks']));
        $this->assertSupply($state);
    }
    public function __clone() { $this->services=clone $this->services;$this->resourcePolicies=clone $this->resourcePolicies; }
    public function beneficiaryGroup(string $payer): string {
        return $this->policy['recipients'][$payer]??throw new \InvalidArgumentException('Unregistered service beneficiary');
    }
    public function lockSubsidy(int $amount,int $height): void {
        if($amount<0)throw new \InvalidArgumentException('Invalid subsidy amount');
        // Customer-only escrow is independent of reserve pauses, prior spending
        // and commitments. No reserve authorization or debit is needed for zero.
        if($amount===0)return;
        if(($this->paused['subsidies']??0)>=$height || $amount>$this->balances['subsidies']
            || Amount::add($this->commitments('subsidies',null,$height),$amount)>Amount::integer($this->policy['limits']['subsidies']))throw new \InvalidArgumentException('Subsidy paused/budget/balance exhausted');
        $this->balances['subsidies']-=$amount;
    }
    public function releaseSubsidy(int $refund,int $spent,string $group,int $height): void {
        $this->balances['subsidies']=Amount::add($this->balances['subsidies'],$refund);
        if($spent>0)$this->spent[]=['reserve'=>'subsidies','group'=>$group,'height'=>$height,'amount'=>$spent];
    }
    public function resourceTotals(State $state): array { return $this->services->resourceTotals($state,$this->lastReleaseHeight); }
    public function validateResourceCapabilityUpdate(State $state,Envelope $tx,int $height): void { $this->services->validateCapabilityUpdate($state,$tx,$height); }
    public function validateBaseCapabilityUpdate(State $state,Envelope $tx,int $height): void { $this->services->validateBaseUpdate($state,$tx,$height); }
    public function requireServiceFunding(\Greenwood\Compute\Protocol\ComputeAssign $assignment,int $height): void { $this->services->requireFunding($assignment,$height); }
    public function applyService(State $state,Envelope $tx,int $height): void { $this->services->apply($state,$this,$tx,$height,$this->chain,$this->genesis,$this->resourcePolicies); }
    public function assertSupply(State $state): void
    {
        $sum=$this->services->escrowTotal();foreach ($this->balances as $value) $sum=Amount::add($sum,$value);
        foreach ($state->getAccounts() as $account) $sum=Amount::add($sum,$account->getBalance());
        foreach ($state->getContractBalances() as $value) $sum=Amount::add($sum,$value);
        if ($sum!==Amount::ISSUANCE) throw new \InvalidArgumentException('Native supply conservation violated');
    }
}
