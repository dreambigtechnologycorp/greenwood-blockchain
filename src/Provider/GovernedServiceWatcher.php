<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Compute\Containerd\{ContainerdClientSet,ContainerdConnection};

/** Off-chain bounded polling. Service outages cannot block core consensus. */
final class GovernedServiceWatcher
{
    public function __construct(private readonly ResourcePolicyHttpReader $reader,private readonly GovernedSystemServiceAgent $agent,private readonly SystemServiceInstallJournal $journal,private readonly FinalizedHeadWatchdog $watchdog,private readonly string $chain,private readonly string $genesis){}
    public function tick(array $consents): array
    {
        if(!array_is_list($consents)||count($consents)>16)throw new \InvalidArgumentException('Bounded local service consents required');
        $view=$this->reader->read();$this->watchdog->observe($view->height,$view->blockHash);$results=[];
        $allowed=array_fill_keys(array_column($consents,'service_policy_id'),true);$removed=0;
        foreach($this->journal->all() as $owned)if(!isset($allowed[$owned['manifest']['id']])&&in_array($owned['state'],['RUNNING','READY','DRAINING'],true)){
            if($removed++>=16)break;try{$this->agent->stopOwned($owned['policy_hash'],$view->height,true);}catch(\Throwable){$results[]=['state'=>'REMOVED_CONSENT_RECONCILIATION_REQUIRED','policy_hash'=>$owned['policy_hash']];}
        }
        foreach($consents as $c){
            try{$r=$this->agent->reconcileView($view,$this->chain,$this->genesis,$c['service_policy_id']??'',$c);
                if($r['state']==='READY'&&isset($c['cutover_from']))$this->agent->drain($c['cutover_from'],$r['policy_hash'],$view->height);$results[]=$r;}
            catch(\Throwable){$results[]=['state'=>'RECONCILIATION_REQUIRED','service_policy_id'=>$c['service_policy_id']??null];}
        }
        foreach($this->journal->all() as $r)if($r['state']==='DRAINING'&&$view->height>=$r['drain_after']){
            try{$this->agent->stopOwned($r['policy_hash'],$view->height);}catch(\Throwable){$results[]=['state'=>'DRAIN_RETRY_REQUIRED','policy_hash'=>$r['policy_hash']];}
        }
        return ['source'=>$view->source,'finalized_height'=>$view->height,'state_root'=>$view->stateRoot,'results'=>$results,'payment_authority'=>false];
    }
    public static function run(array $config,\Closure $reload): void
    {
        $namespace='gw-services-'.substr($config['genesis_hash']??'',0,24);
        if(($config['containerd_namespace']??null)!==$namespace||!in_array($config['mode']??null,['pinned_node','qualification_node'],true))throw new \InvalidArgumentException('Explicit genesis-scoped pinned node service watcher required');
        $poll=$config['poll_seconds']??15;if(!is_int($poll)||$poll<5||$poll>60)throw new \InvalidArgumentException('Bounded watcher poll interval required');
        $journal=new SystemServiceInstallJournal($config['data_directory'],$config['chain_id'],$config['genesis_hash']);
        $clients=(new ContainerdClientSet(new ContainerdConnection($namespace,[],$config['containerd_socket'])))->setSnapshotter($config['snapshotter']);
        $watcher=new self(new ResourcePolicyHttpReader($config['node_origin'],$config['chain_id'],$config['genesis_hash'],$config['tls_peer_fingerprint']??null),new GovernedSystemServiceAgent($clients,$journal),$journal,new FinalizedHeadWatchdog($config['max_stale_head_seconds']??180),$config['chain_id'],$config['genesis_hash']);
        $running=true;pcntl_async_signals(true);pcntl_signal(SIGTERM,static function()use(&$running){$running=false;});pcntl_signal(SIGINT,static function()use(&$running){$running=false;});
        while($running){
            $current=$reload();if(($current['enabled']??false)!==true)break;
            foreach($config as $key=>$value)if(!in_array($key,['consents','enabled'],true)&&($current[$key]??null)!==$value)throw new \RuntimeException('Watcher transport/runtime policy changed; restart and reconcile explicitly');
            try{$result=$watcher->tick($current['consents']??[]);echo json_encode($result,JSON_THROW_ON_ERROR)."\n";}
            catch(\Throwable){echo "{\"state\":\"POLICY_HEAD_UNAVAILABLE_OR_STALE\",\"new_installation\":false}\n";}
            for($seconds=0;$seconds<$poll&&$running;$seconds++)sleep(1);
        }
    }
}
