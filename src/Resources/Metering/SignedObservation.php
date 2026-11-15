<?php
declare(strict_types=1);
namespace Greenwood\Resources\Metering;

use Greenwood\Crypto\{Ed25519KeyPair,ProtocolHash};
use Greenwood\Economics\{CanonicalJson,Policy};

/** Portable provider-origin provenance. NOT a native usage approval or independent witness. */
final class SignedObservation
{
    private const DOMAIN='GREENWOOD_PROVIDER_METER_OBSERVATION_V1';
    public static function sign(array $scope,array $bundle,Ed25519KeyPair $key): array
    {
        self::scope($scope);
        if(isset($bundle['bundle']['funding_scope'])&&CanonicalJson::encode($bundle['bundle']['funding_scope'])!==CanonicalJson::encode($scope))throw new \InvalidArgumentException('Observation contradicts persisted funded scope');
        if(($bundle['bundle']['commitment']??null)!==$scope['commitment']||($bundle['bundle']['verification_level']??null)!=='C'
            ||($bundle['subsidy_eligible']??null)!==false)throw new \InvalidArgumentException('Only explicitly unqualified local observations supported');
        $hash=bin2hex(ProtocolHash::hash('GREENWOOD_LOCAL_METER_BUNDLE_V1',CanonicalJson::encode($bundle['bundle'])));
        if(($bundle['evidence_bundle_hash']??null)!==$hash)throw new \InvalidArgumentException('Bundle digest mismatch');
        $body=CanonicalJson::encode(['version'=>1,'scope'=>$scope,'trust_profile'=>'BOOTSTRAP_OPERATOR_ATTESTED','verification_level'=>'C','payable'=>false,'bundle_hash'=>$hash,'signer'=>bin2hex($key->getPublicKey())]);
        return ['body'=>$body,'signature'=>bin2hex($key->sign(ProtocolHash::hash(self::DOMAIN,$body)))];
    }
    public static function verify(array $signed,array $expectedScope,string $expectedSigner): array
    {
        self::scope($expectedScope);Policy::hex($expectedSigner);
        $body=CanonicalJson::decode($signed['body']??'');
        $sig=$signed['signature']??null;
        if(CanonicalJson::encode($body['scope']??[])!==CanonicalJson::encode($expectedScope)||($body['version']??null)!==1
            ||($body['signer']??null)!==$expectedSigner||($body['trust_profile']??null)!=='BOOTSTRAP_OPERATOR_ATTESTED'
            ||($body['verification_level']??null)!=='C'||($body['payable']??null)!==false
            ||!is_string($sig)||!preg_match('/^[a-f0-9]{128}$/D',$sig)
            ||!sodium_crypto_sign_verify_detached(hex2bin($sig),ProtocolHash::hash(self::DOMAIN,$signed['body']),hex2bin($expectedSigner)))throw new \InvalidArgumentException('Untrusted observation signature or scope');
        Policy::hex($body['bundle_hash']??null);return $body;
    }
    public static function scope(array $s): void
    {
        $keys=['chain','genesis','provider_id','workload_id','assignment_generation','commitment','interval_start_height','interval_end_height','catalog_hash','manifest_hash'];
        if(count($s)!==count($keys)||array_diff(array_keys($s),$keys)||!is_string($s['chain']??null)||$s['chain']===''||strlen($s['chain'])>128)throw new \InvalidArgumentException('Exact meter binding required');
        foreach(['genesis','provider_id','workload_id','assignment_generation','commitment','catalog_hash','manifest_hash'] as $k)Policy::hex($s[$k]??null);
        foreach(['interval_start_height','interval_end_height'] as $k)if(!is_int($s[$k])||$s[$k]<1)throw new \InvalidArgumentException('Invalid meter interval');
        if($s['interval_end_height']<=$s['interval_start_height'])throw new \InvalidArgumentException('Empty meter interval');
    }
}
