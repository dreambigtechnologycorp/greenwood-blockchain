<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\State\State;
use Greenwood\Network\{AttestationTrust,ResourcePolicyState};
use Greenwood\Resources\{ResourceUsageEvidence,ResourceManifest};
use Greenwood\Economics\{ServiceSettlement,GruQuote};
use Greenwood\Crypto\ProtocolHash;

/** Attestor/coordinator preparation using the same native validators. No signing or spending. */
final class ResourceSettlementWorkflow
{
    public static function usageAction(State $state,string $id,array $evidence,array $approvals,string $chain,string $genesis,int $height): array
    {
        $validated=self::validateEvidence($state,$id,$evidence,$chain,$genesis,$height);
        ServiceSettlement::verifyApprovals($evidence,$approvals,$validated['program'],'GREENWOOD_RESOURCE_USAGE_V1');
        return ['action'=>['action'=>'verify_usage','commitment'=>$id,'evidence'=>$evidence,'approvals'=>$approvals],
            'preview'=>$validated['cost'],'signing_required'=>true,'payment_finalized'=>false,'challenge_blocks'=>ServiceSettlement::CHALLENGE_BLOCKS];
    }
    public static function validateEvidence(State $state,string $id,array $evidence,string $chain,string $genesis,int $height): array
    {return self::validateProfile($state,$id,$evidence,$chain,$genesis,$height,'INDEPENDENT_ATTESTED');}
    /** Explicit founder-operated path. Never invoked as fallback from independent verification. */
    public static function bootstrapUsageAction(State $state,string $id,array $evidence,array $approvals,string $chain,string $genesis,int $height): array
    {
        $validated=self::validateProfile($state,$id,$evidence,$chain,$genesis,$height,'BOOTSTRAP_OPERATOR_ATTESTED');
        ServiceSettlement::verifyApprovals($evidence,$approvals,$validated['program'],'GREENWOOD_RESOURCE_USAGE_V1');
        return ['action'=>['action'=>'verify_usage','commitment'=>$id,'evidence'=>$evidence,'approvals'=>$approvals],
            'preview'=>$validated['cost'],'signing_required'=>true,'payment_finalized'=>false,'challenge_blocks'=>ServiceSettlement::CHALLENGE_BLOCKS,
            'trust_profile'=>'BOOTSTRAP_OPERATOR_ATTESTED','independent_verification'=>false,'subsidy_eligible'=>false];
    }
    private static function validateProfile(State $state,string $id,array $evidence,string $chain,string $genesis,int $height,string $profile): array
    {
        $economic=$state->economicSummary();$c=$economic['services']['commitments'][$id]??null;
        if(!$economic||$economic['chain']!==$chain||$economic['genesis']!==bin2hex($genesis)||$height!==$economic['last_release_height']+1||!$c||$c['status']!=='funded'||$height>$c['evidence_deadline'])throw new \InvalidArgumentException('Current funded native commitment and inclusion height required');
        $p=$economic['services']['programs'][$c['program']]['policy'];
        if(($p['trust_profile']??null)!==$profile)throw new \InvalidArgumentException('Explicit preparation profile must match accepted trust policy');
        AttestationTrust::authorize($p,ResourcePolicyState::restore($economic['resource_policies']??[]),$height);$receiptCpu=null;
        if($c['adapter']==='compute_resources_v2'){
            $receipt=$state->getComputeState()->receipt(hex2bin($c['request_id']));$assignment=$state->getComputeState()->assignment(hex2bin($c['request_id']));
            if(!$receipt||!$assignment||$receipt->generation!==$c['generation'])throw new \InvalidArgumentException('Finalized matching compute receipt required');
            $receiptCpu=$receipt->body()->getCpuTimeMs();$c['usage_assignment_generation']=bin2hex(ProtocolHash::hash('GREENWOOD_RESOURCE_ASSIGNMENT_V1',$assignment->toBytes()));
        }elseif($c['adapter']!=='hosted_resources_v2'||$height<=$c['end_height'])throw new \InvalidArgumentException('Ended qualified resource interval required');
        $quantities=ResourceUsageEvidence::quantities($evidence,$c,$p,$chain,bin2hex($genesis),$receiptCpu);
        $gru=ResourceManifest::value($c['resource_manifest'],$quantities)['gru_units'];$quote=new GruQuote([$p['provider_key']],1,$p['gru_scale'],525960);
        $cost=$quote->value($c['quote'],$c['quote_approvals'],$chain,$genesis,$c['accepted_height'],$gru,$p['subsidy_percent']);
        return ['program'=>$p,'commitment'=>$c,'cost'=>$cost];
    }
}
