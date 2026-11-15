<?php
declare(strict_types=1);
namespace Greenwood\Network;
use Greenwood\Economics\Policy;

/** Additional, opt-in restrictions for new programs. Legacy encodings/commitments are unchanged. */
final class AttestationTrust
{
    public static function programHash(array $p): string{return bin2hex(\Greenwood\Crypto\ProtocolHash::hash('GREENWOOD_SERVICE_PROGRAM_POLICY_V1',\Greenwood\Economics\CanonicalJson::encode($p)));}
    public static function acceptedQuote(array $p,array $quote): void
    {
        if(isset($p['trust_profile'])&&(($quote['trust_profile']??null)!==$p['trust_profile']||($quote['program_policy_hash']??null)!==self::programHash($p)))throw new \InvalidArgumentException('Provider/customer offer must disclose and bind exact accepted trust policy');
    }
    public static function shape(array $p): void
    {
        if(!isset($p['trust_profile']))return;
        if(!in_array($p['trust_profile'],['INDEPENDENT_ATTESTED','BOOTSTRAP_OPERATOR_ATTESTED'],true))throw new \InvalidArgumentException('Unsupported native funding trust profile');
        if($p['trust_profile']==='BOOTSTRAP_OPERATOR_ATTESTED'&&($p['subsidy_percent']??null)!==0)throw new \InvalidArgumentException('Bootstrap operator services cannot draw subsidies');
        foreach(['evidence_profile','provider_operator','meter_operator'] as $k)Policy::hex($p[$k]??null);
        if(!is_array($p['attestors']??null)||!is_array($p['attestor_operators']??null)||count($p['attestor_operators'])!==count($p['attestors']))throw new \InvalidArgumentException('Every attestor requires a qualified operator binding');
        foreach($p['attestors'] as $key)Policy::hex($p['attestor_operators'][$key]??null);
    }
    public static function authorize(array $p,ResourcePolicyState $registry,int $height): void
    {
        self::shape($p);if(!isset($p['trust_profile']))return;
        if($p['trust_profile']==='BOOTSTRAP_OPERATOR_ATTESTED'){
            $eligible=false;
            foreach($registry->current('epoch',$height) as $epoch){
                if($epoch['stage']!=='E0_BOOTSTRAP')throw new \InvalidArgumentException('Active stronger epoch cannot downgrade to bootstrap');
                if($epoch['evidence_profile']===$p['evidence_profile']&&$epoch['approved_height']<$height)$eligible=true;
            }
            if(!$eligible)throw new \InvalidArgumentException('Explicit finalized E0 policy required');
            $operators=$registry->current('operator',$height);
            $get=static function(string $id,string $role)use($operators,$height):array{
                $r=$operators[$id]??null;
                if(!$r||$r['approved_height']>=$height||!in_array($role,$r['roles'],true))throw new \InvalidArgumentException('Finalized bootstrap role missing');
                return $r;
            };
            $provider=$get($p['provider_operator'],'PROVIDER');
            if($provider['controller_key']!==$p['provider_key'])throw new \InvalidArgumentException('Bootstrap provider/key mismatch');
            $meter=$get($p['meter_operator'],'METERING');
            if($meter['domain']!==$provider['domain'])throw new \InvalidArgumentException('Bootstrap source must disclose founder control');
            foreach($p['attestors'] as $key){$r=$get($p['attestor_operators'][$key],'ATTESTOR');
                if($r['controller_key']!==$key||$r['domain']!==$provider['domain'])throw new \InvalidArgumentException('Bootstrap attestor/control mismatch');
            }
            return;
        }
        $eligible=false;foreach($registry->current('epoch',$height) as $epoch)if($epoch['stage']==='E2_VERIFICATION_EXPANSION'&&$epoch['evidence_profile']===$p['evidence_profile']&&$registry->qualifies($epoch,$height))$eligible=true;
        if(!$eligible)throw new \InvalidArgumentException('Finalized E2 qualification unavailable; no bootstrap downgrade');
        $operators=$registry->current('operator',$height);
        $get=static function(string $id,string $role)use($operators,$p,$height):array{
            $r=$operators[$id]??null;
            if(!$r||!$r['independent']||$r['approved_height']>=$height||!in_array($role,$r['roles'],true)||!in_array($p['evidence_profile'],$r['qualified_profiles'],true))throw new \InvalidArgumentException('Finalized independently qualified role/source missing');
            return $r;
        };
        $provider=$get($p['provider_operator'],'PROVIDER');if($provider['controller_key']!==$p['provider_key'])throw new \InvalidArgumentException('Provider operator/key mismatch');
        $meter=$get($p['meter_operator'],'METERING');if($meter['domain']===$provider['domain']||$meter['controller_key']===$p['provider_key'])throw new \InvalidArgumentException('Provider-controlled source is not independent measurement');
        $domains=[];foreach($p['attestors'] as $key){$r=$get($p['attestor_operators'][$key],'ATTESTOR');
            if($r['controller_key']!==$key||$r['domain']===$provider['domain']||isset($domains[$r['domain']]))throw new \InvalidArgumentException('Duplicate/affiliated attestor control');
            $domains[$r['domain']]=true;
        }
        if(count($domains)<$p['threshold'])throw new \InvalidArgumentException('Independent domain quorum missing');
    }
}
