<?php

declare(strict_types=1);
namespace Greenwood\ControlPlane;

use Greenwood\Edge\ApprovedDeploymentStore;

/** Read-only service behind mTLS; no application execution parameters. */
final class ProviderObservationService
{
    public function __construct(
        private readonly string $providerId,
        private readonly string $providerAddress,
        private readonly array $allowedEdgePrincipals,
        private readonly ApprovedDeploymentStore $assignments,
        private readonly \Closure $inspectApplication,
    ) {}

    public function handle(string $authenticatedPrincipal, array $request): array
    {
        if (!in_array($authenticatedPrincipal, $this->allowedEdgePrincipals, true)) throw new \RuntimeException('Observation caller not authorized');
        $keys = ['application_id','service','release_id','assignment_id','nonce'];
        if (array_diff(array_keys($request), $keys) || array_diff($keys, array_keys($request))
            || !is_int($request['application_id']) || !is_int($request['release_id']) || !is_string($request['service'])
            || !is_string($request['nonce']) || !preg_match('/^[a-f0-9]{64}$/D', $request['nonce'])) throw new \InvalidArgumentException('Invalid observation request');
        $a = $this->assignments->get($request['application_id'], $request['service'], $request['release_id']);
        if ($a['provider_id'] !== $this->providerId || $a['provider_address'] !== $this->providerAddress
            || $a['assignment_id'] !== $request['assignment_id']) throw new \RuntimeException('Unapproved provider assignment');
        $view = ($this->inspectApplication)($a['application_id'], $a['service']);
        $r = $view['record'] ?? []; $runtime = $view['runtime'] ?? []; $network = $view['network'] ?? [];
        return [
            'nonce' => $request['nonce'], 'assignment_id' => $a['assignment_id'],
            'application_id' => $a['application_id'], 'service' => $a['service'], 'release_id' => $a['release_id'],
            'provider_id' => $this->providerId, 'address' => $this->providerAddress,
            'port' => $network['ingress_port'] ?? null, 'instance_id' => $r['container_id'] ?? null,
            'image_digest' => $r['image_digest'] ?? null, 'state' => $runtime['runtime_state'] ?? 'UNKNOWN',
            'network_state' => $network['state'] ?? 'UNKNOWN', 'lifecycle_state' => $r['state'] ?? 'UNKNOWN', 'pid' => $runtime['pid'] ?? null,
            'network_pid' => $network['pid'] ?? null, 'namespace_identity' => $network['namespace_identity'] ?? null,
            'ready' => ($r['state'] ?? '') === 'RUNNING' && ($network['ready'] ?? false) === true,
            'observed_at' => time(),
        ];
    }
}
