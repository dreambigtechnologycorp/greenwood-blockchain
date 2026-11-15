<?php
declare(strict_types=1);
namespace Greenwood\Economics;
use Greenwood\Crypto\{Ed25519KeyPair,ProtocolHash};
use Greenwood\Transaction\Envelope;

/** PHP client signing helpers. No RPC, key creation, or transaction broadcasting. */
final class ServiceAuthorization
{
    public static function transaction(string $chain,string $genesis,int $nonce,array $action,Ed25519KeyPair $key): Envelope {
        if(strlen($genesis)!==32)throw new \InvalidArgumentException('Genesis hash required');
        if(isset($action['genesis']) && $action['genesis']!==bin2hex($genesis))throw new \InvalidArgumentException('Conflicting genesis');
        $action+=['version'=>1,'genesis'=>bin2hex($genesis)];
        if($action['version']!==1)throw new \InvalidArgumentException('Unsupported settlement version');
        $tx=new Envelope(1,$chain,ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$key->getPublicKey()),$nonce,Envelope::TYPE_SERVICE,CanonicalJson::encode($action),'',$key->getPublicKey());
        return $tx->setSignature($key->sign($tx->getSigningDigest()));
    }
    public static function approval(string $kind,array $document,Ed25519KeyPair $key): array {
        $domain=match($kind){'quote'=>'GREENWOOD_GRU_QUOTE_V1','usage'=>'GREENWOOD_USAGE_ATTEST_V1','resource-usage'=>'GREENWOOD_RESOURCE_USAGE_V1','resource-source'=>'GREENWOOD_QUALIFIED_RESOURCE_SOURCE_V1','resolution'=>'GREENWOOD_USAGE_RESOLVE_V1',default=>throw new \InvalidArgumentException('Unknown document signature domain')};
        $body=CanonicalJson::encode($document);
        return ['body'=>$body,'approval'=>['public_key'=>bin2hex($key->getPublicKey()),'signature'=>bin2hex($key->sign(ProtocolHash::hash($domain,$body)))]];
    }
}
