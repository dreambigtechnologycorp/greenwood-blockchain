<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Auth;

/** Only the corrected testnet registry may supply deployment authority. */
final class DeploymentIdentity {
    public const CHAIN='greenwood-physical-v1';
    public const REGISTRY='37ee42792a1fbb2aadc2f4a9fc5ad37a6b5528669e8aa8a90be75f16ba5df2f6';
    public static function validate(array $value):array {
        if(($value['chain_id']??null)!==self::CHAIN||($value['registry_contract_id']??null)!==self::REGISTRY
            ||!is_int($value['application_id']??null)||$value['application_id']<1||$value['application_id']>0xffffffff)
            throw new \InvalidArgumentException('DEPLOYMENT_IDENTITY_MISMATCH');
        return ['chain_id'=>self::CHAIN,'registry_contract_id'=>self::REGISTRY,'application_id'=>$value['application_id']];
    }
    public static function key(array $value):string {
        return hash('sha256',json_encode(self::validate($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }
}
