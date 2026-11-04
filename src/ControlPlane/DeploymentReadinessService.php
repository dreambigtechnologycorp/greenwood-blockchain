<?php
declare(strict_types=1);
namespace Greenwood\ControlPlane;

/** Read-only verification. All destinations come from protected controller assignments. */
final class DeploymentReadinessService
{
    public function __construct(
        private readonly \Closure $snapshot,
        private readonly \Closure $authorize,
        private readonly \Closure $observe,
        private readonly \Closure $probe,
        private readonly \Closure $clock,
        private readonly ?\Closure $canonicalUrl = null,
        private readonly int $minimumApplicationId = 2,
    ) {}

    public function inspect(array $request): array
    {
        $keys = ['application_id','service','release_id','image_digest','nonce'];
        if (array_diff(array_keys($request), $keys) || array_diff($keys, array_keys($request))
            || !is_int($request['application_id']) || $request['application_id'] < max(1,$this->minimumApplicationId)
            || !is_int($request['release_id']) || $request['release_id'] < 1
            || !is_string($request['service']) || !preg_match('/^[a-z][a-z0-9-]{0,62}$/D', $request['service'])
            || !is_string($request['image_digest']) || !preg_match('/^sha256:[a-f0-9]{64}$/D', $request['image_digest'])
            || !is_string($request['nonce']) || !preg_match('/^[a-f0-9]{64}$/D', $request['nonce'])) {
            throw new \InvalidArgumentException('Invalid readiness identity');
        }
        $start = ($this->clock)();
        $snapshot = ($this->snapshot)($request);
        $a = $snapshot['assignment'];
        foreach (['application_id','service','release_id','image_digest'] as $key) {
            if ($request[$key] !== ($a[$key] ?? null)) throw new \RuntimeException('Assignment mismatch');
        }
        $authority = ($this->authorize)($a);
        if (($authority['authorized'] ?? false) !== true) throw new \RuntimeException('Unauthorized deployment');
        $challenge = bin2hex(random_bytes(32));
        $live = ($this->observe)($a, $challenge);
        foreach (['application_id','service','release_id','assignment_id','provider_id','instance_id','image_digest'] as $key) {
            if (($live[$key] ?? null) !== ($a[$key] ?? null)) throw new \RuntimeException('Provider assignment mismatch');
        }
        if (($live['nonce'] ?? null) !== $challenge || ($live['address'] ?? null) !== $a['provider_address']
            || ($live['port'] ?? null) !== $a['ingress_port'] || ($live['ready'] ?? false) !== true
            || ($live['state'] ?? null) !== 'RUNNING' || ($live['lifecycle_state'] ?? null) !== 'RUNNING'
            || ($live['network_state'] ?? null) !== 'HEALTHY' || !is_int($live['pid'] ?? null) || $live['pid'] < 2
            || $live['pid'] !== ($live['network_pid'] ?? null) || !is_string($live['namespace_identity'] ?? null)
            || $live['namespace_identity'] === '' || !is_int($live['observed_at'] ?? null)
            || $live['observed_at'] < $start-2 || $live['observed_at'] > ($this->clock)()+2) {
            throw new \RuntimeException('Fresh provider health unavailable');
        }
        $url = $this->canonicalUrl===null?'https://gw-' . $a['application_id'] . '-' . $a['service'] . '.greenwoodblockchain.com/':($this->canonicalUrl)($a);
        $allowed=['https://gw-'.$a['application_id'].'-'.$a['service'].'.greenwoodblockchain.com/',
            'https://app-'.$a['application_id'].'.greenwoodblockchain.com/'];
        if(!in_array($url,$allowed,true))throw new \RuntimeException('Unapproved application URL');
        $public = ($this->probe)($snapshot, $url);
        if (($public['verified'] ?? false) !== true) throw new \RuntimeException('Effective public route unverified');
        // Authorization and protected inputs must still describe the same deployment after I/O.
        $finalAuthority = ($this->authorize)($a);
        if (($finalAuthority['authorized'] ?? false) !== true
            || ($finalAuthority['authority_epoch'] ?? null) !== ($authority['authority_epoch'] ?? null)
            || ($this->snapshot)($request) !== $snapshot) throw new \RuntimeException('Deployment changed during observation');
        $now = ($this->clock)();
        if ($now - $start > 20 || $now - $live['observed_at'] > 15) throw new \RuntimeException('Observation expired');
        return $request + [
            'schema_version'=>1, 'ready'=>true, 'status'=>'READY',
            'assignment_id'=>$a['assignment_id'], 'provider_id'=>$a['provider_id'],
            'instance_id'=>$a['instance_id'], 'pid'=>$live['pid'], 'namespace_identity'=>$live['namespace_identity'],
            'backend'=>['address'=>$a['provider_address'],'port'=>$a['ingress_port']],
            'edge_generation'=>$snapshot['generation'], 'canonical_url'=>$url,
            'observed_at'=>$now, 'expires_at'=>$live['observed_at']+15,
            'provider_observed_at'=>$live['observed_at'], 'authority'=>$finalAuthority,
            'public_https'=>$public, 'provider_mtls_verified'=>true,
        ];
    }
}
