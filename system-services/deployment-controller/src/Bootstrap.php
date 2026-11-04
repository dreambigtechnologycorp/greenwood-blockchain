<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController;
use Greenwood\DeploymentController\Assignment\PhysicalAssignmentServices;
use Greenwood\DeploymentController\State\SqliteOperationStore;
use Greenwood\DeploymentController\Auth\{ChallengeService,SignedRequestVerifier};
use Greenwood\DeploymentController\Deployment\{DeploymentCoordinator,WriteEnablement};
use Greenwood\DeploymentController\Provider\{ProviderOperatorClient,ProviderObserverClient,TimerEdgeAdmission,RemoteEdgeAdmission,PhysicalServices,ReleaseEvidenceFile};
use Greenwood\DeploymentController\Util\RateLimiter;
use Greenwood\DeploymentController\Api\DeveloperRoutes;
final class Bootstrap {
    /** Server-owned configuration only. Missing integration adapters fail closed. */
    public static function build(array $c):array {
        if(isset($c['pull_credential_file']))$c['pull_credential']=(new ReleaseEvidenceFile($c['pull_credential_file']))->pullCredential(...);
        foreach(isset($c['edge_remote'])?['pull_credential']:['expected_body_hash','pull_credential','readiness_transport']as$name)
            if(!($c[$name]??null) instanceof \Closure)throw new \RuntimeException('Required physical admission adapter unavailable');
        $physical=PhysicalAssignmentServices::build($c);
        $authority=$physical['authority'];$channel=$physical['channel'];
        $store=new SqliteOperationStore($c['database_path']);
        $distributor=PhysicalAssignmentServices::distributor($c,$store->db,$physical);
        $edge=isset($c['edge_remote'])?new RemoteEdgeAdmission($channel,$c['edge_remote']):new TimerEdgeAdmission($c['edge_directory'],$distributor,$c['expected_body_hash']);
        $readiness=$edge instanceof RemoteEdgeAdmission?$edge->readiness(...):$c['readiness_transport'];
        $services=new PhysicalServices(new ProviderOperatorClient($c['provider_http'],$c['operator_token_file']),new ProviderObserverClient($channel),
            $edge,$c['provider_policy'],$c['pull_credential'],$readiness);
        $gate=new WriteEnablement($c);
        $enabled=$gate->enabled();
        $authorize=static function(string $account,array $intent)use($gate,$authority):array{
            $gate->authorize($account,$intent);
            $proof=$authority->verify($account,$intent);
            $gate->authorize($account,$intent);
            return $proof;
        };
        $coordinator=new DeploymentCoordinator($store,$authorize,$services->plan(...),$services->inspect(...),$services->start(...),
            $services->admit(...),$services->observe(...),$services->edge(...),$services->readiness(...),$enabled,
            static function(string $operation,array $plan,array $proof)use($services):array{
                $document=$services->assignmentPreflight($operation,$plan,$proof);
                return ['document'=>$document];
            },static fn(array $a):string=>$c['public_urls'][$a['application_id'].'-'.$a['service']]??'https://gw-'.$a['application_id'].'-'.$a['service'].'.greenwoodblockchain.com/',
            $distributor->distribute(...));
        $api=new DeveloperRoutes($store,new ChallengeService($store->db),new SignedRequestVerifier($store->db,$c['audience']),$coordinator,new RateLimiter($store->db),$enabled);
        return ['api'=>$api,'coordinator'=>$coordinator,'store'=>$store];
    }
}
