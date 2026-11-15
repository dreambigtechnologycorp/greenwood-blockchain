<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Auth;
use Greenwood\DeploymentController\Registry\{GreenwoodRpc,ApplicationRegistryV1Client,FinalizedRegistryEdgeAuthority};
final class FinalizedAuthority {
    public function __construct(private readonly GreenwoodRpc $rpc,private readonly bool $hostedCandidateEnabled=false) {}
    public function verify(string $account,array $intent):array {
        DeploymentIdentity::validate($intent);
        $started=time();$status=$this->rpc->get('/v1/chain/status');if(($status['genesis_hash']??null)!==DeploymentIdentity::genesis())throw new \RuntimeException('GENESIS_IDENTITY_MISMATCH');
        if(($status['economy']['version']??null)===1&&!$this->hostedCandidateEnabled)throw new \RuntimeException('HOSTED_SERVICE_SETTLEMENT_NOT_QUALIFIED');$meta=$this->rpc->get('/v1/contracts/'.DeploymentIdentity::registry());
        if(($meta['contract_id']??null)!==DeploymentIdentity::registry()||($meta['wasm_hash']??null)!==DeploymentIdentity::code()||($meta['abi_version']??null)!==1)throw new \RuntimeException('REGISTRY_CODE_MISMATCH');
        $registry=new ApplicationRegistryV1Client($this->rpc,DeploymentIdentity::registry());
        $authority=new FinalizedRegistryEdgeAuthority($this->rpc,$registry,DeploymentIdentity::chain(),['developer'=>$account]);
        $assignment=['application_id'=>$intent['application_id'],'release_id'=>$intent['release_number'],'service'=>$intent['service'],'image_digest'=>$intent['image_digest']];
        if(!$authority->authorize('developer',$assignment)||time()-$started>15)throw new \RuntimeException('FINALIZED_AUTHORITY_UNAVAILABLE');
        return $authority->evidence();
    }
}
