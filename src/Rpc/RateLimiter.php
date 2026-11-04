<?php
declare(strict_types=1);
namespace Greenwood\Rpc;

/**
 * Bounded per-peer token bucket for public mutation routes. Transport-layer
 * protection only: never consulted by consensus, never consensus-visible.
 */
final class RateLimiter
{
    private array $buckets = [];
    private int $lastEviction;

    public function __construct(
        private readonly int $capacity = 20,
        private readonly float $refillPerSecond = 5.0,
        private readonly int $maxBuckets = 4096,
        private readonly int $idleSeconds = 300,
    ) {
        if ($capacity < 1 || $refillPerSecond <= 0 || $maxBuckets < 1 || $idleSeconds < 1) {
            throw new \InvalidArgumentException('Invalid rate limiter configuration');
        }
        $this->lastEviction = time();
    }

    /** Consume one token for $peer; throws ApiError 429 when exhausted. */
    public function check(string $peer): void
    {
        if ($peer === '' || strlen($peer) > 128) $peer = 'unknown';
        $now = microtime(true);
        $this->evict();
        $bucket = $this->buckets[$peer] ?? ['tokens' => (float) $this->capacity, 'updated' => $now];
        $bucket['tokens'] = min((float) $this->capacity, $bucket['tokens'] + ($now - $bucket['updated']) * $this->refillPerSecond);
        $bucket['updated'] = $now;
        if ($bucket['tokens'] < 1.0) {
            $this->buckets[$peer] = $bucket;
            $wait = (int) ceil((1.0 - $bucket['tokens']) / $this->refillPerSecond);
            $error = ApiError::rateLimited('Too many requests');
            throw new RateLimited($error, max(1, $wait));
        }
        $bucket['tokens'] -= 1.0;
        $this->buckets[$peer] = $bucket;
    }

    private function evict(): void
    {
        $now = time();
        if ($now === $this->lastEviction) return;
        $this->lastEviction = $now;
        $cutoff = $now - $this->idleSeconds;
        foreach ($this->buckets as $peer => $bucket) {
            if ($bucket['updated'] < $cutoff) unset($this->buckets[$peer]);
        }
        if (count($this->buckets) > $this->maxBuckets) {
            asort($this->buckets, SORT_NUMERIC);
            $this->buckets = array_slice($this->buckets, -$this->maxBuckets, null, true);
        }
    }

    public function bucketCount(): int
    {
        return count($this->buckets);
    }
}

/** Carries Retry-After alongside the 429 error. */
final class RateLimited extends \RuntimeException
{
    public function __construct(public readonly ApiError $error, public readonly int $retryAfterSeconds)
    {
        parent::__construct($error->getMessage());
    }
}
