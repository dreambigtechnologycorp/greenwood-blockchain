<?php
declare(strict_types=1);
// Shared observation implementation; publication is handled by a separate entry point.
return static function(array $settings): array {
$services=\Greenwood\DeploymentController\Assignment\PhysicalAssignmentServices::build($settings);
$admissionAuthority=\Greenwood\DeploymentController\Assignment\PhysicalAssignmentServices::timer($settings);


/**
 * G4 bootstrap reconciliation of controller-approved assignments.
 * Fails closed on lost chain authority or unreachable content.
 * Never accepts hostname, provider, port, or other commands from HTTP traffic.
 * Requires live challenge-bound mTLS provider health before route publication.
 */
$base = $settings['edge_directory'];
if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0) {
    throw new RuntimeException('Root Linux reconciliation required');
}

$mtls = $services['channel'];
$store = new \Greenwood\Edge\ApprovedDeploymentStore($settings['assignments_directory'], \Greenwood\DeploymentController\Auth\DeploymentIdentity::CHAIN, \Greenwood\DeploymentController\Auth\DeploymentIdentity::REGISTRY);
$domain = $settings['public_domain'] ?? '';
if (!is_string($domain) || !preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/D', $domain)
    || !filter_var($settings['proxy_source_address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    throw new RuntimeException('Explicit edge domain and source address required');
}
if (is_link($base.'/content') || !is_dir($base.'/content') || fileowner($base.'/content') !== 0
    || (fileperms($base.'/content') & 0022) !== 0) throw new RuntimeException('Protected content directory required');
$routes = [];
$observation = [];

foreach (glob($base . '/content/*.json') ?: [] as $path) {
    try {
        if (is_link($path) || !is_file($path) || filesize($path) > 2048 || fileowner($path) !== 0 || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('Unsafe approved-content record');
        }
        $m = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($m) || array_diff(array_keys($m), ['application_id','service','release_id','body_sha256'])
            || count($m) !== 4 || !is_int($m['application_id']) || !is_int($m['release_id'])
            || !is_string($m['service']) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $m['service'])
            || !is_string($m['body_sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $m['body_sha256'])) {
            throw new RuntimeException('Invalid approved-content record');
        }
        $id = $m['application_id']; $service = $m['service']; $release = $m['release_id'];
        if (basename($path) !== $id . '-' . $service . '.json') {
            throw new RuntimeException('Content record identity mismatch');
        }
        $assignment = $store->get($id, $service, $release);
        if (!$admissionAuthority->authorize($assignment, $m)) {
            throw new RuntimeException('New application admission unverified');
        }
        $challenge = bin2hex(random_bytes(32));
        $live = $mtls->request($assignment['provider_endpoint'], $assignment['provider_tls_name'], $assignment['provider_id'], [
            'application_id'=>$id, 'service'=>$service, 'release_id'=>$release,
            'assignment_id'=>$assignment['assignment_id'], 'nonce'=>$challenge,
        ]);
        foreach (['application_id','service','release_id','assignment_id','provider_id','instance_id','image_digest'] as $field) {
            if (($live[$field] ?? null) !== $assignment[$field]) throw new RuntimeException('Authenticated assignment mismatch');
        }
        if (($live['nonce'] ?? null) !== $challenge || ($live['address'] ?? null) !== $assignment['provider_address']
            || ($live['port'] ?? null) !== $assignment['ingress_port'] || ($live['ready'] ?? false) !== true
            || ($live['state'] ?? null) !== 'RUNNING' || ($live['lifecycle_state'] ?? null) !== 'RUNNING'
            || ($live['network_state'] ?? null) !== 'HEALTHY' || !is_int($live['pid'] ?? null) || $live['pid'] < 2
            || $live['pid'] !== ($live['network_pid'] ?? null) || empty($live['namespace_identity'])
            || !is_int($live['observed_at'] ?? null) || abs(time()-$live['observed_at']) > 10) {
            throw new RuntimeException('Authenticated provider health not verified');
        }
        $url = 'http://' . $assignment['provider_address'] . ':' . $assignment['ingress_port'] . '/';
        $host = 'gw-'.$id.'-'.$service.'.'.$domain;
        $publicUrl = $settings['public_urls'][$id.'-'.$service] ?? 'https://'.$host.'/';
        if (!in_array($publicUrl,['https://'.$host.'/','https://app-'.$id.'.'.$domain.'/'],true)) throw new RuntimeException('Invalid application URL');
        $host = parse_url($publicUrl,PHP_URL_HOST);
        $source = $settings['proxy_source_address'];
        [$status,$body] = \Greenwood\Edge\BoundHttpProbe::fetch($url,$host,$source);
        $evidencePath = $settings['content_evidence_files'][$id.'-'.$service] ?? $settings['content_evidence_file'] ?? '';
        $digest=(new \Greenwood\DeploymentController\Provider\ReleaseEvidenceFile($evidencePath))->verifyContent(
            $assignment,$body,static fn(string $path):array=>\Greenwood\Edge\BoundHttpProbe::fetch(rtrim($url,'/').$path,$host,$source));
        if (!is_string($body) || $status !== 200 || !hash_equals($m['body_sha256'], $digest)) {
            throw new RuntimeException('Provider upstream content unhealthy');
        }
        if (!$admissionAuthority->authorize($assignment, $m)) {
            throw new RuntimeException('Assignment authorization changed during observation');
        }
        $route = [
            'hostname' => parse_url($publicUrl, PHP_URL_HOST),
            'application_id' => $id,
            'service' => $service,
            'release_id' => $release,
            'assignment_id' => hash('sha256', json_encode($assignment, JSON_THROW_ON_ERROR)),
            'assignment' => $assignment,
            'binding' => [
                'address' => $assignment['provider_address'],
                'port' => $assignment['ingress_port'],
                'image_digest' => $assignment['image_digest'],
            ],
            'desired' => 'PUBLISHED',
            'state' => 'HEALTHY',
        ];
        (new \Greenwood\Edge\ApacheIngressConfiguration(8081,$source))->render([$route]);
        $routes[] = $route;
        $observation[$id . '/' . $service] = 'CHAIN_AUTHORIZED_MTLS_AND_PRIVATE_HTTP_VERIFIED';
    } catch (Throwable $error) {
        $observation[basename($path)] = 'WOULD_WITHDRAW_' . get_class($error);
    }
}

$counts=array_count_values(array_column($routes,'hostname'));
// Conflicting aliases withdraw every claimant, never choose one by file order.
$routes=array_values(array_filter($routes,static fn(array $route):bool=>$counts[$route['hostname']]===1));
return ['routes'=>$routes,'observations'=>$observation];
};
