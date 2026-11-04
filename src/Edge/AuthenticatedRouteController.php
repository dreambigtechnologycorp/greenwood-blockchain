<?php

declare(strict_types=1);
namespace Greenwood\Edge;

use Greenwood\ControlPlane\MutualTlsChannel;

/** G2 desired-state admission only. Never claims proxy publication or withdrawal. */
final class AuthenticatedRouteController
{
    public function __construct(
        private readonly EdgeRouteRegistry $routes,
        private readonly ApprovedDeploymentStore $assignments,
        private readonly \Closure $authority,
        private readonly \Closure $observeProvider,
    ) {}

    public static function providerObserver(MutualTlsChannel $channel): \Closure
    {
        return static fn(array $a, string $nonce): array => $channel->request($a['provider_endpoint'], $a['provider_tls_name'], $a['provider_id'], [
            'application_id'=>$a['application_id'], 'service'=>$a['service'], 'release_id'=>$a['release_id'], 'assignment_id'=>$a['assignment_id'], 'nonce'=>$nonce,
        ]);
    }

    /** principal is supplied by MutualTlsChannel::serveOne, never request JSON. */
    public function handle(string $principal, array $request): array
    {
        $keys = ['action','application_id','service','release_id'];
        if (array_diff(array_keys($request), $keys) || array_diff($keys, array_keys($request))
            || !is_int($request['application_id']) || !is_string($request['service']) || !is_int($request['release_id'])
            || !in_array($request['action'], ['publish','planned-replacement','stop'], true)) throw new \InvalidArgumentException('Invalid route identity request');
        return $this->routes->exclusive(function () use ($principal, $request): array {
            $id=$request['application_id']; $service=$request['service']; $release=$request['release_id'];
            $hostname=$this->routes->hostname($id,$service);
            $a=$this->assignments->get($id,$service,$release);
            if (($this->authority)($principal,$a) !== true) throw new \RuntimeException('Application deployment not authorized');
            if ($request['action'] !== 'publish') {
                $row=$this->routes->find($id,$service) ?? ['hostname'=>$hostname,'application_id'=>$id,'service'=>$service,'release_id'=>$release,'principal'=>$principal];
                $row['intent']='WITHDRAWN'; $row['desired']='WITHDRAWN'; $row['state']='WITHDRAWAL_PENDING';
                $row['reason']=$request['action']==='planned-replacement'?'PLANNED_REPLACEMENT':'OPERATOR_STOP';
                $row['proxy_state']='UNKNOWN'; $row['observed_at']=time(); $this->routes->save($row);
                return $row;
            }
            // Preserve an existing healthy assignment if replacement evidence fails.
            $observation=$this->observation($a);
            if (($this->authority)($principal,$a) !== true) throw new \RuntimeException('Authorization changed during observation');
            $row=['hostname'=>$hostname,'application_id'=>$id,'service'=>$service,'release_id'=>$release,'principal'=>$principal,
                'assignment_id'=>$a['assignment_id'],'intent'=>'PUBLISHED','desired'=>'PUBLISHED','state'=>'HEALTHY',
                'reason'=>'AWAITING_EDGE_RECONCILIATION','proxy_state'=>'UNKNOWN','binding'=>$this->binding($a),
                'observed_at'=>$observation['observed_at']];
            $this->routes->save($row); return $row;
        });
    }

    public function reconcile(?\Closure $applyProxy = null): array
    {
        return $this->routes->exclusive(function () use ($applyProxy): array {
            $result=[];
            foreach ($this->routes->all() as $row) {
                if (($row['intent'] ?? 'WITHDRAWN') === 'PUBLISHED') {
                    // Invalidate stale healthy evidence durably before remote reads.
                    $row['desired']='WITHDRAWN'; $row['state']='WITHDRAWAL_PENDING'; $row['proxy_state']='UNKNOWN';
                    $row['reason']='OBSERVATION_UNAVAILABLE'; unset($row['error_class']); $this->routes->save($row);
                    try {
                        $a=$this->assignments->get($row['application_id'],$row['service'],$row['release_id']);
                        if (($this->authority)($row['principal'],$a) !== true) {
                            $row['reason']='AUTHORIZATION_LOST'; $row['intent']='WITHDRAWN';
                        } elseif (($row['assignment_id'] ?? '') !== $a['assignment_id']) {
                            $row['reason']='ASSIGNMENT_CHANGED';
                        } else {
                            $o=$this->observation($a);
                            if (($this->authority)($row['principal'],$a) !== true) {
                                $row['reason']='AUTHORIZATION_LOST'; $row['intent']='WITHDRAWN';
                            } else {
                                $row['desired']='PUBLISHED'; $row['state']='HEALTHY'; $row['reason']='AWAITING_EDGE_RECONCILIATION'; $row['observed_at']=$o['observed_at'];
                            }
                        }
                    } catch (\Throwable $e) {
                        $row['error_class']=$e::class;
                        if ($e instanceof ProviderObservationException) $row['reason']=$e->reason;
                        // No assertion about installed Apache state is made here.
                    }
                    $this->routes->save($row);
                }
                $result[]=$row;
            }
            if ($applyProxy !== null) {
                foreach ($result as &$pending) {
                    $pending['proxy_state']='UNKNOWN';$pending['public_ready']=false;$pending['effective_generation']=null;
                    $this->routes->save($pending);
                }
                unset($pending);
                $applied = $applyProxy($result);
                foreach ($result as &$row) {
                    $row['public_ready'] = false;
                    $row['proxy_state'] = ($applied['confirmed'] ?? false) ? 'CONFIRMED' : 'UNKNOWN';
                    $row['effective_generation'] = ($applied['confirmed'] ?? false) ? $applied['generation'] : null;
                    if ($applied['confirmed'] ?? false) {
                        $row['state'] = $row['desired'] === 'PUBLISHED' ? 'PUBLISHED' : 'WITHDRAWN';
                        $row['reason'] = 'APACHE_LOOPBACK_VERIFIED';
                    } else {
                        $row['state'] = 'APPLY_UNCERTAIN';
                        $row['reason'] = $applied['status'] ?? 'APACHE_APPLY_FAILED';
                    }
                    $this->routes->save($row);
                }
                unset($row);
            }
            return $result;
        });
    }

    private function observation(array $a): array
    {
        $nonce=bin2hex(random_bytes(32)); $sent=time();
        $o=($this->observeProvider)($a,$nonce); $now=time();
        if (($o['nonce'] ?? '')!==$nonce || ($o['assignment_id'] ?? '')!==$a['assignment_id']
            || !is_int($o['observed_at'] ?? null) || $o['observed_at']<$sent || $o['observed_at']>$now || $now-$o['observed_at']>15
            || ($o['application_id'] ?? null)!==$a['application_id'] || ($o['release_id'] ?? null)!==$a['release_id'] || ($o['service'] ?? null)!==$a['service']
            || ($o['provider_id'] ?? null)!==$a['provider_id'] || ($o['address'] ?? null)!==$a['provider_address'] || ($o['port'] ?? null)!==$a['ingress_port']
            || ($o['image_digest'] ?? null)!==$a['image_digest'] || ($o['instance_id'] ?? null)!==$a['instance_id']) throw new ProviderObservationException('INVALID_OBSERVATION');
        if (($o['network_state'] ?? '') === 'CLEANUP_REQUIRED') throw new ProviderObservationException('NETWORK_CLEANUP_REQUIRED');
        if (($o['lifecycle_state'] ?? '') === 'FAILED') throw new ProviderObservationException('REPLACEMENT_START_FAILED');
        if (($o['state'] ?? '') !== 'RUNNING' || ($o['network_state'] ?? '') !== 'HEALTHY' || ($o['ready'] ?? false) !== true) throw new ProviderObservationException('PROVIDER_UNHEALTHY');
        if (!is_int($o['pid'] ?? null) || $o['pid'] < 2 || ($o['network_pid'] ?? null) !== $o['pid']
            || !is_string($o['namespace_identity'] ?? null) || $o['namespace_identity'] === '') throw new ProviderObservationException('INVALID_RUNTIME_IDENTITY');
        return $o;
    }
    private function binding(array $a): array
    {
        return ['provider_id'=>$a['provider_id'],'address'=>$a['provider_address'],'port'=>$a['ingress_port'],'image_digest'=>$a['image_digest'],'instance_id'=>$a['instance_id']];
    }
}
