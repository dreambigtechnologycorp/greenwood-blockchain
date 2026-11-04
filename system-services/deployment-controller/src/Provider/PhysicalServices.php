<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Provider;
/** Binds orchestration to existing provider, observer, timer files and readiness service. */
final class PhysicalServices {
    public function __construct(private readonly ProviderOperatorClient $provider,private readonly ProviderObserverClient $observer,
        private readonly TimerEdgeAdmission|RemoteEdgeAdmission $edge,private readonly array $providerPolicy,private readonly \Closure $pullCredential,
        private readonly \Closure $readinessTransport) {}
    public function plan(string $operation,array $intent,array $authority):array {
        $p=$this->providerPolicy;
        if(($authority['authorized']??false)!==true||$intent['application_id']<1)throw new \RuntimeException('UNAUTHORIZED_ASSIGNMENT');
        \Greenwood\DeploymentController\Auth\DeploymentIdentity::validate($intent);
        return ['chain_id'=>$intent['chain_id'],'registry_contract_id'=>$intent['registry_contract_id'],'application_id'=>$intent['application_id'],'service'=>$intent['service'],'release_id'=>$intent['release_number'],
            'provider_id'=>$p['provider_id'],'provider_address'=>$p['provider_address'],'provider_endpoint'=>$p['provider_endpoint'],
            'provider_tls_name'=>$p['provider_tls_name'],'image_digest'=>$intent['image_digest'],
            'image_ref'=>$p['registry_host'].'/chains/'.$intent['chain_id'].'/registries/'.$intent['registry_contract_id'].'/apps/'.$intent['application_id'].'/'.$intent['service'].'@'.$intent['image_digest']];
    }
    public function inspect(array $plan):array{return $this->provider->inspectApplication($plan['application_id'],$plan['service']);}
    public function assignmentPreflight(string $operation,array $plan,array $authority):array {
        if(($authority['authorized']??false)!==true)throw new \RuntimeException('UNAUTHORIZED_ASSIGNMENT');
        $reservation=$this->provider->reserveApplication($plan['application_id'],$plan['service'],$plan['image_ref']);
        foreach(['application_id','service','image_ref','image_digest']as$key)
            if(($reservation[$key]??null)!==$plan[$key])throw new \RuntimeException('RESERVATION_IDENTITY_MISMATCH');
        if(($reservation['reserved']??false)!==true||($reservation['ready']??null)!==false)throw new \RuntimeException('RESERVATION_UNAVAILABLE');
        $a=$plan;unset($a['image_ref']);
        $a['instance_id']=$reservation['instance_id']??null;$a['ingress_port']=$reservation['ingress_port']??null;
        return \Greenwood\DeploymentController\Assignment\AssignmentDocument::validate([
            'chain_id'=>$plan['chain_id'],'registry_contract_id'=>$plan['registry_contract_id'],
            'operation_id'=>$operation,'account_id'=>$authority['account_id'],'version'=>1,'previous_generation'=>null,'assignment'=>$a]);
    }
    public function start(string $operation,array $plan):array {
        $token=($this->pullCredential)($plan);return $this->provider->startApplication($plan['application_id'],$plan['service'],$plan['image_ref'],$token,'https');
    }
    public function admit(string $operation,array $a,array $proof):void{$this->edge->admit($operation,$a,$proof);}
    public function observe(array $a):array{return $this->observer->verifyApplication($a);}
    public function edge(string $operation,array $a,array $proof):array{return $this->edge->inspect($operation,$a,$proof);}
    public function readiness(array $a,string $nonce):array{return ($this->readinessTransport)([
        'application_id'=>$a['application_id'],'service'=>$a['service'],'release_id'=>$a['release_id'],'image_digest'=>$a['image_digest'],'nonce'=>$nonce]);}
}
