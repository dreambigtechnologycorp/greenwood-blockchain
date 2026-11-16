<?php
declare(strict_types=1);
namespace Greenwood\Genesis;

use Greenwood\Crypto\Ed25519KeyPair;
use Greenwood\Economics\Policy;

/** Offline public candidate assembly. Never provisions keys or authorizes activation. */
final class ProductionPreparation
{
    public static function prepare(array $policy, array $topology): array
    {
        $result=['version'=>1,'activation_authorized'=>false,'formal_ratification_verified'=>false,
            'status'=>'BLOCKED','blockers'=>[],'candidate'=>null];
        try {
            $allowed=['version','profile','service_trust_policy','block_seconds','decimals','burn_percent','operator_percent',
                'signers','guardians','pause_blocks','allocations','limits','large_threshold','operations_balance',
                'operations_payment','operations_window','bootstrap_units','operations_account','clock','window_blocks',
                'emission_distribution','exhaustion','failed_transaction_fee','fees','recipients','recovery_policy'];
            if(array_diff(array_keys($policy),$allowed))throw new \InvalidArgumentException('Unknown public economic policy fields');
            Policy::validateProduction($policy);
        }
        catch (\InvalidArgumentException $e) { $result['blockers'][]='Economic policy: '.$e->getMessage(); }
        try { self::topology($topology); }
        catch (\InvalidArgumentException $e) { $result['blockers'][]='Public topology: '.$e->getMessage(); }
        if($result['blockers'])return $result;
        $g=new Genesis($topology['chain_id']);
        foreach($topology['validators'] as $id=>$validator)$g->addValidator($id,new Ed25519KeyPair(hex2bin($validator['public_key']),''));
        $g->configureEconomics($policy);
        $candidate=json_decode($g->toJson(),true,32,JSON_THROW_ON_ERROR);
        foreach($candidate['validators'] as $id=>&$validator)$validator['endpoint']=$topology['validators'][$id]['endpoint'];
        unset($validator);
        $result['status']='CANDIDATE_ONLY';$result['candidate']=$candidate;
        $result['provider_public_keys']=$topology['providers'];
        $result['genesis_hash']=bin2hex($g->getGenesisHash());
        $result['initial_state_root']=bin2hex($g->initialState()->getStateRoot());
        $result['activation_blockers']=['Formal Institute ratification and custody verification',
            'Approved recovery policy and qualified release artifacts',
            'Verified physical recovery access and explicit installation authorization',
            'Physical production acceptance'];
        return $result;
    }

    private static function topology(array $t): void
    {
        $fields=array_keys($t);sort($fields);
        if($fields!==['chain_id','previous_chain_id','providers','retired_public_keys','validators','version']||($t['version']??null)!==1)
            throw new \InvalidArgumentException('Exact versioned public topology fields required');
        foreach(['chain_id','previous_chain_id'] as $field)if(!is_string($t[$field])||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$t[$field]))
            throw new \InvalidArgumentException('Explicit fresh and previous chain identities required');
        if($t['chain_id']===$t['previous_chain_id']||$t['chain_id']===Genesis::DEFAULT_CHAIN_ID)
            throw new \InvalidArgumentException('Fresh chain identity required');
        if(!is_array($t['retired_public_keys'])||!array_is_list($t['retired_public_keys'])||!$t['retired_public_keys']||count($t['retired_public_keys'])>128)
            throw new \InvalidArgumentException('Retired public identity list required');
        foreach($t['retired_public_keys'] as $key)Policy::hex($key);
        foreach(['validators','providers'] as $field){
            if(!is_array($t[$field]))throw new \InvalidArgumentException('Three explicit validator/provider identities required');
            $ids=array_keys($t[$field]);sort($ids);
            if($ids!==['A','B','C'])throw new \InvalidArgumentException('Fixed A/B/C topology required');
        }
        $keys=[];$endpoints=[];
        foreach($t['validators'] as $validator){
            if(!is_array($validator))throw new \InvalidArgumentException('Public validator fields required');
            $fields=array_keys($validator);sort($fields);
            if($fields!==['endpoint','public_key'])throw new \InvalidArgumentException('Only public validator fields accepted');
            $keys[]=Policy::hex($validator['public_key']);
            $endpoint=$validator['endpoint'];
            if(!is_string($endpoint)||!preg_match('~^tcp://([A-Za-z0-9][A-Za-z0-9.-]*):([0-9]{1,5})$~D',$endpoint,$matches)
                ||(int)$matches[2]<1||(int)$matches[2]>65535)throw new \InvalidArgumentException('Explicit TCP consensus endpoint required');
            $endpoints[]=$endpoint;
        }
        foreach($t['providers'] as $key)$keys[]=Policy::hex($key);
        if(count(array_unique($keys))!==6||array_intersect($keys,$t['retired_public_keys']))
            throw new \InvalidArgumentException('Six distinct newly provisioned public identities required');
        if(count(array_unique($endpoints))!==3)throw new \InvalidArgumentException('Distinct consensus endpoints required');
    }
}
