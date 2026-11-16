<?php
declare(strict_types=1);
namespace Greenwood\Economics;

/** Explicit genesis opt-in. No council, threshold or timelock is supplied by default. */
final class RecoveryPolicy
{
    public static function validate(mixed $r,array $signers,array $guardians): void
    {
        if($r===null)return;
        if(!is_array($r))throw new \InvalidArgumentException('Explicit recovery policy required');
        if($r===['enabled'=>false])return;
        $fields=array_keys($r);sort($fields);
        if($fields!==['enabled','keys','proposal_lifetime_blocks','threshold','timelock_blocks','version','veto_threshold']
            ||$r['enabled']!==true||$r['version']!==1)throw new \InvalidArgumentException('Unsupported recovery policy');
        if(!is_array($r['keys'])||!array_is_list($r['keys'])||count($r['keys'])<3||count($r['keys'])>9||count(array_unique($r['keys']))!==count($r['keys']))throw new \InvalidArgumentException('Distinct recovery keys required');
        foreach($r['keys'] as $key)Policy::hex($key);
        if(array_intersect($r['keys'],array_merge($signers,$guardians)))throw new \InvalidArgumentException('Recovery and ordinary governance keys must be separate');
        foreach(['threshold','timelock_blocks','proposal_lifetime_blocks','veto_threshold'] as $field)if(!is_int($r[$field]))throw new \InvalidArgumentException('Explicit recovery integer required');
        if($r['threshold']<=intdiv(count($r['keys']),2)||$r['threshold']>count($r['keys'])||$r['veto_threshold']<1||$r['veto_threshold']>5
            ||$r['timelock_blocks']<1||$r['proposal_lifetime_blocks']<=$r['timelock_blocks']||$r['proposal_lifetime_blocks']>525960)
            throw new \InvalidArgumentException('Invalid recovery quorum or bounded notice window');
    }
    public static function enabled(array $policy): bool{return ($policy['recovery_policy']['enabled']??false)===true;}
}
