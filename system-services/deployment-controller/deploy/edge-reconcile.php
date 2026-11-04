<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Greenwood\DeploymentController\Assignment\PhysicalAssignmentServices;
use Greenwood\DeploymentController\Provider\ReleaseEvidenceFile;
use Greenwood\Edge\{ApacheApplyAdapter,ApacheIngressConfiguration,ApacheSystemAdapter,EdgeRouteRegistry};

umask(0077);
$settings = PhysicalAssignmentServices::config(getenv('GREENWOOD_EDGE_ADMISSION_CONFIG') ?: '');
if (($settings['managed_reconciliation_enabled'] ?? false) !== true
    || getenv('GREENWOOD_EDGE_READ_ONLY_CHECK') === '1') throw new RuntimeException('MANAGED_RECONCILIATION_DISABLED');
$base = $settings['edge_directory'];
$registry = new EdgeRouteRegistry($base.'/route-state', $settings['public_domain']);
$observe = require __DIR__.'/edge-route-observation.php';
$result = $registry->exclusive(static function () use ($settings,$base,$observe): array {
    $system = new ApacheSystemAdapter($base.'/apply', $settings['proxy_source_address'],
        static function(array $route,string $body,Closure $fetch) use ($settings):string {
            $path = $settings['content_evidence_files'][$route['application_id'].'-'.$route['service']]
                ?? $settings['content_evidence_file'] ?? '';
            return (new ReleaseEvidenceFile($path))->verifyContent($route['assignment'],$body,$fetch);
        });
    $apply = new ApacheApplyAdapter($base.'/apply',ApacheSystemAdapter::MANAGED_FILE,
        new ApacheIngressConfiguration(8081,$settings['proxy_source_address']),
        $system->candidate(...),$system->active(...),$system->reload(...),$system->probe(...));
    try {
        $observed = $observe($settings);
    } catch (Throwable $error) {
        // An unavailable authority must withdraw previously exposed managed routes.
        $observed = ['routes'=>[],'observations'=>['authority'=>'WITHDRAW_'.get_class($error)]];
    }
    return ['observations'=>$observed['observations'],'apply'=>$apply->apply($observed['routes'])];
});
echo json_encode($result,JSON_THROW_ON_ERROR), "\n";
exit(($result['apply']['confirmed'] ?? false) === true ? 0 : 1);
