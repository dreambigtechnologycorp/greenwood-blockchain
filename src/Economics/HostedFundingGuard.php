<?php
declare(strict_types=1);
namespace Greenwood\Economics;

/** Consumer boundary shared by controller, provider start/recovery, and expiry reconciliation.
 * read() must use the administrator-pinned node transport, never caller-provided evidence.
 */
final class HostedFundingGuard
{
    public function __construct(private readonly \Closure $read,private readonly string $chain,private readonly string $genesis,private readonly string $providerKey,private readonly ?\Greenwood\Provider\FinalizedHeadWatchdog $watchdog=null) {
        Policy::hex($genesis);Policy::hex($providerKey);if($chain==='')throw new \InvalidArgumentException('Funding chain pin required');
    }
    public function require(array $document): array {
        $generation=HostedServiceBinding::generation($document);$view=($this->read)($generation);$height=$view['finalized_height']??null;
        foreach(['state_root','finalized_hash'] as $name)Policy::hex($view[$name]??null);
        if(($view['chain_id']??null)!==$this->chain || ($view['genesis_hash']??null)!==$this->genesis || !is_int($height)||$height<1)throw new \RuntimeException('HOSTED_FUNDING_NETWORK_MISMATCH');
        $this->watchdog?->observe($height,$view['finalized_hash']);
        $id=Policy::hex($view['commitment_id']??null);$c=$view['commitment']??[];$h=$c['hosted']??[];
        if(!in_array($c['adapter']??null,['hosted_cpu_v1','hosted_resources_v2'],true) || ($c['assignment_generation']??null)!==$generation
            ||($c['provider_key']??null)!==$this->providerKey || ($c['status']??null)!=='funded'
            || !is_int($c['funded_height']??null)||!is_int($h['start_height']??null)||!is_int($c['end_height']??null)
            || $c['funded_height']>=$h['start_height'] || $c['funded_height']>=$height || $height<$h['start_height'] || $height>=$c['end_height']
            ||CanonicalJson::encode($h['document']??[])!==CanonicalJson::encode($document))throw new \RuntimeException('HOSTED_FUNDED_INTERVAL_REQUIRED');
        $scope=[];
        if(isset($c['quote'],$c['request_id'])){
            $offer=CanonicalJson::decode($c['quote']);$catalog=Policy::hex($offer['catalog_hash']??null);
            $scope=['meter_scope'=>['chain'=>$this->chain,'genesis'=>$this->genesis,
                'provider_id'=>bin2hex(\Greenwood\Compute\Protocol\ProviderId::fromPublicKey(hex2bin($this->providerKey))->toBytes()),
                'workload_id'=>$c['request_id'],'assignment_generation'=>$generation,'commitment'=>$id,
                'interval_start_height'=>$h['start_height'],'interval_end_height'=>$c['end_height'],
                'catalog_hash'=>$catalog,'manifest_hash'=>($c['resource_manifest']??null)===null?$catalog:\Greenwood\Resources\ResourceManifest::hash($c['resource_manifest'])]];
        }
        return ['commitment_id'=>$id,'assignment_generation'=>$generation,'start_height'=>$h['start_height'],'end_height'=>$c['end_height'],
            'cpu_budget_ms'=>$h['cpu_budget_ms'],'financial_status'=>$c['status'],'payment_finalized'=>false,'finalized_height'=>$height]+$scope;
    }
    /** Restrict first, then stop using the existing operator. Failure to read funding denies continuation. */
    public function reconcile(array $document,\Closure $restrict,\Closure $stop): array {
        try{return ['authorized'=>true,'funding'=>$this->require($document)];}
        catch(\Throwable){$restricted=false;$stopped=false;try{$restrict();$restricted=true;}catch(\Throwable){}try{$stop();$stopped=true;}catch(\Throwable){}return ['authorized'=>false,'reason'=>'FUNDED_INTERVAL_UNAVAILABLE','restriction_confirmed'=>$restricted,'stop_confirmed'=>$stopped];}
    }
}
