<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Compute\Containerd\{ContainerdClientSet,GovernedServiceDefinition,SystemServiceRuntime,SystemServiceHandle};
use Greenwood\Compute\Containerd\Service\{ImagePreparationService,ImageService,TaskService};
use Greenwood\Network\ResourcePolicyState;
use Greenwood\Node\Chain;

/** Explicit local opt-in agent. Uses existing containerd transfer/runtime, no scheduler or keys. */
final class GovernedSystemServiceAgent
{
    public function __construct(private readonly ContainerdClientSet $clients,private readonly SystemServiceInstallJournal $journal)
    {$journal->pinRuntime($clients->getConnection()->getNamespace(),$clients->getConnection()->getSocketPath(),$clients->getSnapshotter());}
    public function reconcile(Chain $chain,string $expectedChain,string $expectedGenesis,string $policyId,array $consent): array
    {
        if($chain->genesis->getChainId()!==$expectedChain||bin2hex($chain->genesis->getGenesisHash())!==$expectedGenesis)throw new \InvalidArgumentException('Governed service chain pin mismatch');
        $view=new ResourcePolicyView($expectedChain,$expectedGenesis,$chain->tip->getHeight(),bin2hex($chain->state->getStateRoot()),bin2hex($chain->tip->getHash()),ResourcePolicyState::restore($chain->state->economicSummary()['resource_policies']??[]),'LOCAL_REPLAYED_CHAIN');
        return $this->reconcileView($view,$expectedChain,$expectedGenesis,$policyId,$consent);
    }
    public function reconcileView(ResourcePolicyView $view,string $expectedChain,string $expectedGenesis,string $policyId,array $consent): array
    {
        if($view->chain!==$expectedChain||$view->genesis!==$expectedGenesis)throw new \InvalidArgumentException('Governed service policy view pin mismatch');
        $this->journal->assertIdentity($expectedChain,$expectedGenesis);
        $height=$view->height;$policies=$view->policies;
        // Revocation/expiry applies to owned tasks even when admission of the desired manifest fails.
        $history=$policies->snapshot()['service'][$policyId]??[];$effective=null;
        foreach($history as $r)if($r['activation_height']<=$height)$effective=$r;
        $operator=$policies->current('operator',$height)[$consent['operator_id']??'']??null;
        foreach($this->journal->all() as $owned)if($owned['manifest']['id']===$policyId&&in_array($owned['state'],['RUNNING','READY','DRAINING'],true)){
            if(!$effective||$effective['status']!=='active'||$effective['expires_height']<=$height||!$operator||!in_array($owned['manifest']['approved_role'],$operator['roles'],true)||($consent['opt_in']??null)!==true)$this->stopOwned($owned['policy_hash'],$height,true);
        }
        $definition=GovernedServiceDefinition::build($policies,$height,$policyId,$consent);
        $manifest=$policies->current('service',$height)[$policyId];$hash=ResourcePolicyState::hash($manifest);
        $record=$this->journal->get($hash);
        if($record&&$record['state']==='STAGED'&&$record['container_id']!==$definition->id)throw new \RuntimeException('Staged runtime identity changed; explicit local reconciliation required');
        if(!$record){
            // Existing transfer path verifies committed image/rootfs before any task intent is created.
            (new ImagePreparationService($this->clients))->ensureReady($definition->imageRef);
            $image=(new ImageService($this->clients))->get($definition->imageRef);
            if($image->getTarget()->getDigest()!==$manifest['image_digest'])throw new \RuntimeException('Approved service artifact mutated');
            $record=$this->journal->stage($hash,$manifest,$definition->id,$height);
        }
        if($record['state']==='STAGED'){
            VacantSystemService::inspect($this->clients,$record);
            // CAS + EXTRA/rollback-journal intent is committed before containerd side effects. Only one caller wins.
            $this->journal->transition($hash,'STAGED','STARTING',$height);
            try{$handle=(new SystemServiceRuntime($this->clients))->start($definition);$record=$this->journal->transition($hash,'STARTING','RUNNING',$height,$handle);}
            catch(\Throwable $e){$this->journal->transition($hash,'STARTING','UNCERTAIN',$height);throw $e;}
        }
        if(in_array($record['state'],['STARTING','STOPPING','UNCERTAIN'],true))return ['state'=>'UNCERTAIN','reason'=>'EXPLICIT_RUNTIME_OWNERSHIP_RECONCILIATION_REQUIRED','policy_hash'=>$hash];
        if(in_array($record['state'],['RUNNING','READY'],true)){
            $task=(new TaskService($this->clients))->get($record['container_id'])->getProcess();
            if($task->getStatus()!==\Containerd\V1\Types\Status::RUNNING){$this->journal->transition($hash,$record['state'],'UNCERTAIN',$height);throw new \RuntimeException('Governed service task unavailable; blind restart denied');}
            // Running is not readiness. Operator's exact manifest-bound health approval is a separate step.
            if($record['state']==='RUNNING'&&($consent['ready_policy_hash']??null)===$hash)$record=$this->journal->transition($hash,'RUNNING','READY',$height);
        }
        return ['state'=>$record['state'],'policy_hash'=>$hash,'container_id'=>$record['container_id'],'payment_authority'=>false];
    }
    public function drain(string $oldHash,string $readyHash,int $height): array
    {
        $old=$this->journal->get($oldHash);$new=$this->journal->get($readyHash);
        if(!$old||!$new||$new['state']!=='READY'||!in_array($old['state'],['READY','DRAINING','STOPPED'],true)||$old['manifest']['id']!==$new['manifest']['id']||$old['manifest']['revision']>=$new['manifest']['revision'])throw new \InvalidArgumentException('Cutover requires a ready approved replacement of the same service');
        if($old['state']!=='READY'){if(($old['replacement_hash']??null)!==$readyHash)throw new \InvalidArgumentException('Existing cutover cannot change replacement or renew grace');return $old;}
        return $this->journal->transition($oldHash,'READY','DRAINING',$height,null,\Greenwood\Economics\Amount::add($height,$new['manifest']['grace_blocks']),$readyHash);
    }
    public function stopOwned(string $hash,int $height,bool $revoked=false): array
    {
        $r=$this->journal->get($hash);
        if(!$r||!$r['handle']||!in_array($r['state'],['RUNNING','READY','DRAINING'],true)||(!$revoked&&($r['state']!=='DRAINING'||$height<$r['drain_after'])))throw new \InvalidArgumentException('No owned task eligible for stop');
        if(!$revoked){$replacement=$this->journal->get($r['replacement_hash']??'');
            if(!$replacement||$replacement['state']!=='READY'||(new TaskService($this->clients))->get($replacement['container_id'])->getProcess()->getStatus()!==\Containerd\V1\Types\Status::RUNNING)throw new \RuntimeException('Replacement readiness lost; old service retained');}
        $this->journal->transition($hash,$r['state'],'STOPPING',$height);
        try{(new SystemServiceRuntime($this->clients))->stop(new SystemServiceHandle(...$r['handle']));return $this->journal->transition($hash,'STOPPING','STOPPED',$height);}
        catch(\Throwable $e){$this->journal->transition($hash,'STOPPING','UNCERTAIN',$height);throw $e;}
    }
    public function reconcileVacant(string $hash,int $height): array
    {
        $r=$this->journal->get($hash)??throw new \InvalidArgumentException('Unknown installation');return $this->journal->confirmVacant($hash,$height,VacantSystemService::inspect($this->clients,$r));
    }
}
