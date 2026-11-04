<?php

declare(strict_types=1);
namespace Greenwood\DeploymentController\Registry;

use Greenwood\DeploymentController\Registry\GreenwoodRpc;

/** Fresh, coherent finalized registry reads; no signing service or writes. */
final class FinalizedRegistryEdgeAuthority
{
    private ?array $evidence = null;
    public function __construct(
        private readonly GreenwoodRpc $rpc,
        private readonly ApplicationRegistryV1Client $registry,
        private readonly string $chainId,
        private readonly array $principalAccounts,
    ) {}

    public function authorize(string $authenticatedPrincipal, array $assignment): bool
    {
        $this->evidence = null;
        $account = $this->principalAccounts[$authenticatedPrincipal] ?? null;
        if (!is_string($account) || !preg_match('/^[a-f0-9]{64}$/D', $account)) return false;
        $before = $this->rpc->getChainStatus();
        if ($before['chain_id'] !== $this->chainId || ($before['syncing'] ?? true) !== false
            || $before['height'] !== $before['finalized_height']) return false;
        $this->registry->atFinalizedHeight($before['finalized_height']);
        $id=$assignment['application_id']; $release=$assignment['release_id'];
        $app=$this->registry->application($id);
        $authority=$this->registry->authority($id,$account);
        $header=$this->registry->release($id,$release);
        $digest=$this->registry->releaseServiceDigest($id,$release,$assignment['service']);
        $after=$this->rpc->getChainStatus();
        foreach (['chain_id','height','finalized_height','block_hash','state_root','syncing'] as $field) {
            if (!array_key_exists($field,$before) || !array_key_exists($field,$after) || $before[$field] !== $after[$field]) return false;
        }
        $ok=$app['active_release']===$release && $header['release_number']===$release
            && ($authority & ApplicationRegistryV1Client::CAP_DEPLOY)!==0
            && 'sha256:'.$digest===$assignment['image_digest'];
        $this->evidence=['authorized'=>$ok,'chain_id'=>$this->chainId,'registry_contract'=>$this->registry->contractId(),
            'application_id'=>$id,'release_id'=>$release,'service'=>$assignment['service'],'account_id'=>$account,
            'controller'=>$app['controller'],'authority_epoch'=>$app['authority_epoch'],'effective_authority'=>$authority,
            'image_digest'=>'sha256:'.$digest,'finalized_height'=>$after['finalized_height'],
            'block_hash'=>$after['block_hash'],'state_root'=>$after['state_root'],'observed_at'=>time()];
        return $ok;
    }
    public function evidence(): ?array { return $this->evidence; }
}
