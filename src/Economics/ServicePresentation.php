<?php
declare(strict_types=1);
namespace Greenwood\Economics;

/** Product status projection; financial and execution finality remain separate. */
final class ServicePresentation
{
    public static function status(array $c,bool $receiptFinalized=false,bool $executing=false): array
    {
        $payment=match($c['status']??''){'paid'=>'PAYMENT_FINALIZED','refunded'=>'REFUNDED','disputed'=>'CHALLENGE','verified','resolved'=>'CHALLENGE','funded'=>'USAGE_PENDING',default=>'UNVERIFIED'};
        return ['execution_status'=>$receiptFinalized?'RECEIPT_FINALIZED':($executing?'EXECUTING':'UNVERIFIED'),'payment_status'=>$payment,'provider_journal_settled_is_payment'=>false];
    }
    public static function consent(array $program,array $verifiedQuote): array
    {
        return ['provider'=>$verifiedQuote['provider'],'trust_profile'=>$program['trust_profile']??'LEGACY_SIGNATURE_QUORUM_UNQUALIFIED','program_policy_hash'=>\Greenwood\Network\AttestationTrust::programHash($program),
            'maximum_gwd_base_units'=>$verifiedQuote['max_gwd_units'],'maximum_gru_units'=>$verifiedQuote['max_gru_units'],'subsidy_percent'=>$program['subsidy_percent'],'resource_limits'=>$verifiedQuote['resource_manifest']??null,
            'refund_policy'=>['unattested_after_end_blocks'=>ServiceSettlement::EVIDENCE_BLOCKS,'dispute_refund_after_blocks'=>ServiceSettlement::DISPUTE_BLOCKS,'challenge_blocks'=>ServiceSettlement::CHALLENGE_BLOCKS],
            'qualification'=>'Governance qualification is an external reviewed assertion; signed provider telemetry is not independent physical proof.'];
    }
}
