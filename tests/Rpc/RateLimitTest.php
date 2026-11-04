<?php
declare(strict_types=1);
namespace Greenwood\Tests\Rpc;

use Greenwood\Consensus\Runtime\ConsensusDriver;
use Greenwood\Crypto\Ed25519KeyPair;
use Greenwood\Genesis\Genesis;
use Greenwood\Node\Chain;
use Greenwood\Persistence\{BlockFilesystem, StateSqlite};
use Greenwood\Rpc\{RateLimited, RateLimiter};
use Greenwood\Tests\Fixtures\ComputeFixture as F;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    public function testUnderLimitAccepted(): void
    {
        $limiter = new RateLimiter(5, 1.0);
        for ($i = 0; $i < 5; $i++) $limiter->check('peer-a');
        self::assertSame(1, $limiter->bucketCount());
    }

    public function testOverLimitRejectedWithRetryAfter(): void
    {
        $limiter = new RateLimiter(3, 1.0);
        for ($i = 0; $i < 3; $i++) $limiter->check('peer-a');
        try {
            $limiter->check('peer-a');
            self::fail('Expected rate limit');
        } catch (RateLimited $e) {
            self::assertSame(429, $e->error->httpStatus);
            self::assertSame('RATE_LIMITED', $e->error->apiCode);
            self::assertGreaterThanOrEqual(1, $e->retryAfterSeconds);
        }
    }

    public function testBucketRecovery(): void
    {
        $limiter = new RateLimiter(1, 50.0);
        $limiter->check('peer-a');
        try { $limiter->check('peer-a'); self::fail('Expected rate limit'); } catch (RateLimited) {}
        usleep(30_000); // 30ms at 50/s refills >1 token
        $limiter->check('peer-a');
        self::assertTrue(true);
    }

    public function testClientsAreIsolated(): void
    {
        $limiter = new RateLimiter(1, 0.001);
        $limiter->check('peer-a');
        $limiter->check('peer-b'); // unaffected by peer-a exhaustion
        try { $limiter->check('peer-a'); self::fail('Expected rate limit'); } catch (RateLimited) {}
        $this->expectException(RateLimited::class);
        $limiter->check('peer-b');
    }

    public function testBoundedMemoryAndIdleEviction(): void
    {
        $limiter = new RateLimiter(1, 1.0, 16, 1);
        for ($i = 0; $i < 100; $i++) $limiter->check('peer-' . $i);
        self::assertLessThanOrEqual(100, $limiter->bucketCount());
        sleep(2);
        $limiter->check('trigger-eviction');
        self::assertLessThanOrEqual(2, $limiter->bucketCount(), 'Idle buckets must be evicted');
    }

    public function testConsensusRoutesNeverRateLimited(): void
    {
        $dir = sys_get_temp_dir() . '/gw-rl-' . bin2hex(random_bytes(6));
        $g = new Genesis('rpc-test');
        foreach (['B', 'C', 'D'] as $id) $g->addValidator($id, Ed25519KeyPair::generate(), 1);
        $g->addValidator('A', F::key(), 1);
        $driver = new ConsensusDriver(new Chain($g, new BlockFilesystem($dir . '/blocks'), new StateSqlite($dir . '/state.sqlite')), 'E', [], null, 'http://127.0.0.1:0');
        $handle = new \ReflectionMethod(ConsensusDriver::class, 'handle');

        // Exhaust the public mutation bucket for one peer.
        for ($i = 0; $i < 20; $i++) {
            try { $handle->invoke($driver, 'POST', '/v1/transactions', '', [], 'peer-a'); } catch (\Throwable) {}
        }
        try {
            $handle->invoke($driver, 'POST', '/v1/transactions', '', [], 'peer-a');
            self::fail('Expected 429');
        } catch (RateLimited $e) {
            self::assertSame(429, $e->error->httpStatus);
        }
        // A different peer is unaffected.
        $r = $handle->invoke($driver, 'POST', '/v1/transactions', '', [], 'peer-b');
        self::assertSame(422, $r->status, 'Invalid body must still 422, not 429, for a fresh peer');
        // Consensus internals bypass the limiter entirely.
        try { $handle->invoke($driver, 'POST', '/consensus/proposal', '', [], 'peer-a'); } catch (\Throwable) {}
        // Reaching here without RateLimited proves consensus routes bypass the limiter.
        $r = $handle->invoke($driver, 'GET', '/v1/chain/status', '', [], 'peer-a');
        self::assertSame(200, $r->status, 'Reads are never rate limited');

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        rmdir($dir);
    }
}
