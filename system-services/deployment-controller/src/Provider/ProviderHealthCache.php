<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Provider;

/** Liveness hints only: never stores deployment evidence or a READY assertion. */
final class ProviderHealthCache
{
    private array $cache = [];
    public function __construct(private readonly int $ttl = 5) {
        if ($ttl < 1 || $ttl > 15) throw new \InvalidArgumentException('Invalid liveness TTL');
    }
    public function set(string $providerId, bool $alive): void {
        if (count($this->cache)>=1024) array_shift($this->cache);
        $this->cache[$providerId]=['alive'=>$alive,'expires_at'=>time()+$this->ttl];
    }
    public function get(string $providerId): ?bool {
        $value=$this->cache[$providerId] ?? null;
        return $value!==null && $value['expires_at']>time() ? $value['alive'] : null;
    }
}
