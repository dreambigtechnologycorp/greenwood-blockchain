<?php

declare(strict_types=1);

namespace Greenwood\Edge;

/**
 * Trusted edge composition. Adapters must authenticate/authorize against current
 * application authority and resolve current provider health, never request fields.
 * No Cloudflare dependency and no process/network instructions in publish().
 */
final class EdgeController
{
    public function __construct(
        private readonly EdgeRouteRegistry $registry,
        private readonly \Closure $authorize,
        private readonly \Closure $resolveHealthyBinding,
        private readonly \Closure $applyProxy,
        private readonly \Closure $probePublic,
        private readonly array $providerAddresses,
        private readonly int $firstIngressPort,
        private readonly int $lastIngressPort,
        private readonly int $maxHealthAgeSeconds = 30,
        private readonly ?\Closure $clock = null,
    ) {
        if ($maxHealthAgeSeconds < 1 || $maxHealthAgeSeconds > 300) {
            throw new \InvalidArgumentException('Invalid health freshness policy');
        }
        if ($firstIngressPort < 1024 || $lastIngressPort > 65535 || $firstIngressPort > $lastIngressPort) {
            throw new \InvalidArgumentException('Invalid edge ingress policy');
        }
        foreach ($providerAddresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                || !preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $address)) {
                throw new \InvalidArgumentException('Edge requires private provider addresses');
            }
        }
    }

    public function publish(string $principal, int $applicationId, string $service, int $releaseId): array
    {
        return $this->registry->exclusive(function () use ($principal, $applicationId, $service, $releaseId): array {
            $hostname = $this->registry->hostname($applicationId, $service);
            if ($releaseId < 1 || ($this->authorize)($principal, $applicationId, $service, $releaseId, 'publish') !== true) {
                throw new \RuntimeException('Public service publication is not authorized');
            }
            // Validate replacement before changing the existing healthy route.
            $binding = $this->binding($applicationId, $service, $releaseId);
            $route = [
                'hostname' => $hostname, 'application_id' => $applicationId, 'service' => $service,
                'release_id' => $releaseId, 'principal' => $principal, 'desired' => 'PUBLISHED',
                'state' => 'HEALTHY', 'binding' => $binding,
            ];
            $this->registry->save($route);
            $this->reconcileLocked();
            return $this->registry->find($applicationId, $service);
        });
    }

    public function withdraw(string $principal, int $applicationId, string $service): void
    {
        $this->registry->exclusive(function () use ($principal, $applicationId, $service): void {
            $route = $this->registry->find($applicationId, $service);
            if (($this->authorize)($principal, $applicationId, $service, $route['release_id'] ?? 0, 'withdraw') !== true) {
                throw new \RuntimeException('Route withdrawal is not authorized');
            }
            if ($route !== null) {
                $route['desired'] = 'WITHDRAWN';
                $route['state'] = 'WITHDRAWING';
                $this->registry->save($route);
            }
            $this->reconcileLocked();
        });
    }

    /** Run on startup and each health cycle, including after uncertain proxy failures. */
    public function reconcile(): void
    {
        $this->registry->exclusive(fn() => $this->reconcileLocked());
    }

    private function reconcileLocked(): void
    {
        $routes = $this->registry->all();
        $healthy = [];
        foreach ($routes as &$route) {
            $route['state'] = 'WITHDRAWING';
            if ($route['desired'] === 'PUBLISHED') {
                try {
                    if (($this->authorize)($route['principal'], $route['application_id'], $route['service'], $route['release_id'], 'publish') !== true) {
                        throw new \RuntimeException('Publication authorization expired');
                    }
                    $binding = $this->binding($route['application_id'], $route['service'], $route['release_id']);
                    // A resolver cannot migrate a published route without controller approval.
                    if ($binding !== $route['binding']) throw new \RuntimeException('Binding changed; publication required');
                    $route['state'] = 'HEALTHY';
                    $healthy[] = $route;
                } catch (\Throwable) {
                    $route['state'] = 'WITHDRAWING';
                }
            }
            // Invalidate old readiness before attempting any proxy mutation.
            $this->registry->save($route);
        }
        unset($route);
        // Must return true only after config validation AND successful proxy reload.
        if (($this->applyProxy)($healthy) !== true) throw new \RuntimeException('Proxy reconciliation not confirmed');
        foreach ($routes as $route) {
            $route['state'] = $route['state'] === 'HEALTHY' ? 'PUBLISHED' : 'WITHDRAWN';
            $this->registry->save($route);
            if ($route['state'] === 'PUBLISHED') {
                try {
                    if (($this->probePublic)($route) === true) {
                        $route['state'] = 'PUBLIC_READY';
                        $this->registry->save($route);
                    }
                } catch (\Throwable) {
                    // The internal route is installed; external transport is not ready.
                }
            }
        }
    }

    private function binding(int $applicationId, string $service, int $releaseId): array
    {
        $b = ($this->resolveHealthyBinding)($applicationId, $service, $releaseId);
        $now = $this->clock === null ? time() : ($this->clock)();
        if (!is_array($b) || !is_int($b['observed_at'] ?? null)
            || $b['observed_at'] > $now || $now - $b['observed_at'] > $this->maxHealthAgeSeconds
            || ($b['ready'] ?? null) !== true || ($b['state'] ?? null) !== 'RUNNING'
            || ($b['application_id'] ?? null) !== $applicationId || ($b['service'] ?? null) !== $service
            || ($b['release_id'] ?? null) !== $releaseId
            || !is_string($b['provider_id'] ?? null)
            || !isset($this->providerAddresses[$b['provider_id']])
            || ($b['address'] ?? null) !== $this->providerAddresses[$b['provider_id']]
            || !is_int($b['port'] ?? null) || $b['port'] < $this->firstIngressPort || $b['port'] > $this->lastIngressPort
            || !is_string($b['image_digest'] ?? null) || !preg_match('/^sha256:[a-f0-9]{64}$/D', $b['image_digest'])
            || !is_string($b['instance_id'] ?? null) || $b['instance_id'] === '') {
            throw new \RuntimeException('No authorized healthy application binding');
        }
        return [
            'provider_id' => $b['provider_id'], 'address' => $b['address'], 'port' => $b['port'],
            'image_digest' => $b['image_digest'], 'instance_id' => $b['instance_id'],
        ];
    }
}
