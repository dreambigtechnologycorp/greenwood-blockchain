<?php
declare(strict_types=1);
namespace Greenwood\Economics;

use Greenwood\Crypto\ProtocolHash;
use Greenwood\State\State;
use Greenwood\Transaction\Envelope;
use Greenwood\Compute\Protocol\ComputeAssign;
use Greenwood\Resources\{ResourceCatalog,ResourceManifest,ProviderCapabilityV2,ResourceUsageEvidence};

/** Consensus-only finance. Never reads cgroups, wall clocks, runtimes, or price APIs. */
final class ServiceSettlement
{
    public const CHALLENGE_BLOCKS = 4320;
    public const EVIDENCE_BLOCKS = 4320;
    public const DISPUTE_BLOCKS = 43200;
    private array $programs = [];
    private array $commitments = [];
    private array $bindings = [];
    private array $quotes = [];
    private array $archives = [];

    public function snapshot(): array { return ['programs'=>$this->programs,'commitments'=>$this->commitments,'bindings'=>$this->bindings,'quotes'=>$this->quotes]+($this->archives?['archives'=>$this->archives]:[]); }
    public static function restore(array $s): self {
        $v=new self();
        foreach(['programs','commitments','bindings','quotes'] as $name) {
            if(!is_array($s[$name]??null))throw new \InvalidArgumentException('Invalid settlement cache');
            $v->$name=$s[$name];
        }
        $v->archives=$s['archives']??[];if(!is_array($v->archives))throw new \InvalidArgumentException('Invalid archive cache');return $v;
    }
    public static function catalogHash(array $p): string {
        if(in_array($p['adapter']??null,['compute_resources_v2','hosted_resources_v2'],true))return ResourceCatalog::hash($p['resource_catalog']??[]);
        return bin2hex(ProtocolHash::hash('GREENWOOD_GRU_CATALOG_V1',CanonicalJson::encode([
            'adapter'=>$p['adapter']??null,'gru_scale'=>$p['gru_scale']??null,
            'gru_per_cpu_ms_num'=>$p['gru_per_cpu_ms_num']??null,'gru_per_cpu_ms_den'=>$p['gru_per_cpu_ms_den']??null,
            'billable_statuses'=>($p['adapter']??null)==='hosted_cpu_v1'?'interval_consumed_cpu':'all_terminal_consumed_cpu','cpu_ceiling'=>($p['adapter']??null)==='hosted_cpu_v1'?'accepted_interval_cpu_budget_v1':'accepted_request_cpu_times_timeout_v1'])));
    }
    /** Program identity is immutable; replacements use a new ID and do not rewrite funded contracts. */
    public static function validateProgram(array $p, array $policy): array {
        if (($policy['service_trust_policy']??null)==='explicit_profiles_v1' && (!isset($p['trust_profile']) || !in_array($p['adapter']??null,['compute_resources_v2','hosted_resources_v2'],true))) throw new \InvalidArgumentException('Production services require explicit resource trust profiles');
        \Greenwood\Network\AttestationTrust::shape($p);
        foreach(['id','provider_key','service_id','catalog_hash'] as $name)Policy::hex($p[$name]??null);
        if(!in_array($p['adapter']??null,['compute_cpu_v1','hosted_cpu_v1','compute_resources_v2','hosted_resources_v2'],true))throw new \InvalidArgumentException('Unsupported CPU adapter');
        if(in_array($p['adapter'],['hosted_cpu_v1','hosted_resources_v2'],true))foreach(['registry_contract_id','registry_code_hash'] as $name)Policy::hex($p[$name]??null);
        foreach(['start_height','end_height','subsidy_percent','threshold','gru_scale'] as $name)
            if(!is_int($p[$name]??null))throw new \InvalidArgumentException('Invalid program integer');
        if($p['start_height']<1 || $p['end_height']<=$p['start_height'] || $p['end_height']-$p['start_height']>525960
            || $p['subsidy_percent']<0 || $p['subsidy_percent']>100 || $p['gru_scale']<1 || $p['gru_scale']>1000000)
            throw new \InvalidArgumentException('Invalid bounded program');
        // These two budgets cap reserve subsidies, not customer payments. A fully
        // customer-funded program may explicitly authorize zero reserve spending.
        foreach(['budget_units','beneficiary_budget_units'] as $name)
            if(Amount::integer($p[$name]??null)<($p['subsidy_percent']===0?0:1))throw new \InvalidArgumentException('Invalid program budget/catalog');
        foreach(array_merge(['max_gru_units'],in_array($p['adapter'],['compute_resources_v2','hosted_resources_v2'],true)?[]:['gru_per_cpu_ms_num','gru_per_cpu_ms_den']) as $name)
            if(Amount::integer($p[$name]??null)<1)throw new \InvalidArgumentException('Invalid program budget/catalog');
        if($p['catalog_hash']!==self::catalogHash($p))throw new \InvalidArgumentException('Catalog hash does not commit resource conversion/billing policy');
        if(Amount::integer($p['beneficiary_budget_units'])>Amount::integer($p['budget_units'])
            || ($p['subsidy_percent']>0 && Amount::integer($p['budget_units'])>Amount::integer($policy['limits']['subsidies'])))throw new \InvalidArgumentException('Program exceeds subsidy ceiling');
        if(!is_array($p['beneficiaries']??null) || !$p['beneficiaries'] || count($p['beneficiaries'])>1000)throw new \InvalidArgumentException('Explicit beneficiaries required');
        foreach($p['beneficiaries'] as $payer){Policy::hex($payer);if(!isset($policy['recipients'][$payer]))throw new \InvalidArgumentException('Unregistered beneficiary');}
        $bootstrap=($p['trust_profile']??null)==='BOOTSTRAP_OPERATOR_ATTESTED';$minimum=$bootstrap?1:2;
        if(!is_array($p['attestors']??null) || count($p['attestors'])<$minimum || count($p['attestors'])>7
            || count(array_unique($p['attestors']))!==count($p['attestors']) || $p['threshold']<$minimum || $p['threshold']>count($p['attestors']))throw new \InvalidArgumentException('Explicit usage quorum required');
        $provider=bin2hex(ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',hex2bin($p['provider_key'])));
        foreach($p['attestors'] as $key){Policy::hex($key);$account=bin2hex(ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',hex2bin($key)));
            if((!$bootstrap&&$key===$p['provider_key']) || in_array($account,$p['beneficiaries'],true))throw new \InvalidArgumentException('Usage attestor cannot be service counterparty');}
        if(in_array($provider,$p['beneficiaries'],true) || $provider===($policy['operations_account']??null))throw new \InvalidArgumentException('Invalid program provider');
        return $p;
    }
    public function install(array $p): void {
        if(isset($this->programs[$p['id']]) || count($this->programs)>=1000)throw new \InvalidArgumentException('Duplicate/program capacity');
        $this->programs[$p['id']]=['policy'=>$p,'committed'=>0,'spent'=>0,'gru_committed'=>0,'gru_spent'=>0,'beneficiaries'=>[]];
    }
    private function resourceActive(array $c,State $state,int $height): bool {
        if(!in_array($c['adapter']??'', ['compute_resources_v2','hosted_resources_v2'],true)||$c['end_height']<=$height||in_array($c['status'],['paid','refunded'],true))return false;
        return $c['adapter']!=='compute_resources_v2'||!$state->getComputeState()->terminal(hex2bin($c['request_id']));
    }
    public function validateCapabilityUpdate(State $state,Envelope $tx,int $height): void {
        $new=ProviderCapabilityV2::validate(CanonicalJson::decode($tx->getPayload()));$newAssets=ProviderCapabilityV2::assets($new);
        foreach($this->commitments as $c){
            if($c['provider_key']!==$new['provider_key']||$c['end_height']<=$height||in_array($c['status'],['paid','refunded'],true))continue;
            if(!in_array($c['adapter']??'compute_cpu_v1',['compute_resources_v2','hosted_resources_v2'],true))throw new \InvalidArgumentException('Legacy obligations must finish before resource-mode conversion');
            if(!$this->resourceActive($c,$state,$height))continue;$oldAssets=ProviderCapabilityV2::assets($c['resource_capability']);
            foreach($c['resource_manifest']['lines'] as $line){$id=$line['asset_id'];
                if($line['resource_id']==='infrastructure.node.reserved'){$group=$oldAssets[$id]['billing_group'];$oldGroup=array_filter($oldAssets,fn($a)=>$a['billing_group']===$group);$newGroup=array_filter($newAssets,fn($a)=>$a['billing_group']===$group);if(CanonicalJson::encode($oldGroup)!==CanonicalJson::encode($newGroup))throw new \InvalidArgumentException('Dedicated node topology is committed until allocation ends');}
if(!isset($newAssets[$id])||CanonicalJson::encode($oldAssets[$id])!==CanonicalJson::encode($newAssets[$id]))throw new \InvalidArgumentException('Active resource allocation cannot be removed or reidentified');}
        }
        foreach($state->getComputeState()->assignedRequests() as $job)if(bin2hex($job['assignment']->providerPublicKey)===$new['provider_key']){
            $found=false;foreach($this->commitments as $c)if($c['adapter']==='compute_resources_v2'&&$c['request_id']===$job['id']){$found=true;break;}
            if(!$found)throw new \InvalidArgumentException('Unreleased legacy assignment blocks resource-mode conversion');
        }
    }
    public function validateBaseUpdate(State $state,Envelope $tx,int $height): void {
        $new=\Greenwood\Compute\Protocol\ProviderCapability::decode($tx->getPayload());$old=$state->getComputeState()->capabilities()[$new->providerId]??null;
        if(!$old)return;
        foreach($this->commitments as $c)if($c['provider_key']===bin2hex($new->providerPublicKey)&&$this->resourceActive($c,$state,$height)){
            if($new->cpuCapacityMillis<$old->cpuCapacityMillis||$new->memoryCapacityBytes<$old->memoryCapacityBytes||$new->pidCapacity<$old->pidCapacity||$new->maxConcurrentJobs<$old->maxConcurrentJobs||array_diff($old->architectures,$new->architectures))throw new \InvalidArgumentException('Active typed reservations block base-capacity reduction');
        }
    }
    private function assertResourceCapacity(State $state,array $manifest,array $capability,int $start,int $end): void {
        $capacities=ProviderCapabilityV2::groupCapacities($capability);$events=[];
        $add=function(array $d,int $from,int $until)use(&$events){foreach($d as $key=>$amount){$events[$from][]=['key'=>$key,'delta'=>$amount];$events[$until][]=['key'=>$key,'delta'=>-$amount];}};
        $add(ResourceManifest::demands($manifest,$capability),$start,$end);
        foreach($this->commitments as $c)if($c['provider_key']===$capability['provider_key']&&$this->resourceActive($c,$state,$start)){
            $from=max($start,$c['hosted']['start_height']??$c['funded_height']);$until=min($end,$c['end_height']);
            if($from<$until)$add(ResourceManifest::demands($c['resource_manifest'],$c['resource_capability']),$from,$until);
        }
        ksort($events,SORT_NUMERIC);$totals=[];
        foreach($events as $rows){usort($rows,fn($a,$b)=>$a['delta']<=>$b['delta']);foreach($rows as $row){$key=$row['key'];$old=$totals[$key]??0;
            if($row['delta']<0){if($old<-$row['delta'])throw new \InvalidArgumentException('Resource reservation underflow');$totals[$key]=$old+$row['delta'];}
            else{$total=Amount::add($old,$row['delta']);if($total>($capacities[$key]??0))throw new \InvalidArgumentException('Exclusive/shared resource capacity exhausted');$totals[$key]=$total;}
        }}
    }

    public function resourceTotals(State $state,int $height): array {
        $out=[];foreach($this->commitments as $c)if($this->resourceActive($c,$state,$height)&&($c['hosted']['start_height']??$c['funded_height'])<=$height){$id=$c['resource_capability']['provider_id'];
            foreach(ResourceManifest::demands($c['resource_manifest'],$c['resource_capability']) as $key=>$amount)$out[$id][$key]=Amount::add($out[$id][$key]??0,$amount);}
        foreach($out as &$totals)ksort($totals,SORT_STRING);unset($totals);ksort($out,SORT_STRING);return $out;
    }
    public function escrowTotal(): int {
        $sum=0;foreach($this->commitments as $c)if(!in_array($c['status'],['paid','refunded'],true))$sum=Amount::add($sum,Amount::add($c['payer_locked'],$c['subsidy_locked']));return $sum;
    }
    public function subsidyCommitted(): int {
        $sum=0;foreach($this->commitments as $c)if(!in_array($c['status'],['paid','refunded'],true))$sum=Amount::add($sum,$c['subsidy_locked']);return $sum;
    }
    public function requireFunding(ComputeAssign $a,int $height): void {
        $binding='compute:'.bin2hex($a->requestId).':'.$a->generation;$id=$this->bindings[$binding]??'';$c=$this->commitments[$id]??null;
        if(!$c || $c['status']!=='funded' || $c['funded_height']>=$height || $c['end_height']!==$a->deadline
            || $c['provider_key']!==bin2hex($a->providerPublicKey))throw new \InvalidArgumentException('Assignment requires previously finalized matching funding');
    }
    private function attest(array $body,array $approvals,array $p,string $domain): void {
        self::verifyApprovals($body,$approvals,$p,$domain);
    }
    public static function verifyApprovals(array $body,array $approvals,array $p,string $domain): void {
        $digest=ProtocolHash::hash($domain,CanonicalJson::encode($body));$seen=[];
        foreach($approvals as $a){$key=Policy::hex($a['public_key']??null);$sig=$a['signature']??'';
            if(isset($seen[$key]) || !in_array($key,$p['attestors'],true) || !is_string($sig) || !preg_match('/^[a-f0-9]{128}$/D',$sig)
                || !sodium_crypto_sign_verify_detached(hex2bin($sig),$digest,hex2bin($key)))throw new \InvalidArgumentException('Invalid usage attestation');
            $seen[$key]=true;}
        if(count($seen)<$p['threshold'])throw new \InvalidArgumentException('Usage attestation quorum missing');
    }
    /** Ledger owns reserve debits/credits; enclosing State clone makes all monetary changes atomic. */
    public function apply(State $state,Ledger $ledger,Envelope $tx,int $height,string $chain,string $genesis,?\Greenwood\Network\ResourcePolicyState $resourcePolicies=null): void {
        $a=CanonicalJson::decode($tx->getPayload());
        if(($a['version']??null)!==1 || ($a['genesis']??null)!==bin2hex($genesis))throw new \InvalidArgumentException('Wrong settlement version/genesis');
        $sender=bin2hex($tx->getSender());$action=$a['action']??null;
        if(in_array($action,['archive_v1','archive_v2'],true)){
            $rows=$a['records']??null;if(!is_array($rows)||!array_is_list($rows)||!$rows||count($rows)>16)throw new \InvalidArgumentException('Bounded archive batch required');
            $seen=[];foreach($rows as $row){$id=Policy::hex($row['commitment']??null);$digest=Policy::hex($row['record_hash']??null);$c=$this->commitments[$id]??null;
                if(isset($seen[$id])||!$c||!in_array($c['status'],['paid','refunded'],true)||$height<=$c['end_height']||count($this->archives)>=100000)throw new \InvalidArgumentException('Only terminal expired financial records can be archived');
                $actual=bin2hex(ProtocolHash::hash('GREENWOOD_SERVICE_ARCHIVE_V1',CanonicalJson::encode($c)));
                if($digest!==$actual)throw new \InvalidArgumentException('Archive record digest mismatch');
                // Full authenticated record remains in finalized block history; retain immutable replay protection.
                $this->archives[$id]=['id'=>$id,'program'=>$c['program'],'status'=>$c['status'],'payment'=>$c['payment'],'record_hash'=>$digest,'archived_height'=>$height];
                if($action==='archive_v2')$this->archives[$id]+=['archive_version'=>2,'adapter'=>$c['adapter'],'application_id'=>$c['hosted']['document']['assignment']['application_id']??null,'service'=>$c['hosted']['document']['assignment']['service']??null,'assignment_id'=>$c['hosted']===null?null:HostedServiceBinding::assignmentId($c['hosted']['document']),'assignment_generation'=>$c['assignment_generation'],'provider_id'=>$c['resource_capability']['provider_id']??bin2hex(\Greenwood\Compute\Protocol\ProviderId::fromPublicKey(hex2bin($c['provider_key']))->toBytes()),'start_height'=>$c['hosted']['start_height']??$c['funded_height'],'end_height'=>$c['end_height']];
                unset($this->commitments[$id]);$seen[$id]=true;
            }return;
        }
        if($action==='fund'){
            if(count($this->commitments)>=10000||count($this->quotes)>=100000)throw new \InvalidArgumentException('Settlement/live or replay-protection capacity reached');
            $pid=Policy::hex($a['program']??null);$program=$this->programs[$pid]??null;
            if(!$program)throw new \InvalidArgumentException('Unknown approved service program');$p=$program['policy'];
            \Greenwood\Network\AttestationTrust::authorize($p,$resourcePolicies??new \Greenwood\Network\ResourcePolicyState(),$height);
            if($height<$p['start_height'] || $height>$p['end_height'] || !in_array($sender,$p['beneficiaries'],true))throw new \InvalidArgumentException('Program expired or beneficiary unauthorized');
            $body=$a['quote']??'';$approvals=$a['quote_approvals']??[];
            if(!is_string($body)||!is_array($approvals))throw new \InvalidArgumentException('Invalid quote');
            $quote=new GruQuote([$p['provider_key']],1,$p['gru_scale'],525960);
            $q=$quote->verify($body,$approvals,$chain,$genesis,$height);
            \Greenwood\Network\AttestationTrust::acceptedQuote($p,$q);
            if($q['price_kind']!=='provider_gwd' || $q['payer']!==$sender || $q['service_id']!==$p['service_id'] || $q['catalog_hash']!==$p['catalog_hash'])throw new \InvalidArgumentException('Quote/program mismatch');
            $resourceMode=in_array($p['adapter'],['compute_resources_v2','hosted_resources_v2'],true);$resourceManifest=null;$resourceCapability=null;
            $providerId=bin2hex(\Greenwood\Compute\Protocol\ProviderId::fromPublicKey(hex2bin($p['provider_key']))->toBytes());
            $extension=$state->getComputeState()->resourceCapabilities()[$providerId]??null;
            if($resourceMode){
                $base=$state->getComputeState()->capabilities()[hex2bin($providerId)]??null;
                if(!$extension||!$extension['assets']||!$base||$base->status!==0||$extension['base_nonce']!==$base->nonce)throw new \InvalidArgumentException('Active finalized resource capability required');
                $q=ResourceQuote::verify($quote,$body,$approvals,$chain,$genesis,$height,$p['resource_catalog'],$extension,$p['subsidy_percent']);
                $resourceManifest=$q['resource_manifest'];$resourceCapability=$extension;
            }elseif($extension&&$extension['assets'])throw new \InvalidArgumentException('Resource-mode provider requires an explicit versioned resource manifest');
            $quoteId=bin2hex(ProtocolHash::hash('GREENWOOD_GRU_QUOTE_V1',$body));
            if(isset($this->quotes[$quoteId]))throw new \InvalidArgumentException('Accepted quote already consumed');
            $hosted=null;$end=$a['end_height']??null;
            if(!is_int($end)||$end<=$height||$end>$p['end_height'])throw new \InvalidArgumentException('Invalid service interval end');
            if(in_array($p['adapter'],['hosted_cpu_v1','hosted_resources_v2'],true)){
                $hosted=$a['hosted']??[];$assignment=HostedServiceBinding::validate($hosted,$p,$chain,$sender,$height,$end);
                HostedServiceBinding::authorize($state,$hosted,$p,$sender);
                if(($q['binding_hash']??null)!==HostedServiceBinding::hash($hosted+['end_height'=>$end]))throw new \InvalidArgumentException('Provider offer does not bind hosted assignment/interval');
                $requestId=hash('sha256',HostedServiceBinding::scope($hosted));$generation=$hosted['document']['version'];
                $binding='hosted:'.HostedServiceBinding::generation($hosted['document']).':'.$hosted['start_height'];
                foreach($this->commitments as $old){
                    if(!in_array($old['adapter']??'compute_cpu_v1',['hosted_cpu_v1','hosted_resources_v2'],true))continue;$oh=$old['hosted'];$oa=$oh['document']['assignment'];
                    $sameService=HostedServiceBinding::scope($oh)===HostedServiceBinding::scope($hosted);
                    $sameInstance=$old['provider_key']===$p['provider_key'] && $oa['instance_id']===$assignment['instance_id'];
                    if(($sameService||$sameInstance)&&$hosted['start_height']<$old['end_height']&&$oh['start_height']<$end)throw new \InvalidArgumentException('Overlapping hosted service/resource interval');
                }
                $maxPayment=Amount::integer($hosted['max_payment_units']);
                if(!$resourceMode){$maxCpuGru=Amount::mulDiv(Amount::integer($hosted['cpu_budget_ms']),Amount::integer($p['gru_per_cpu_ms_num']),Amount::integer($p['gru_per_cpu_ms_den']),true);
                if(Amount::integer($q['max_gru_units'])!==$maxCpuGru)throw new \InvalidArgumentException('Hosted quote must fully fund accepted CPU budget');}
                else foreach($resourceManifest['lines'] as $line)if($line['resource_id']==='compute.cpu.consumed'&&Amount::integer($line['maximum_quantity'])>Amount::integer($hosted['cpu_budget_ms']))throw new \InvalidArgumentException('Composite CPU allowance exceeds hosted CPU budget');
            }else{
                $requestId=Policy::hex($a['request_id']??null);$request=$state->getComputeState()->request(hex2bin($requestId));$generation=$a['generation']??null;
                if(!is_int($generation) || $generation!==($state->getComputeState()->assignment(hex2bin($requestId))?->generation??0)+1
                    || $end>$request->getDeadlineHeight() || $request->getRequester()!==$tx->getSender() || $state->getComputeState()->terminal(hex2bin($requestId)))throw new \InvalidArgumentException('Invalid request financial binding');
                foreach($this->commitments as $old)if(in_array($old['adapter']??'compute_cpu_v1',['compute_cpu_v1','compute_resources_v2'],true) && $old['request_id']===$requestId && !in_array($old['status'],['paid','refunded'],true))throw new \InvalidArgumentException('Request already financially committed');
                $binding='compute:'.$requestId.':'.$generation;$maxPayment=$request->getMaxPayment();
            }
            if(isset($this->bindings[$binding]))throw new \InvalidArgumentException('Financial generation/interval already used');
            $maxGru=Amount::integer($q['max_gru_units']);$cost=$quote->value($body,$approvals,$chain,$genesis,$height,$maxGru,$p['subsidy_percent']);
            if(Amount::integer($q['max_gwd_units'])>$maxPayment)throw new \InvalidArgumentException('Quote exceeds accepted max_payment');
            if($resourceMode){
                $cpuAsset=null;$ramSeen=false;
                foreach($resourceManifest['lines'] as $line){
                    if(in_array($line['resource_id'],['compute.cpu.reserved','compute.cpu.consumed'],true)){
                        if($cpuAsset!==null&&$cpuAsset!==$line['asset_id'])throw new \InvalidArgumentException('One runtime cannot reserve an ambiguous CPU pool');$cpuAsset=$line['asset_id'];
                        if($hosted===null&&Amount::integer($line['reserved_units'])!==$request->getCpuLimitMillis())throw new \InvalidArgumentException('Manifest must reserve requested CPU allocation');
                        if($hosted===null&&$line['resource_id']==='compute.cpu.consumed'&&Amount::integer($line['maximum_quantity'])>Amount::mulDiv($request->getCpuLimitMillis(),$request->getExecutionTimeoutMs(),1000,true))throw new \InvalidArgumentException('Manifest exceeds request CPU ceiling');
                    }
                    if($line['resource_id']==='memory.ram.reserved'){
                        if($ramSeen)throw new \InvalidArgumentException('One runtime requires one attributable RAM allocation');$ramSeen=true;
                        if($hosted===null&&Amount::integer($line['reserved_units'])!==Amount::mulDiv($request->getMemoryLimitBytes(),1,1048576,true))throw new \InvalidArgumentException('Manifest must reserve requested memory allocation');
                    }
                }
                if($cpuAsset===null||!$ramSeen)throw new \InvalidArgumentException('Runtime CPU and RAM allocation must be explicit, including bundled/free capacity');
            }
            if($resourceMode)$this->assertResourceCapacity($state,$resourceManifest,$resourceCapability,$hosted['start_height']??$height,$end);
            $group=$ledger->beneficiaryGroup($sender);$used=$program['beneficiaries'][$group]??0;
            if(Amount::add(Amount::add($program['spent'],$program['committed']),$cost['subsidy_units'])>Amount::integer($p['budget_units'])
                || Amount::add($used,$cost['subsidy_units'])>Amount::integer($p['beneficiary_budget_units'])
                || Amount::add(Amount::add($program['gru_spent'],$program['gru_committed']),$maxGru)>Amount::integer($p['max_gru_units']))throw new \InvalidArgumentException('Program/beneficiary/GRU budget exhausted');
            $balance=$state->getAccount($tx->getSender())->getBalance();if($balance<$cost['payer_units'])throw new \InvalidArgumentException('Insufficient customer escrow');
            $ledger->lockSubsidy($cost['subsidy_units'],$height);
            $state->debitEconomicAccount($tx->getSender(),$balance-$cost['payer_units']);
            $id=bin2hex(ProtocolHash::hash('GREENWOOD_SERVICE_COMMITMENT_V1',CanonicalJson::encode(['chain'=>$chain,'genesis'=>bin2hex($genesis),'acceptance'=>$a])));
            $this->quotes[$quoteId]=$id;$this->bindings[$binding]=$id;
            $this->commitments[$id]=['id'=>$id,'resource_manifest'=>$resourceManifest,'resource_capability'=>$resourceCapability,'usage_assignment_generation'=>$hosted===null?null:HostedServiceBinding::generation($hosted['document']),'adapter'=>$p['adapter'],'hosted'=>$hosted,'assignment_generation'=>$hosted===null?null:HostedServiceBinding::generation($hosted['document']),'program'=>$pid,'request_id'=>$requestId,'generation'=>$generation,'provider_key'=>$p['provider_key'],'provider'=>$q['provider'],'payer'=>$sender,'group'=>$group,
                'quote'=>$body,'quote_approvals'=>$approvals,'accepted_height'=>$height,'funded_height'=>$height,'end_height'=>$end,'evidence_deadline'=>Amount::add($end,self::EVIDENCE_BLOCKS),
                'payer_locked'=>$cost['payer_units'],'subsidy_locked'=>$cost['subsidy_units'],'max_gru'=>$maxGru,'status'=>'funded','claim'=>null,'dispute'=>null,'payment'=>null];
            $program['committed']=Amount::add($program['committed'],$cost['subsidy_units']);$program['gru_committed']=Amount::add($program['gru_committed'],$maxGru);
            $program['beneficiaries'][$group]=Amount::add($used,$cost['subsidy_units']);$this->programs[$pid]=$program;return;
        }
        $id=Policy::hex($a['commitment']??null);$c=$this->commitments[$id]??null;
        if(!$c || in_array($c['status'],['paid','refunded'],true))throw new \InvalidArgumentException('Unknown or terminal service commitment');$p=$this->programs[$c['program']]['policy'];
        if($action==='verify_usage'){
            \Greenwood\Network\AttestationTrust::authorize($p,$resourcePolicies??new \Greenwood\Network\ResourcePolicyState(),$height);
            if($c['status']!=='funded' || $height>$c['evidence_deadline'])throw new \InvalidArgumentException('Usage claim stale or duplicate');
            $resourceMode=in_array($c['adapter'],['compute_resources_v2','hosted_resources_v2'],true);
            if($resourceMode){
                $receiptCpu=null;
                if($c['adapter']==='compute_resources_v2'){
                    $r=$state->getComputeState()->receipt(hex2bin($c['request_id']));$assignment=$state->getComputeState()->assignment(hex2bin($c['request_id']));
                    if($r===null||$assignment===null||$r->generation!==$c['generation'])throw new \InvalidArgumentException('Finalized resource execution receipt required');
                    $receiptCpu=$r->body()->getCpuTimeMs();$c['usage_assignment_generation']=bin2hex(ProtocolHash::hash('GREENWOOD_RESOURCE_ASSIGNMENT_V1',$assignment->toBytes()));
                }elseif($height<=$c['end_height'])throw new \InvalidArgumentException('Resource interval has not ended');
                $e=$a['evidence']??[];if(!is_array($e))throw new \InvalidArgumentException('Malformed resource evidence');$quantities=ResourceUsageEvidence::quantities($e,$c,$p,$chain,bin2hex($genesis),$receiptCpu);
                $this->attest($e,$a['approvals']??[],$p,'GREENWOOD_RESOURCE_USAGE_V1');$gru=ResourceManifest::value($c['resource_manifest'],$quantities)['gru_units'];
            }else{
            $e=$a['evidence']??[];
            if(($c['adapter']??'compute_cpu_v1')==='hosted_cpu_v1'){
                if($height<=$c['end_height'])throw new \InvalidArgumentException('Hosted interval has not ended');
                $cpu=Amount::integer($e['cpu_ms']??null);
                if($cpu>Amount::integer($c['hosted']['cpu_budget_ms']))throw new \InvalidArgumentException('Hosted CPU budget exceeded');
                $expected=['chain'=>$chain,'genesis'=>bin2hex($genesis),'commitment'=>$id,'catalog_hash'=>$p['catalog_hash'],
                    'assignment_generation'=>$c['assignment_generation'],'start_height'=>$c['hosted']['start_height'],'end_height'=>$c['end_height'],
                    'cpu_ms'=>(string)$cpu,'measurement_hash'=>Policy::hex($e['measurement_hash']??null),'runtime_lineage_hash'=>Policy::hex($e['runtime_lineage_hash']??null)];
            }else{
                $r=$state->getComputeState()->receipt(hex2bin($c['request_id']));
                if($r===null || $r->generation!==$c['generation'])throw new \InvalidArgumentException('Finalized matching execution receipt required');
                $request=$state->getComputeState()->request(hex2bin($c['request_id']));$cpu=$r->body()->getCpuTimeMs();
                $maxCpu=Amount::mulDiv($request->getCpuLimitMillis(),$request->getExecutionTimeoutMs(),1000,true);
                if($cpu>$maxCpu)throw new \InvalidArgumentException('Reported CPU exceeds accepted resource budget');
                $expected=['chain'=>$chain,'genesis'=>bin2hex($genesis),'commitment'=>$id,'receipt_id'=>bin2hex($r->getId()),'catalog_hash'=>$p['catalog_hash'],'cpu_ms'=>(string)$cpu];
            }
            if(CanonicalJson::encode($e)!==CanonicalJson::encode($expected))throw new \InvalidArgumentException('Evidence does not bind authorized service consumption');
            $this->attest($e,$a['approvals']??[],$p,'GREENWOOD_USAGE_ATTEST_V1');
            $gru=Amount::mulDiv($cpu,Amount::integer($p['gru_per_cpu_ms_num']),Amount::integer($p['gru_per_cpu_ms_den']),true);
            }
            $quote=new GruQuote([$p['provider_key']],1,$p['gru_scale'],525960);
            // Verify at acceptance height: quote expiry limits acceptance, not later payment for accepted service.
            $cost=$quote->value($c['quote'],$c['quote_approvals'],$chain,$genesis,$c['accepted_height'],$gru,$p['subsidy_percent']);
            $c['claim']=['evidence'=>$e,'gru'=>$gru,'cost'=>$cost,'ready'=>Amount::add($height,self::CHALLENGE_BLOCKS)];$c['status']='verified';
        }elseif($action==='dispute'){
            if($c['status']!=='verified' || $height>=$c['claim']['ready'] || ($sender!==$c['payer'] && !in_array(bin2hex($tx->getSenderPublicKey()),$p['attestors'],true)))throw new \InvalidArgumentException('Unauthorized or late dispute');
            $c['dispute']=['reason'=>Policy::hex($a['reason_hash']??null),'height'=>$height];$c['status']='disputed';
        }elseif($action==='resolve'){
            \Greenwood\Network\AttestationTrust::authorize($p,$resourcePolicies??new \Greenwood\Network\ResourcePolicyState(),$height);
            if($c['status']!=='disputed' || $height>Amount::add($c['dispute']['height'],self::DISPUTE_BLOCKS) || !is_bool($a['pay']??null))throw new \InvalidArgumentException('Invalid dispute resolution');
            $resolution=['chain'=>$chain,'genesis'=>bin2hex($genesis),'commitment'=>$id,'evidence'=>$c['claim']['evidence'],'dispute'=>$c['dispute'],'pay'=>$a['pay']];
            $this->attest($resolution,$a['approvals']??[],$p,'GREENWOOD_USAGE_RESOLVE_V1');
            if($a['pay']){$c['status']='resolved';$c['claim']['ready']=Amount::add($height,self::CHALLENGE_BLOCKS);}else{$this->close($state,$ledger,$c,$height,false);}
        }elseif($action==='settle'){
            if(!in_array($c['status'],['verified','resolved'],true) || $height<$c['claim']['ready'])throw new \InvalidArgumentException('Usage not payable/challenge delay active');
            $this->close($state,$ledger,$c,$height,true);
        }elseif($action==='refund'){
            if(!(($c['status']==='funded' && $height>$c['evidence_deadline']) || ($c['status']==='disputed' && $height>Amount::add($c['dispute']['height'],self::DISPUTE_BLOCKS))))throw new \InvalidArgumentException('Refund not eligible');
            $this->close($state,$ledger,$c,$height,false);
        }else throw new \InvalidArgumentException('Unsupported settlement action');
        $this->commitments[$id]=$c;
    }
    private function close(State $state,Ledger $ledger,array &$c,int $height,bool $pay): void {
        $cost=$pay?$c['claim']['cost']:['payer_units'=>0,'subsidy_units'=>0,'provider_units'=>0];
        $payerRefund=$c['payer_locked']-$cost['payer_units'];$subsidyRefund=$c['subsidy_locked']-$cost['subsidy_units'];
        if($payerRefund<0||$subsidyRefund<0)throw new \InvalidArgumentException('Escrow charge exceeds funding');
        foreach([[$c['payer'],$payerRefund],[$c['provider'],$cost['provider_units']]] as [$to,$amount])
            if($amount>0)$state->creditEconomicAccount(hex2bin($to),Amount::add($state->getAccount(hex2bin($to))->getBalance(),$amount));
        $ledger->releaseSubsidy($subsidyRefund,$cost['subsidy_units'],$c['group'],$height);
        $program=$this->programs[$c['program']];$program['committed']-=$c['subsidy_locked'];$program['spent']=Amount::add($program['spent'],$cost['subsidy_units']);
        $program['gru_committed']-=$c['max_gru'];$program['gru_spent']=Amount::add($program['gru_spent'],$pay?$c['claim']['gru']:0);
        $program['beneficiaries'][$c['group']]-=$subsidyRefund;
        $this->programs[$c['program']]=$program;
        $c['status']=$pay?'paid':'refunded';$c['payment']=['height'=>$height,'provider_units'=>$cost['provider_units'],'payer_units'=>$cost['payer_units'],'subsidy_units'=>$cost['subsidy_units'],'payer_refund'=>$payerRefund,'subsidy_released'=>$subsidyRefund];
    }
}
