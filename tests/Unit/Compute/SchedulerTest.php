<?php
declare(strict_types=1);
namespace Greenwood\Tests\Unit\Compute;

use Greenwood\Compute\Protocol\{ComputeRequest, TrustClass};
use Greenwood\Compute\Scheduling\{ProviderRecord, ProviderStatus, ReservationLedger, Scheduler, SchedulingFailure};
use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase
{
    private function request(int $trust = TrustClass::OPEN, int $cpu = 1000, int $memory = 1073741824, int $pids = 64): ComputeRequest
    {
        return new ComputeRequest(str_repeat("\x01", 32), 1, str_repeat("\x02", 32), '', str_repeat("\x03", 32), '',
            $trust, $cpu, $memory, $pids, 1000, 4096, 0, 100);
    }

    private function provider(string $seed, string $status = ProviderStatus::ACTIVE, array $trust = [TrustClass::OPEN],
        array $arch = ['linux/amd64'], int $cpu = 4000, int $memory = 4294967296, int $pids = 256, int $jobs = 4): ProviderRecord
    {
        return new ProviderRecord(hash('sha256', $seed, true), $status, $trust, $arch, $cpu, $memory, $pids, $jobs);
    }

    private function failure(callable $fn): SchedulingFailure
    {
        try { $fn(); } catch (SchedulingFailure $e) { return $e; }
        $this->fail('Expected SchedulingFailure');
    }

    // Section 24 — OPEN scheduling.

    public function testOpenSingleProviderSelected(): void
    {
        $p = $this->provider('a');
        $this->assertSame($p->providerId, Scheduler::schedule($this->request(), 'linux/amd64', [$p], new ReservationLedger()));
    }

    public function testOpenMultipleProvidersDeterministicWinner(): void
    {
        $providers = [$this->provider('a'), $this->provider('b'), $this->provider('c')];
        $first = Scheduler::schedule($this->request(), 'linux/amd64', $providers, new ReservationLedger());
        for ($i = 0; $i < 25; $i++) {
            shuffle($providers);
            $this->assertSame($first, Scheduler::schedule($this->request(), 'linux/amd64', $providers, new ReservationLedger()));
        }
        $expected = min(array_map(fn ($p) => $p->providerId, [$this->provider('a'), $this->provider('b'), $this->provider('c')]));
        $this->assertSame($expected, $first); // lexicographic tie-break is stable.
    }

    public function testTrustedOnlyProviderRejectsOpenRequestUnlessOpenMember(): void
    {
        $trustedOnly = $this->provider('t', ProviderStatus::ACTIVE, [TrustClass::TRUSTED]);
        $this->assertSame(SchedulingFailure::NO_TRUST_MATCH,
            $this->failure(fn () => Scheduler::schedule($this->request(), 'linux/amd64', [$trustedOnly], new ReservationLedger()))->getReason());
        $both = $this->provider('t2', ProviderStatus::ACTIVE, [TrustClass::OPEN, TrustClass::TRUSTED]);
        $this->assertSame($both->providerId, Scheduler::schedule($this->request(), 'linux/amd64', [$both], new ReservationLedger()));
    }

    public function testOpenStatusAndResourceFilters(): void
    {
        foreach ([ProviderStatus::SUSPENDED, ProviderStatus::DRAINING] as $status) {
            $p = $this->provider('s', $status);
            $this->assertSame(SchedulingFailure::NO_ACTIVE_PROVIDER,
                $this->failure(fn () => Scheduler::schedule($this->request(), 'linux/amd64', [$p], new ReservationLedger()))->getReason());
        }
        foreach ([['cpu', 999], ['memory', 1073741823], ['pids', 63]] as [$which, $capacity]) {
            $args = ['cpu' => 4000, 'memory' => 4294967296, 'pids' => 256];
            $args[$which] = $capacity;
            $p = $this->provider('r', ProviderStatus::ACTIVE, [TrustClass::OPEN], ['linux/amd64'], $args['cpu'], $args['memory'], $args['pids']);
            $this->assertSame(SchedulingFailure::NO_CAPACITY,
                $this->failure(fn () => Scheduler::schedule($this->request(), 'linux/amd64', [$p], new ReservationLedger()))->getReason());
        }
        $arm = $this->provider('arm', ProviderStatus::ACTIVE, [TrustClass::OPEN], ['linux/arm64/v8']);
        $this->assertSame(SchedulingFailure::NO_ARCH_MATCH,
            $this->failure(fn () => Scheduler::schedule($this->request(), 'linux/amd64', [$arm], new ReservationLedger()))->getReason());
        $this->assertSame(SchedulingFailure::NO_PROVIDER,
            $this->failure(fn () => Scheduler::schedule($this->request(), 'linux/amd64', [], new ReservationLedger()))->getReason());
    }

    // Section 25 — TRUSTED: exact membership, no hierarchy.

    public function testTrustedExactMembership(): void
    {
        $openOnly = $this->provider('o');
        $this->assertSame(SchedulingFailure::NO_TRUST_MATCH,
            $this->failure(fn () => Scheduler::schedule($this->request(TrustClass::TRUSTED), 'linux/amd64', [$openOnly], new ReservationLedger()))->getReason());
        $trusted = $this->provider('t', ProviderStatus::ACTIVE, [TrustClass::TRUSTED]);
        $this->assertSame($trusted->providerId,
            Scheduler::schedule($this->request(TrustClass::TRUSTED), 'linux/amd64', [$openOnly, $trusted], new ReservationLedger()));
        $suspended = $this->provider('ts', ProviderStatus::SUSPENDED, [TrustClass::TRUSTED]);
        $this->assertSame(SchedulingFailure::NO_ACTIVE_PROVIDER,
            $this->failure(fn () => Scheduler::schedule($this->request(TrustClass::TRUSTED), 'linux/amd64', [$suspended], new ReservationLedger()))->getReason());
        $starved = $this->provider('tc', ProviderStatus::ACTIVE, [TrustClass::TRUSTED], ['linux/amd64'], 999);
        $this->assertSame(SchedulingFailure::NO_CAPACITY,
            $this->failure(fn () => Scheduler::schedule($this->request(TrustClass::TRUSTED), 'linux/amd64', [$starved], new ReservationLedger()))->getReason());
    }

    // Section 26 — SOVEREIGN: only explicit sovereign members qualify.

    public function testSovereignIsolation(): void
    {
        $sovereign = $this->provider('sov', ProviderStatus::ACTIVE, [TrustClass::SOVEREIGN]);
        $trusted = $this->provider('tr', ProviderStatus::ACTIVE, [TrustClass::TRUSTED]);
        $open = $this->provider('op', ProviderStatus::ACTIVE, [TrustClass::OPEN]);
        $winner = Scheduler::schedule($this->request(TrustClass::SOVEREIGN), 'linux/amd64', [$trusted, $open, $sovereign], new ReservationLedger());
        $this->assertSame($sovereign->providerId, $winner);
        $this->assertSame(SchedulingFailure::NO_TRUST_MATCH,
            $this->failure(fn () => Scheduler::schedule($this->request(TrustClass::SOVEREIGN), 'linux/amd64', [$open, $trusted], new ReservationLedger()))->getReason());
        $starvedSov = $this->provider('sov2', ProviderStatus::ACTIVE, [TrustClass::SOVEREIGN], ['linux/amd64'], 999);
        $this->assertSame(SchedulingFailure::NO_CAPACITY,
            $this->failure(fn () => Scheduler::schedule($this->request(TrustClass::SOVEREIGN), 'linux/amd64', [$starvedSov], new ReservationLedger()))->getReason());
    }

    // Section 27 — reservation accounting; section 31 — capacity update guard.

    public function testReservationAccountingAndRelease(): void
    {
        $p = $this->provider('cap', ProviderStatus::ACTIVE, [TrustClass::OPEN], ['linux/amd64'], 2000, 4294967296, 256, 2);
        $ledger = new ReservationLedger();
        $a = $this->request(TrustClass::OPEN, 1000, 2147483648, 100);
        $ledger->reserve($p, $a);
        $this->assertSame(['cpu' => 1000, 'memory' => 2147483648, 'pids' => 100, 'jobs' => 1], $ledger->reserved($p->providerId));
        $this->assertTrue(Scheduler::hasCapacity($p, $this->request(TrustClass::OPEN, 1000, 2147483648, 156), $ledger));
        $ledger->reserve($p, $a);
        $this->assertFalse(Scheduler::hasCapacity($p, $a, $ledger)); // job slots exhausted
        $this->assertSame(SchedulingFailure::NO_CAPACITY,
            $this->failure(fn () => Scheduler::schedule($a, 'linux/amd64', [$p], $ledger))->getReason());
        $ledger->release($p, $a);
        $this->assertEquals($p->providerId, Scheduler::schedule($a, 'linux/amd64', [$p], $ledger));
        $ledger->release($p, $a); // second release drains the second reservation
        $this->expectException(\UnderflowException::class);
        $ledger->release($p, $a); // release beyond reservations must not fake capacity
    }

    public function testCapacityUpdateCannotInvalidateReservations(): void
    {
        $p = $this->provider('upd');
        $ledger = new ReservationLedger();
        $ledger->reserve($p, $this->request(TrustClass::OPEN, 2000, 2147483648, 100));
        $shrunk = new ProviderRecord($p->providerId, $p->status, $p->trustClasses, $p->architectures, 1000, 2147483648, 100, 4);
        $this->expectException(\InvalidArgumentException::class);
        $ledger->assertCapacityUpdateAllowed($shrunk); // reserved CPU > new capacity
    }

    // Section 16 — least-loaded selection; section 36 — insertion-order independence.

    public function testLeastLoadedSelectionAndOrderingIndependence(): void
    {
        $busy = $this->provider('busy');
        $idle = $this->provider('idle');
        $ledger = new ReservationLedger();
        $ledger->reserve($busy, $this->request()); // busy: 1 job, 1000/4000 cpu
        $this->assertSame($idle->providerId, Scheduler::schedule($this->request(), 'linux/amd64', [$busy, $idle], $ledger));
        $small = new ProviderRecord(hash('sha256', 'small', true), ProviderStatus::ACTIVE, [TrustClass::OPEN], ['linux/amd64'], 2000, 4294967296, 256, 4);
        $big = new ProviderRecord(hash('sha256', 'big', true), ProviderStatus::ACTIVE, [TrustClass::OPEN], ['linux/amd64'], 8000, 4294967296, 256, 4);
        $ledger->reserve($small, $this->request(TrustClass::OPEN, 500, 0, 1));
        $ledger->reserve($big, $this->request(TrustClass::OPEN, 3000, 0, 1));
        $winner = Scheduler::schedule($this->request(TrustClass::OPEN, 1, 1, 1), 'linux/amd64', [$big, $small], $ledger);
        $this->assertSame($small->providerId, $winner); // 500/2000 < 3000/8000
        $base = [$busy, $idle, $small, $big];
        $expected = Scheduler::schedule($this->request(), 'linux/amd64', $base, $ledger);
        for ($i = 0; $i < 100; $i++) {
            $shuffled = $base;
            shuffle($shuffled);
            $this->assertSame($expected, Scheduler::schedule($this->request(), 'linux/amd64', $shuffled, $ledger));
        }
    }

    // Section 28 — integer safety.

    public function testIntegerSafety(): void
    {
        $huge = new ProviderRecord(hash('sha256', 'h', true), ProviderStatus::ACTIVE, [TrustClass::OPEN],
            ['linux/amd64'], 0xFFFFFFFF, PHP_INT_MAX, 0xFFFFFFFF, 0xFFFFFFFF);
        $request = $this->request(TrustClass::OPEN, 0xFFFFFFFF, PHP_INT_MAX, 0xFFFFFFFF);
        $this->assertTrue(Scheduler::hasCapacity($huge, $request, new ReservationLedger()));
        $ledger = new ReservationLedger();
        $ledger->reserve($huge, $request); // exact fit allowed
        $this->assertFalse(Scheduler::hasCapacity($huge, $request, $ledger));
        $this->expectException(\InvalidArgumentException::class); // capacity exceedance guards, never wraps
        $ledger->reserve($huge, $request); // second reservation overflows capacity; reject, never wrap
    }

    public function testZeroCapacityRecordsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProviderRecord(hash('sha256', 'z', true), ProviderStatus::ACTIVE, [TrustClass::OPEN], ['linux/amd64'], 0, 1, 1, 1);
    }

    public function testRecordValidationRejectsInvalidTrustAndStatus(): void
    {
        $id = hash('sha256', 'v', true);
        $this->expectException(\InvalidArgumentException::class);
        new ProviderRecord($id, ProviderStatus::ACTIVE, [3], ['linux/amd64'], 1, 1, 1, 1);
    }
}
