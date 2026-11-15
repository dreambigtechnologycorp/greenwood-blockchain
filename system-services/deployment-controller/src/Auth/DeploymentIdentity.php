<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Auth;

/** Process-wide immutable pins loaded exclusively from protected administrator configuration. */
final class DeploymentIdentity {
    private static ?array $scope=null;
    public static function configure(array $scope): void {
        if(!is_string($scope['chain_id']??null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D',$scope['chain_id']))throw new \InvalidArgumentException('CONFIGURED_CHAIN_ID_REQUIRED');
        foreach(['genesis_hash','registry_contract_id','registry_wasm_hash'] as $name)
            if(!is_string($scope[$name]??null)||!preg_match('/^[a-f0-9]{64}$/D',$scope[$name]))throw new \InvalidArgumentException('CONFIGURED_CHAIN_PINS_REQUIRED');
        $pins=array_intersect_key($scope,array_flip(['chain_id','genesis_hash','registry_contract_id','registry_wasm_hash']));ksort($pins);
        if(self::$scope!==null && self::$scope!==$pins)throw new \RuntimeException('DEPLOYMENT_IDENTITY_ALREADY_PINNED');
        self::$scope=$pins;
    }
    public static function chain():string{return self::scope()['chain_id'];}
    public static function registry():string{return self::scope()['registry_contract_id'];}
    public static function genesis():string{return self::scope()['genesis_hash'];}
    public static function code():string{return self::scope()['registry_wasm_hash'];}
    private static function scope():array{return self::$scope??throw new \RuntimeException('DEPLOYMENT_IDENTITY_NOT_CONFIGURED');}
    public static function validate(array $value):array {
        if(($value['chain_id']??null)!==self::chain()||($value['registry_contract_id']??null)!==self::registry()
            ||!is_int($value['application_id']??null)||$value['application_id']<1||$value['application_id']>0xffffffff)
            throw new \InvalidArgumentException('DEPLOYMENT_IDENTITY_MISMATCH');
        return ['chain_id'=>self::chain(),'registry_contract_id'=>self::registry(),'application_id'=>$value['application_id']];
    }
    public static function key(array $value):string {
        return hash('sha256',json_encode(self::validate($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }
}
