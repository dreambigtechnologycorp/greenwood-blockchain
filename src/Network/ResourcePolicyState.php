<?php
declare(strict_types=1);
namespace Greenwood\Network;

use Greenwood\Economics\{CanonicalJson,Policy};
use Greenwood\Crypto\ProtocolHash;

/** Governance facts, not discovery, a scheduler, or a financial ledger.
 * All writes occur through Ledger's existing four-signer policy timelock.
 * Reviewed domain assertions remain an external governance trust assumption.
 */
final class ResourcePolicyState
{
    public const ROLES=['PROVIDER','METERING','ATTESTOR','SYSTEM_SERVICE','VALIDATOR','GOVERNANCE_SIGNER'];
    public const SERVICES=['greenwood-metering','greenwood-attestor','greenwood-metering-coordinator'];
    private array $records=[];
    public function snapshot(): array{return $this->records;}
    public static function restore(array $records): self{$v=new self();$v->records=$records;return $v;}
    public static function hash(array $record): string{return bin2hex(ProtocolHash::hash('GREENWOOD_RESOURCE_POLICY_V1',CanonicalJson::encode($record)));}
    public static function validate(array $r): array
    {
        if(strlen(CanonicalJson::encode($r))>32768||($r['version']??null)!==1||!in_array($r['kind']??null,['operator','service','epoch'],true))throw new \InvalidArgumentException('Invalid resource network policy');
        Policy::hex($r['id']??null);
        foreach(['revision','activation_height','expires_height'] as $k)if(!is_int($r[$k]??null)||$r[$k]<1)throw new \InvalidArgumentException('Invalid policy height/revision');
        if($r['expires_height']<=$r['activation_height'])throw new \InvalidArgumentException('Empty policy interval');
        if(!in_array($r['status']??null,['active','paused','revoked'],true))throw new \InvalidArgumentException('Invalid policy status');
        if($r['kind']==='operator'){
            foreach(['controller_key','domain','independence_evidence_hash','reviewer_key'] as $k)Policy::hex($r[$k]??null);
            if(!is_array($r['roles']??null)||!$r['roles']||!array_is_list($r['roles'])||count(array_unique($r['roles']))!==count($r['roles'])||array_diff($r['roles'],self::ROLES))throw new \InvalidArgumentException('Invalid operator roles');
            if(!is_array($r['qualified_profiles']??null)||!array_is_list($r['qualified_profiles'])||count($r['qualified_profiles'])>32)throw new \InvalidArgumentException('Bounded evidence profiles required');
            foreach($r['qualified_profiles'] as $hash)Policy::hex($hash);
            if(!is_bool($r['independent']??null))throw new \InvalidArgumentException('Explicit domain review required');
            if($r['independent']&&$r['independence_evidence_hash']===str_repeat('0',64))throw new \InvalidArgumentException('Independence review missing');
            if($r['independent']){
                Policy::hex($r['participation_evidence_hash']??null);
                if($r['participation_evidence_hash']===str_repeat('0',64)||!is_int($r['participation_start_height']??null)||!is_int($r['participation_end_height']??null)||$r['participation_start_height']<1||$r['participation_end_height']<$r['participation_start_height'])throw new \InvalidArgumentException('Reviewed sustained participation evidence required');
            }
        }elseif($r['kind']==='service'){
            if(!in_array($r['service_id']??null,self::SERVICES,true)||!preg_match('/^sha256:[a-f0-9]{64}$/D',$r['image_digest']??'')
                ||!is_string($r['artifact_source']??null)||!str_ends_with($r['artifact_source'],'@'.$r['image_digest'])||!str_contains($r['artifact_source'],'/'))throw new \InvalidArgumentException('Reviewed immutable service artifact required');
            foreach(['config_schema_hash','budget_policy_ref'] as $k)Policy::hex($r[$k]??null);
            $role=match($r['service_id']){'greenwood-metering'=>'METERING','greenwood-attestor'=>'ATTESTOR','greenwood-metering-coordinator'=>'SYSTEM_SERVICE'};
            if(($r['approved_role']??null)!==$role)throw new \InvalidArgumentException('Service class requires its exact approved operator role');
            if(!in_array($r['approved_role']??null,['METERING','ATTESTOR','SYSTEM_SERVICE'],true)||($r['user']??null)!=='65532:65532'||($r['host_network']??null)!==false||!in_array($r['host_permissions']??null,[[],['private_journal_rw']],true))throw new \InvalidArgumentException('Network services cannot self-authorize host privileges');
            if(!is_array($r['command']??null)||!array_is_list($r['command'])||!$r['command']||count($r['command'])>32)throw new \InvalidArgumentException('Bounded command required');
            foreach($r['command'] as $s)if(!is_string($s)||$s===''||strlen($s)>1024||str_contains($s,"\0"))throw new \InvalidArgumentException('Invalid service command');
            foreach(['minimum_eligible_replicas','grace_blocks','rollback_revision'] as $k)if(!is_int($r[$k]??null)||$r[$k]<0)throw new \InvalidArgumentException('Invalid service policy bounds');
            if($r['rollback_revision']>=$r['revision']&&$r['rollback_revision']!==0)throw new \InvalidArgumentException('Rollback must name an earlier approved revision');
            if(isset($r['execution_profile'])){
                $profile=$r['execution_profile'];if(!is_array($profile)||count($profile)!==3||array_diff(array_keys($profile),['cpu_millicores','memory_bytes','pids']))throw new \InvalidArgumentException('Exact system service resource bounds required');
                foreach($profile as $value)if(!is_int($value)||$value<1)throw new \InvalidArgumentException('Positive integer execution bounds required');
                if($profile['cpu_millicores']>intdiv(PHP_INT_MAX,100))throw new \InvalidArgumentException('System service CPU quota overflow');
            }
        }else{
            if(!in_array($r['stage']??null,['E0_BOOTSTRAP','E1_RESOURCE_EXPANSION','E2_VERIFICATION_EXPANSION'],true))throw new \InvalidArgumentException('Dynamic consensus membership/E3 is not implemented');
            foreach(['sustained_blocks','notice_blocks','provider_domains','attestor_domains','participation_max_age_blocks'] as $k)if(!is_int($r[$k]??null)||$r[$k]<1||$r[$k]>525960)throw new \InvalidArgumentException('Explicit ratified epoch parameters required');
            Policy::hex($r['evidence_profile']??null);
            if($r['stage']!=='E0_BOOTSTRAP'&&$r['provider_domains']<2)throw new \InvalidArgumentException('Resource expansion requires independent domains');
            if($r['stage']==='E2_VERIFICATION_EXPANSION'&&$r['attestor_domains']<2)throw new \InvalidArgumentException('Independent verification requires distinct domains');
        }
        return $r;
    }
    public function install(array $r,int $height,array $signers): void
    {
        self::validate($r);$kind=$r['kind'];$id=$r['id'];$history=$this->records[$kind][$id]??[];$old=$history?end($history):null;
        if(count($history)>=128||count($this->records[$kind]??[])>=1000&&!$history||$r['revision']!==($old['revision']??0)+1||$r['activation_height']<$height)throw new \InvalidArgumentException('Stale revision, retroactive activation or policy capacity');
        if($old&&$old['status']==='revoked')throw new \InvalidArgumentException('Revoked policy identity cannot be resurrected');
        if($old&&$r['activation_height']<=$old['activation_height'])throw new \InvalidArgumentException('Policy effective heights must increase');
        if($kind==='operator'){
            if(!in_array($r['reviewer_key'],$signers,true))throw new \InvalidArgumentException('Domain reviewer must be a current governance signer');
            if($old&&($old['domain']!==$r['domain']||$old['controller_key']!==$r['controller_key']))throw new \InvalidArgumentException('Operator identity/domain cannot be rewritten');
            if($r['independent']&&$r['participation_end_height']>=$height)throw new \InvalidArgumentException('Future participation cannot be qualified');
            foreach($this->records['operator']??[] as $versions)foreach($versions as $known)if($known['controller_key']===$r['controller_key']&&$known['domain']!==$r['domain'])throw new \InvalidArgumentException('One controller key cannot represent independent administrative domains');
        }
        if($kind==='service'&&$r['rollback_revision']>0){
            $target=null;foreach($history as $candidate)if($candidate['revision']===$r['rollback_revision'])$target=$candidate;
            if(!$target||$target['status']!=='active')throw new \InvalidArgumentException('Rollback target is not an approved active revision');
            foreach(['service_id','image_digest','artifact_source','config_schema_hash','command','approved_role','user','host_network','host_permissions','execution_profile'] as $field)if(CanonicalJson::encode([$target[$field]??null])!==CanonicalJson::encode([$r[$field]??null]))throw new \InvalidArgumentException('Rollback cannot authorize unrelated code/configuration/privileges');
        }
        if($kind==='epoch'){
            if($r['activation_height']<$height+$r['notice_blocks'])throw new \InvalidArgumentException('Epoch notice period missing');
            $rank=['E0_BOOTSTRAP'=>0,'E1_RESOURCE_EXPANSION'=>1,'E2_VERIFICATION_EXPANSION'=>2];
            foreach($this->records['epoch']??[] as $versions)foreach($versions as $e)if($rank[$r['stage']]<$rank[$e['stage']])throw new \InvalidArgumentException('Epoch cannot weaken already approved independence');
            if(!$this->qualifies($r,$height))throw new \InvalidArgumentException('Sustained finalized role qualification missing');
        }
        $r['approved_height']=$height;$this->records[$kind][$id][]=$r;
    }
    public function current(string $kind,int $height): array
    {
        $out=[];foreach($this->records[$kind]??[] as $id=>$history){$selected=null;foreach($history as $r)if($r['activation_height']<=$height)$selected=$r;
            if($selected&&$selected['status']==='active'&&$selected['expires_height']>$height)$out[$id]=$selected;}
        ksort($out,SORT_STRING);return $out;
    }
    public function domains(string $role,int $height,int $window,string $profile,int $maxAge=525960): array
    {
        $out=[];foreach($this->current('operator',$height) as $id=>$r){
            if(!$r['independent']||!in_array($role,$r['roles'],true)||!in_array($profile,$r['qualified_profiles'],true)||$r['activation_height']>$height-$window||$r['approved_height']>$height-$window)continue;
            if($r['participation_end_height']-$r['participation_start_height']+1<$window||$r['participation_end_height']<$height-$maxAge)continue;
            // A revised record must itself survive the full window: registrations and reactivations cannot borrow uptime.
            $out[$r['domain']][]=$id;
        }ksort($out,SORT_STRING);return $out;
    }
    public function qualifies(array $epoch,int $height): bool
    {
        if($epoch['stage']==='E0_BOOTSTRAP')return true;
        $providers=$this->domains('PROVIDER',$height,$epoch['sustained_blocks'],$epoch['evidence_profile'],$epoch['participation_max_age_blocks']);
        if(count($providers)<$epoch['provider_domains'])return false;
        if($epoch['stage']==='E1_RESOURCE_EXPANSION')return true;
        $witnesses=$this->domains('ATTESTOR',$height,$epoch['sustained_blocks'],$epoch['evidence_profile'],$epoch['participation_max_age_blocks']);
        // Conservative: witnesses affiliated with any currently qualifying provider cannot count.
        $witnesses=array_diff_key($witnesses,$providers);
        $meters=array_diff_key($this->domains('METERING',$height,$epoch['sustained_blocks'],$epoch['evidence_profile'],$epoch['participation_max_age_blocks']),$providers);
        return count($witnesses)>=$epoch['attestor_domains']&&count($meters)>0;
    }
}
