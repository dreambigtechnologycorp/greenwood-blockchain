<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Deployment;

use Greenwood\DeploymentController\Auth\DeploymentIdentity;

/** Bounded execution scope loaded only from the protected server configuration. */
final class WriteEnablement
{
    private readonly bool $enabled;
    public function __construct(private readonly array $config)
    {
        $flag = $config['deployment_enabled'] ?? false;
        if (!is_bool($flag)) throw new \RuntimeException('BOOLEAN_WRITE_ENABLEMENT_REQUIRED');
        $this->enabled = $flag;
        if (!$flag) return;
        $this->scope();
        if (($config['provider_http'] ?? null) !== 'unix:///run/greenwood-provider/operator.sock') {
            throw new \RuntimeException('LOCAL_AUTHENTICATED_OPERATOR_REQUIRED');
        }
        foreach (['node-1','node-4'] as $node) {
            $this->peer($config['assignment_receivers'][$node] ?? [], 'assignment/' . $node);
        }
        if ($config['assignment_receivers']['node-1']['endpoint'] === $config['assignment_receivers']['node-4']['endpoint']) {
            throw new \RuntimeException('DISTINCT_RECEIVERS_REQUIRED');
        }
        $this->peer($config['edge_remote'] ?? [], 'edge-admission');
        $p = $config['provider_policy'] ?? [];
        $this->peer(['endpoint'=>$p['provider_endpoint'] ?? null,'tls_name'=>$p['provider_tls_name'] ?? null,
            'identity'=>$p['provider_id'] ?? null], 'provider/node-1');
        if (($p['provider_id'] ?? null) !== $config['deployment_write_scope']['provider_id']) {
            throw new \RuntimeException('WRITE_PROVIDER_SCOPE_MISMATCH');
        }
    }
    public function enabled(): bool { return $this->enabled; }
    public function authorize(string $account, array $intent): void
    {
        if (!$this->enabled) return; // Disabled coordinator still supports authenticated observation.
        $scope = $this->scope();
        if ($account !== $scope['account_id']) throw new \RuntimeException('WRITE_ACCOUNT_OUT_OF_SCOPE');
        foreach (['chain_id','registry_contract_id','application_id','service','release_number','image_digest'] as $key) {
            if (($intent[$key] ?? null) !== $scope[$key]) throw new \RuntimeException('WRITE_RELEASE_OUT_OF_SCOPE');
        }
    }
    private function scope(): array
    {
        $s = $this->config['deployment_write_scope'] ?? [];
        DeploymentIdentity::validate($s);
        foreach (['account_id','provider_id'] as $key) {
            if (!is_string($s[$key] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$s[$key])) throw new \RuntimeException('WRITE_IDENTITY_REQUIRED');
        }
        if (!is_int($s['application_id'] ?? null) || $s['application_id'] < 1
            || !is_int($s['release_number'] ?? null) || $s['release_number'] < 1
            || !is_string($s['service'] ?? null) || !preg_match('/^[a-z][a-z0-9-]{0,62}$/D',$s['service'])
            || !is_string($s['image_digest'] ?? null) || !preg_match('/^sha256:[a-f0-9]{64}$/D',$s['image_digest'])
            || !is_int($s['issued_at'] ?? null) || !is_int($s['expires_at'] ?? null)
            || $s['issued_at'] > time() || $s['expires_at'] <= time()
            || $s['expires_at'] <= $s['issued_at'] || $s['expires_at'] - $s['issued_at'] > 3600) {
            throw new \RuntimeException('CURRENT_BOUNDED_WRITE_SCOPE_REQUIRED');
        }
        return $s;
    }
    private function peer(array $peer, string $role): void
    {
        $endpoint = is_string($peer['endpoint'] ?? null) ? parse_url($peer['endpoint']) : false;
        if (!is_array($endpoint) || array_diff(array_keys($endpoint),['scheme','host','port'])
            || ($endpoint['scheme'] ?? null) !== 'tcp' || !isset($endpoint['host'],$endpoint['port'])
            || $endpoint['port'] < 1 || $endpoint['port'] > 65535
            || !is_string($peer['tls_name'] ?? null) || !preg_match('/^[a-zA-Z0-9.-]{1,253}$/D',$peer['tls_name'])
            || !is_string($peer['identity'] ?? null)) throw new \RuntimeException('PINNED_PHYSICAL_PEER_REQUIRED');
        foreach ($this->config['tls']['peer_identities'] ?? [] as $pin => $identity) {
            if ($identity === $peer['identity'] && preg_match('/^[a-f0-9]{64}$/D',$pin)
                && ($this->config['tls']['peer_uris'][$pin] ?? null) === 'spiffe://greenwood-physical-v1/control-plane/'.$role) return;
        }
        throw new \RuntimeException('PHYSICAL_PEER_ROLE_PIN_REQUIRED');
    }
}
