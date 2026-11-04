<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Greenwood\DeploymentController\Assignment\PhysicalAssignmentServices;
use Greenwood\DeploymentController\Auth\DeploymentIdentity;
use Greenwood\DeploymentController\Provider\ProviderOperatorClient;
use Greenwood\ControlPlane\ProviderObservationService;
use Greenwood\Edge\ApprovedDeploymentStore;
umask(0077);
$path=getenv('GREENWOOD_H8_OBSERVER_CONFIG');
if(!$path)throw new RuntimeException('OBSERVER_CONFIGURATION_REQUIRED');
$c=PhysicalAssignmentServices::config($path);$s=PhysicalAssignmentServices::build($c);
$store=new ApprovedDeploymentStore($c['assignments_directory'],DeploymentIdentity::CHAIN,DeploymentIdentity::REGISTRY);
$operator=new ProviderOperatorClient($c['provider_http'],$c['operator_token_file']);
$service=new ProviderObservationService($c['provider_id'],$c['provider_address'],['deployment-controller','edge-admission'],$store,$operator->inspectApplication(...));
$listener=$s['channel']->listen($c['listen']);
while(true){
    try{$s['channel']->serveOne($listener,$service->handle(...));}
    catch(Throwable){/* Fail closed without logging credentials or request contents. */}
}
