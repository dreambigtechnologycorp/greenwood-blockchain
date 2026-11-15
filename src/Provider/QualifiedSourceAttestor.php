<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\State\State;
use Greenwood\Crypto\{Ed25519KeyPair,ProtocolHash};
use Greenwood\Economics\{CanonicalJson,Policy,ServiceAuthorization};
use Greenwood\Network\ResourcePolicyState;

/** Witness workflow. A qualified meter signature is an external trust base, not proof of physical truth. */
final class QualifiedSourceAttestor
{
    public const SOURCE_DOMAIN='GREENWOOD_QUALIFIED_RESOURCE_SOURCE_V1';
    public static function sourceApproval(array $evidence,Ed25519KeyPair $meterKey): array
    {return ServiceAuthorization::approval('resource-source',$evidence,$meterKey)['approval'];}
    public static function approve(State $state,string $id,array $evidence,array $sourceApproval,Ed25519KeyPair $witness,string $chain,string $genesis,int $height): array
    {
        $v=ResourceSettlementWorkflow::validateEvidence($state,$id,$evidence,$chain,$genesis,$height);$p=$v['program'];$key=bin2hex($witness->getPublicKey());
        if(!in_array($key,$p['attestors'],true))throw new \InvalidArgumentException('Witness signer is not authorized by accepted program');
        $policies=ResourcePolicyState::restore($state->economicSummary()['resource_policies']??[]);$meter=$policies->current('operator',$height)[$p['meter_operator']]??null;
        $sourceKey=Policy::hex($sourceApproval['public_key']??null);$signature=$sourceApproval['signature']??null;
        if(!$meter||$sourceKey!==$meter['controller_key']||!is_string($signature)||!preg_match('/^[a-f0-9]{128}$/D',$signature)
            ||!sodium_crypto_sign_verify_detached(hex2bin($signature),ProtocolHash::hash(self::SOURCE_DOMAIN,CanonicalJson::encode($evidence)),hex2bin($sourceKey)))throw new \InvalidArgumentException('Qualified independent source signature required; provider diagnostics cannot substitute');
        return ServiceAuthorization::approval('resource-usage',$evidence,$witness)['approval'];
    }
}
