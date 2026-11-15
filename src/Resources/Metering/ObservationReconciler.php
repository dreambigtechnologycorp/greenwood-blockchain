<?php
declare(strict_types=1);
namespace Greenwood\Resources\Metering;
use Greenwood\Economics\{Amount,CanonicalJson,Policy};
use Greenwood\Crypto\ProtocolHash;

/** Reconciles separately collected signed CPU observations under explicitly configured trust domains.
 * Domain configuration is an administrative assertion; physical independence still needs qualification.
 */
final class ObservationReconciler
{
    public static function cpu(array $observations,array $authorities,int $threshold,string $commitment,string $runtime,int $toleranceMs): array {
        Policy::hex($commitment);Policy::hex($runtime);
        if($threshold<2||$threshold>count($authorities)||count($observations)>7||$toleranceMs<0)throw new \InvalidArgumentException('Invalid observation policy');
        $keys=[];$admins=[];$infra=[];$quantities=[];$records=[];
        foreach($observations as $signed){$o=$signed['observation']??[];$key=$signed['public_key']??'';Policy::hex($key);$policy=$authorities[$key]??null;$sig=$signed['signature']??'';
            if(!$policy||isset($keys[$key])||!is_string($sig)||!preg_match('/^[a-f0-9]{128}$/D',$sig)||!sodium_crypto_sign_verify_detached(hex2bin($sig),ProtocolHash::hash('GREENWOOD_RESOURCE_OBSERVATION_V1',CanonicalJson::encode($o)),hex2bin($key)))throw new \InvalidArgumentException('Invalid observation signer');
            foreach(['administrative_domain','infrastructure_domain','qualification_hash'] as $field)Policy::hex($policy[$field]??null);
            if($policy['qualification_hash']===str_repeat('0',64)||!in_array($policy['verification_level']??null,['A','B'],true)||($o['version']??null)!==1||($o['commitment']??null)!==$commitment||($o['runtime_identity']??null)!==$runtime
                ||!in_array($o['source_kind']??null,['witness_cgroup','witness_task_clock'],true)||($o['source_kind']??null)!==($policy['source_kind']??null))throw new \InvalidArgumentException('Unqualified/provider-supplied observation');
            $start=Amount::integer($o['counter_start_ms']??null);$end=Amount::integer($o['counter_end_ms']??null);if($end<$start)throw new \InvalidArgumentException('Counter reset requires segment reconciliation');
            Policy::hex($o['source_hash']??null);$keys[$key]=true;$admins[$policy['administrative_domain']]=true;$infra[$policy['infrastructure_domain']]=true;$quantities[]=$end-$start;$records[$key]=$o;
        }
        if(count($keys)<$threshold||count($admins)<$threshold||count($infra)<$threshold)throw new \InvalidArgumentException('Independent declared observation domains missing');
        sort($quantities,SORT_NUMERIC);if($quantities[count($quantities)-1]-$quantities[0]>$toleranceMs)throw new \InvalidArgumentException('Conflicting independent measurements');
        ksort($records,SORT_STRING);return ['cpu_ms'=>(string)$quantities[0],'evidence_bundle_hash'=>bin2hex(ProtocolHash::hash('GREENWOOD_RECONCILED_OBSERVATIONS_V1',CanonicalJson::encode($records))),'physical_independence_demonstrated'=>false];
    }
}
