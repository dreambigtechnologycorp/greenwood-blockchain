<?php
declare(strict_types=1);
namespace Greenwood\Resources\Metering;
use Greenwood\Economics\{CanonicalJson,Policy};
use Greenwood\Crypto\{Ed25519KeyPair,ProtocolHash};

/** Source-authenticated telemetry, distinct from native payment/independent attestation. */
final class TelemetryFrame
{
    public static function sign(array $frame,Ed25519KeyPair $key): array
    {
        $body=CanonicalJson::encode($frame);
        return ['body'=>$body,'signature'=>bin2hex($key->sign(ProtocolHash::hash('GREENWOOD_RESOURCE_TELEMETRY_V1',$body)))];
    }
    public static function verify(array $signed,string $sourceKey): array
    {
        Policy::hex($sourceKey);$body=$signed['body']??null;$sig=$signed['signature']??null;
        if(!is_string($body)||strlen($body)>65536||!is_string($sig)||!preg_match('/^[a-f0-9]{128}$/D',$sig)
            ||!sodium_crypto_sign_verify_detached(hex2bin($sig),ProtocolHash::hash('GREENWOOD_RESOURCE_TELEMETRY_V1',$body),hex2bin($sourceKey)))throw new \InvalidArgumentException('Untrusted resource telemetry');
        return CanonicalJson::decode($body);
    }
}
