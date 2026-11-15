<?php
declare(strict_types=1);
namespace Greenwood\Network;
use Greenwood\Economics\Amount;

/** Nonpayable budgeting projection. Outputs cannot debit reserves or authorize invoices. */
final class InfrastructureCostModel
{
    public static function project(array $p,int $qualifiedBlocks,int $attestations,int $operators): array
    {
        if(($p['version']??null)!==1||($p['unit']??null)!=='GWD_BASE_UNITS_PER_FINALIZED_BLOCK')throw new \InvalidArgumentException('Explicit infrastructure budget unit required');
        foreach([$qualifiedBlocks,$attestations,$operators] as $v)if($v<0)throw new \InvalidArgumentException('Negative availability/activity');
        $window=Amount::integer($p['window_blocks']??null);$maxOperators=Amount::integer($p['max_operators']??null);$maxAttestations=Amount::integer($p['max_attestations']??null);
        if($window<1||$qualifiedBlocks>$window||$operators>$maxOperators||$attestations>$maxAttestations)throw new \InvalidArgumentException('Infrastructure budget admission bound');
        $base=Amount::multiply(Amount::multiply($qualifiedBlocks,$operators),Amount::integer($p['base_units_per_block']??null));
        $activity=Amount::multiply($attestations,Amount::integer($p['units_per_attestation']??null));$total=Amount::add($base,$activity);
        if($total>Amount::integer($p['window_cap_units']??null))throw new \InvalidArgumentException('Proposed infrastructure budget insolvent at declared exposure');
        return ['base_units'=>(string)$base,'activity_units'=>(string)$activity,'maximum_gwd_units'=>(string)$total,'payable'=>false,'authorization'=>'SEPARATE_EXISTING_TREASURY_PROPOSAL_REQUIRED','qualification'=>'REQUIRES_RATIFICATION','issuance_change'=>false];
    }
}
