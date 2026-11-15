<?php
declare(strict_types=1);
namespace Greenwood\Economics;
use Greenwood\Resources\{ResourceCatalog,ResourceManifest,ProviderCapabilityV2};

/** One signed composite offer, one aggregate GWD ceiling, same GRU/GWD conversion. */
final class ResourceQuote
{
    public static function verify(GruQuote $verifier,string $body,array $approvals,string $chain,string $genesis,int $height,array $catalog,array $capability,int $subsidyPercent): array {
        $q=$verifier->verify($body,$approvals,$chain,$genesis,$height);
        if(($q['resource_quote_version']??null)!==2||$q['price_kind']!=='provider_gwd'||$q['catalog_hash']!==ResourceCatalog::hash($catalog)||!is_array($q['resource_manifest']??null))throw new \InvalidArgumentException('Composite quote/catalog mismatch');
        if(($q['capability_nonce']??null)!==$capability['nonce']||($q['capability_hash']??null)!==ProviderCapabilityV2::hash($capability)||$q['genesis']!==$capability['genesis']
            ||$q['provider']!==bin2hex(\Greenwood\Crypto\ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',hex2bin($capability['provider_key']))))throw new \InvalidArgumentException('Quote does not bind exact provider capability snapshot');
        $m=ResourceManifest::validate($q['resource_manifest'],$catalog,$capability,$subsidyPercent);
        if(($q['resource_manifest_hash']??null)!==ResourceManifest::hash($m)||Amount::integer($q['max_gru_units'])!==ResourceManifest::maximum($m)['gru_units'])throw new \InvalidArgumentException('Composite quote maximum/hash mismatch');
        return $q;
    }
}
