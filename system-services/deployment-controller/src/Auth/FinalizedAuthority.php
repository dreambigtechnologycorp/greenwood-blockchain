<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Auth;
use Greenwood\DeploymentController\Registry\{GreenwoodRpc,ApplicationRegistryV1Client,FinalizedRegistryEdgeAuthority};
final class FinalizedAuthority {
    public const CONTRACT=DeploymentIdentity::REGISTRY;
    public function __construct(private readonly GreenwoodRpc $rpc) {}
    public function verify(string $account,array $intent):array {
        DeploymentIdentity::validate($intent);
        $started=time();$meta=$this->rpc->get('/v1/contracts/'.self::CONTRACT);
        if(($meta['contract_id']??null)!==self::CONTRACT||($meta['wasm_hash']??null)!==self::CONTRACT||($meta['abi_version']??null)!==1)throw new \RuntimeException('REGISTRY_CODE_MISMATCH');
        $registry=new ApplicationRegistryV1Client($this->rpc,self::CONTRACT);
        $authority=new FinalizedRegistryEdgeAuthority($this->rpc,$registry,'greenwood-physical-v1',['developer'=>$account]);
        $assignment=['application_id'=>$intent['application_id'],'release_id'=>$intent['release_number'],'service'=>$intent['service'],'image_digest'=>$intent['image_digest']];
        if(!$authority->authorize('developer',$assignment)||time()-$started>15)throw new \RuntimeException('FINALIZED_AUTHORITY_UNAVAILABLE');
        return $authority->evidence();
    }
}
