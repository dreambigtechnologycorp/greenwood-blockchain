<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Greenwood\DeploymentController\Assignment\PhysicalAssignmentServices;
use Greenwood\DeploymentController\Provider\{TimerEdgeAdmission,EdgeAdmissionEndpoint,PhysicalReadiness,ProviderObserverClient,ReleaseEvidenceFile};
umask(0077);
$path=getenv('GREENWOOD_EDGE_ADMISSION_CONFIG');
if(!$path)throw new RuntimeException('EDGE_CONFIGURATION_REQUIRED');
$c=PhysicalAssignmentServices::config($path);
if(isset($c['content_evidence_file']))$c['expected_body_hash']=(new ReleaseEvidenceFile($c['content_evidence_file']))->contentHash(...);
if(isset($c['content_evidence_files']))$c['expected_body_hash']=static function(array $a)use($c):string{
    $path=$c['content_evidence_files'][$a['application_id'].'-'.$a['service']]??'';
    return (new ReleaseEvidenceFile($path))->contentHash($a);
};
foreach(['expected_body_hash']as$name)
    if(!($c[$name]??null) instanceof Closure)throw new RuntimeException('EDGE_ADAPTER_REQUIRED');
$s=PhysicalAssignmentServices::build($c);
// Validate the private ledger path through the same composition used by the timer.
$authority=PhysicalAssignmentServices::timer($c);
$db=new PDO('sqlite:'.$c['distribution_ledger']);
$distributor=PhysicalAssignmentServices::distributor($c,$db,$s);
$admission=new TimerEdgeAdmission($c['edge_directory'],$distributor,$c['expected_body_hash'],true,$c['assignments_directory']);
$readiness=new PhysicalReadiness($c,$admission,$authority,$s['authority']->verify(...),new ProviderObserverClient($s['channel']));
$handler=new EdgeAdmissionEndpoint($admission,$s['authority']->verify(...),$readiness->inspect(...));
$listener=$s['channel']->listen($c['listen']);
while(true){
    try{$s['channel']->serveOne($listener,$handler->handle(...));}
    catch(Throwable){/* No credentials, request data or raw exception text in logs. */}
}
