<?php
declare(strict_types=1);
namespace Greenwood\Economics;

final class Policy
{
    /** Selected fresh-production allocation; historical genesis policies remain replayable. */
    public const PRODUCTION_ALLOCATION = [
        'security_emissions'=>'150000000000000', 'grants'=>'250000000000000',
        'subsidies'=>'300000000000000', 'institute_treasury'=>'250000000000000',
        'working_operations'=>'50000000000000',
    ];
    public const RESERVES = ['grants', 'institute_treasury', 'security_emissions', 'subsidies', 'working_operations'];
    public static function validateProduction(array $p): array
    {
        $p=self::validate($p);
        if(!array_key_exists('recovery_policy',$p)||$p['recovery_policy']===null)throw new \InvalidArgumentException('Explicit production recovery decision required');
        if ($p['profile']!=='production' || ($p['service_trust_policy']??null)!=='explicit_profiles_v1'
            || CanonicalJson::encode($p['allocations'])!==CanonicalJson::encode(self::PRODUCTION_ALLOCATION))
            throw new \InvalidArgumentException('Fresh production requires selected allocation and explicit service trust profiles');
        return $p;
    }
    public static function hex(mixed $v): string
    {
        if (!is_string($v) || !preg_match('/^[a-f0-9]{64}$/D', $v)) throw new \InvalidArgumentException('Invalid public identity');
        return $v;
    }
    public static function validate(array $p): array
    {
        // Economic parameters are committed by genesis, never selected from node-local defaults.
        if (($p['version'] ?? null) !== 1 || !in_array($p['profile'] ?? null,['production','qualification'],true)
            || ($p['block_seconds'] ?? null) !== 60 || ($p['decimals'] ?? null) !== 6
            || ($p['burn_percent'] ?? null) !== 0 || ($p['operator_percent'] ?? null) !== 100) throw new \InvalidArgumentException('Unsupported economic policy');
        if (array_key_exists('service_trust_policy',$p) && $p['service_trust_policy'] !== 'explicit_profiles_v1') throw new \InvalidArgumentException('Unsupported service trust policy');
        $keys = $p['signers'] ?? [];
        if (!is_array($keys) || count($keys) !== 5 || count(array_unique($keys)) !== 5) throw new \InvalidArgumentException('Five independent signer keys required');
        foreach ($keys as $key) self::hex($key);
        $guardians = $p['guardians'] ?? [];
        if (!is_array($guardians) || count($guardians) < 1 || count($guardians) > 5 || count(array_unique($guardians)) !== count($guardians)) throw new \InvalidArgumentException('Explicit guardian keys required');
        foreach ($guardians as $key) self::hex($key);
        RecoveryPolicy::validate($p['recovery_policy']??null,$keys,$guardians);
        if (!is_int($p['pause_blocks'] ?? null) || $p['pause_blocks'] < 1 || $p['pause_blocks'] > 20160) throw new \InvalidArgumentException('Invalid bounded guardian pause');
        $allocation = $p['allocations'] ?? []; $names = array_keys($allocation); sort($names, SORT_STRING);
        if ($names !== self::RESERVES) throw new \InvalidArgumentException('Required reserve allocations missing');
        $sum = 0;
        foreach ($allocation as $value) $sum = Amount::add($sum, Amount::integer($value));
        if ($sum !== Amount::ISSUANCE) throw new \InvalidArgumentException('Genesis issuance mismatch');
        foreach (['grants','subsidies','institute_treasury','working_operations'] as $name) {
            $limit = Amount::integer($p['limits'][$name] ?? null);
            if ($limit < 1 || $limit > Amount::integer($allocation[$name])) throw new \InvalidArgumentException('Invalid reserve spending limit');
        }
        foreach (['large_threshold','operations_balance','operations_payment','operations_window'] as $name) if (Amount::integer($p[$name] ?? null) < 1) throw new \InvalidArgumentException('Invalid spending limit');
        foreach(['operations_balance'=>100000,'operations_payment'=>5000,'operations_window'=>100000] as $name=>$gwd)if(Amount::integer($p[$name])>$gwd*Amount::SCALE)throw new \InvalidArgumentException('Operational exposure ceiling exceeded');
        if (Amount::integer($p['large_threshold']) !== 100000 * Amount::SCALE) throw new \InvalidArgumentException('Large payment floor is immutable');
        if (($p['clock'] ?? null) !== 'finalized_height' || ($p['window_blocks'] ?? null) !== 43200) throw new \InvalidArgumentException('Explicit 30-day nominal height window required');
        if (($p['emission_distribution'] ?? null) !== 'equal_genesis_validators'
            || ($p['exhaustion'] ?? null) !== 'partial_then_stop') throw new \InvalidArgumentException('Explicit emission distribution/exhaustion policy required');
        if (($p['failed_transaction_fee'] ?? null) !== 'reject_without_charge') throw new \InvalidArgumentException('Explicit failed-transaction policy required');
        foreach (['transfer','governance','contract','compute'] as $type)if(Amount::integer($p['fees'][$type]??null)>Amount::integer($p['operations_payment']))throw new \InvalidArgumentException('Fee exceeds permitted operational debit');
        $bootstrap=Amount::integer($p['bootstrap_units']??null);
        if($bootstrap>Amount::integer($p['operations_balance']) || $bootstrap>Amount::integer($p['limits']['working_operations']) || ($bootstrap>0 && !isset($p['operations_account'])))throw new \InvalidArgumentException('Invalid bootstrap funding');
        if (isset($p['operations_account'])){self::hex($p['operations_account']);if(!isset($p['recipients'][$p['operations_account']]))throw new \InvalidArgumentException('Operations beneficiary registration required');}
        $governanceFee=Amount::integer($p['fees']['governance']);$transferFee=Amount::integer($p['fees']['transfer']);
        $signerFunding=max(Amount::multiply($governanceFee,3),1);
        if(Amount::add($signerFunding,$transferFee)>Amount::integer($p['operations_payment']))throw new \InvalidArgumentException('Fee schedule prevents bounded bootstrap payments');
        $minimumBootstrap=($governanceFee===0 && $transferFee===0)?0:Amount::multiply(Amount::add($signerFunding,$transferFee),5);
        if($bootstrap<$minimumBootstrap)throw new \InvalidArgumentException('Bootstrap cannot fund initial signer transactions');
        if (!is_array($p['recipients'] ?? null) || count($p['recipients']) > 1000) throw new \InvalidArgumentException('Explicit beneficiary registry required');
        foreach ($p['recipients'] as $address => $group) {
            self::hex($address);
            if (!is_string($group) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $group)) throw new \InvalidArgumentException('Invalid beneficiary group');
        }
        if(strlen(CanonicalJson::encode($p))>49152)throw new \InvalidArgumentException('Policy too large for a safe rotation transaction');
        return $p;
    }
}
