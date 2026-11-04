<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Provider;
use Greenwood\ControlPlane\MutualTlsChannel;

/** Uses the physical protocol implementation, including revocation and peer pinning. */
final class ProviderObserverClient
{
    public function __construct(private readonly MutualTlsChannel $channel) {}

    public function verifyApplication(array $assignment, ?string $nonce = null): array
    {
        $nonce ??= bin2hex(random_bytes(32)); $sent = time();
        if (!preg_match('/^[a-f0-9]{64}$/D', $nonce)) throw new \InvalidArgumentException('Invalid observation challenge');
        $request = array_intersect_key($assignment, array_flip(['application_id','service','release_id','assignment_id']));
        if (!is_int($request['application_id'] ?? null) || !is_int($request['release_id'] ?? null)) {
            throw new \InvalidArgumentException('Integer application/release identity required');
        }
        $result = $this->channel->request($assignment['provider_endpoint'], $assignment['provider_tls_name'],
            $assignment['provider_id'], $request + ['nonce'=>$nonce]);
        return self::validate($assignment, $result, $nonce, $sent, time());
    }

    public static function validate(array $a, array $r, string $nonce, int $sent, int $now): array
    {
        foreach (['application_id','service','release_id','assignment_id','image_digest','provider_id','instance_id'] as $key) {
            if (!isset($a[$key]) || ($r[$key] ?? null) !== $a[$key]) throw new \RuntimeException('Observer identity mismatch');
        }
        if (($r['nonce'] ?? null) !== $nonce || ($r['address'] ?? null) !== $a['provider_address']
            || ($r['port'] ?? null) !== $a['ingress_port'] || ($r['state'] ?? null) !== 'RUNNING'
            || ($r['lifecycle_state'] ?? null) !== 'RUNNING' || ($r['network_state'] ?? null) !== 'HEALTHY'
            || ($r['ready'] ?? null) !== true || !is_int($r['pid'] ?? null) || $r['pid'] < 2
            || ($r['network_pid'] ?? null) !== $r['pid'] || !is_string($r['namespace_identity'] ?? null)
            || $r['namespace_identity'] === '' || !is_int($r['observed_at'] ?? null)
            || $r['observed_at'] < $sent || $r['observed_at'] > $now || $now-$r['observed_at'] > 15) {
            throw new \RuntimeException('Fresh challenge-bound provider evidence unavailable');
        }
        return $r;
    }
}
