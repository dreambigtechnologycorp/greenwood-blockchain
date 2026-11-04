<?php
declare(strict_types=1);
namespace Greenwood\Tests\Integration;

use Greenwood\Compute\Protocol\{ComputeAssign, ProviderCapability, ProviderId, TrustClass};
use Greenwood\Compute\Scheduling\{FinalizedStateAdapter, ReservationLedger, Scheduler, SchedulingFailure};
use Greenwood\Compute\Verification\GenesisProviderEligibility;
use Greenwood\Crypto\{Ed25519KeyPair, ProtocolHash};
use Greenwood\State\State;
use Greenwood\Tests\Fixtures\ComputeFixture as F;
use Greenwood\Transaction\Envelope;
use PHPUnit\Framework\TestCase;

final class ProviderCapabilityTest extends TestCase
{
    private Ed25519KeyPair $provider;
    private string $providerId;

    protected function setUp(): void
    {
        $this->provider = F::key();
        $this->providerId = F::providerId()->toBytes();
    }

    private function state(): State
    {
        return (new State())->setAccountBalance(F::requester(), 10000)
            ->setProviderEligibility(new GenesisProviderEligibility([$this->provider->getPublicKey()], []))
            ->recomputeStateRoot();
    }

    private function capability(array $overrides = []): ProviderCapability
    {
        return new ProviderCapability(
            $this->providerId, $this->provider->getPublicKey(),
            $overrides['status'] ?? ProviderCapability::STATUS_ACTIVE,
            $overrides['nonce'] ?? 1,
            $overrides['architectures'] ?? ['linux/arm64/v8', 'linux/amd64'],
            $overrides['cpuCapacityMillis'] ?? 8000,
            $overrides['memoryCapacityBytes'] ?? 1073741824,
            $overrides['pidCapacity'] ?? 4096,
            $overrides['maxConcurrentJobs'] ?? 8);
    }

    private function tx(int $type, string $payload, int $nonce, ?Ed25519KeyPair $key = null): Envelope
    {
        $key ??= $this->provider;
        $tx = new Envelope(1, 'compute-l1', ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $key->getPublicKey()),
            $nonce, $type, $payload, '', $key->getPublicKey());
        $tx->setSignature($key->sign($tx->getSigningDigest()));
        return Envelope::decode($tx->getCanonicalBytes());
    }

    private function apply(State $state, Envelope $tx, int $height): void
    {
        $root = $state->getStateRoot();
        $state->applyTransaction($tx, 'compute-l1', $height);
        self::assertNotSame($root, $state->getStateRoot());
    }

    private function rejects(State $state, Envelope $tx, int $height): void
    {
        $root = $state->getStateRoot();
        try {
            $state->applyTransaction($tx, 'compute-l1', $height);
            self::fail('Invalid capability transaction accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame($root, $state->getStateRoot());
        }
    }

    public function testRegistrationRoundtripAndCanonicalId(): void
    {
        $capability = $this->capability();
        self::assertEquals($capability, ProviderCapability::decode($capability->toBytes()));
        self::assertSame($capability->getId(), ProtocolHash::hash(ProviderCapability::DOMAIN, $capability->toBytes()));
        $state = $this->state();
        $this->apply($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY, $capability->toBytes(), 0), 1);
        self::assertEquals($capability, $state->getComputeState()->capabilities()[$this->providerId]);
        self::assertSame(['cpu' => 0, 'memory' => 0, 'pids' => 0, 'jobs' => 0],
            $state->getComputeState()->reserved($this->providerId));
    }

    public function testAuthorizationRequiresProviderKeyBinding(): void
    {
        $state = $this->state();
        $other = Ed25519KeyPair::generate();
        // Sender account not derived from the capability's provider key.
        $this->rejects($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY, $this->capability()->toBytes(), 0, $other), 1);
        // Provider key cannot mint a capability for a different provider id.
        $forged = new ProviderCapability(ProviderId::fromPublicKey($other->getPublicKey())->toBytes(),
            $other->getPublicKey(), 0, 1, ['linux/amd64'], 1000, 1024, 64, 1);
        $this->rejects($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY, $forged->toBytes(), 1), 1);
    }

    public function testNonceSequence(): void
    {
        $state = $this->state();
        $this->apply($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY, $this->capability()->toBytes(), 0), 1);
        $this->rejects($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY, $this->capability(['nonce' => 1])->toBytes(), 1), 2);
        $this->rejects($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY, $this->capability(['nonce' => 3])->toBytes(), 1), 2);
        $this->apply($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY, $this->capability(['nonce' => 2])->toBytes(), 1), 2);
        self::assertSame(2, $state->getComputeState()->capabilities()[$this->providerId]->nonce);
    }

    public function testCodecRejectsInvalidRecords(): void
    {
        foreach ([['architectures' => ['linux/sparc']], ['architectures' => ['linux/amd64', 'linux/amd64']],
                  ['architectures' => []], ['cpuCapacityMillis' => 0], ['memoryCapacityBytes' => 0],
                  ['pidCapacity' => 0], ['maxConcurrentJobs' => 0], ['status' => 3], ['nonce' => 0]] as $overrides) {
            try {
                $this->capability($overrides);
                self::fail('Invalid capability accepted');
            } catch (\InvalidArgumentException) { self::addToAssertionCount(1); }
        }
    }

    public function testStatusTransitionsKeepAssignmentsIntact(): void
    {
        $state = $this->state();
        $request = F::request(['requestNonce' => 1, 'deadlineHeight' => 10, 'trustClass' => TrustClass::TRUSTED]);
        $this->apply($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY, $this->capability()->toBytes(), 0), 1);
        $this->apply($state, $this->tx(7, $request->toBytes(), 1), 2);
        $assignment = new ComputeAssign($request->getId(), $this->providerId, $this->provider->getPublicKey(), 1, 10);
        $this->apply($state, $this->tx(8, $assignment->toBytes(), 2), 3);
        $this->apply($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY,
            $this->capability(['nonce' => 2, 'status' => ProviderCapability::STATUS_DRAINING])->toBytes(), 3), 4);
        self::assertSame(ProviderCapability::STATUS_DRAINING, $state->getComputeState()->capabilities()[$this->providerId]->status);
        $this->apply($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY,
            $this->capability(['nonce' => 3, 'status' => ProviderCapability::STATUS_SUSPENDED])->toBytes(), 4), 5);
        $reserved = $state->getComputeState()->reserved($this->providerId);
        self::assertSame($request->getMemoryLimitBytes(), $reserved['memory']);
        self::assertSame(1, $reserved['jobs']);
    }

    public function testCapacityUpdateCannotUndercutReservations(): void
    {
        $state = $this->state();
        $request = F::request(['requestNonce' => 1, 'deadlineHeight' => 10]);
        $this->apply($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY, $this->capability()->toBytes(), 0), 1);
        $this->apply($state, $this->tx(7, $request->toBytes(), 1), 2);
        $assignment = new ComputeAssign($request->getId(), $this->providerId, $this->provider->getPublicKey(), 1, 10);
        $this->apply($state, $this->tx(8, $assignment->toBytes(), 2), 3);
        // Increase is fine.
        $this->apply($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY,
            $this->capability(['nonce' => 2, 'cpuCapacityMillis' => 16000])->toBytes(), 3), 4);
        // Reduction above reservations is fine.
        $stillFits = $this->capability(['nonce' => 3, 'memoryCapacityBytes' => $request->getMemoryLimitBytes()]);
        $this->apply($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY, $stillFits->toBytes(), 4), 5);
        // Reduction below reserved memory rejects.
        $undercut = $this->capability(['nonce' => 4, 'memoryCapacityBytes' => $request->getMemoryLimitBytes() - 1]);
        $this->rejects($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY, $undercut->toBytes(), 5), 6);
    }

    public function testSchedulerAssemblesInputsFromFinalizedState(): void
    {
        $state = $this->state();
        $eligibility = $state->getProviderEligibility();
        $cap = $this->capability(['architectures' => ['linux/arm64/v8']]);
        $this->apply($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY, $cap->toBytes(), 0), 1);
        $records = FinalizedStateAdapter::providerRecords($state->getComputeState(), $eligibility, 1);
        self::assertCount(1, $records);
        // Trust comes from eligibility only: OPEN is universal, TRUSTED per genesis keys.
        self::assertSame([TrustClass::OPEN, TrustClass::TRUSTED], $records[0]->trustClasses);
        self::assertSame(['linux/arm64/v8'], $records[0]->architectures);
        foreach ([TrustClass::OPEN, TrustClass::TRUSTED] as $class) {
            $request = F::request(['requestNonce' => 0, 'deadlineHeight' => 10, 'trustClass' => $class]);
            self::assertSame($this->providerId,
                Scheduler::schedule($request, 'linux/arm64/v8', $records, FinalizedStateAdapter::ledger($state->getComputeState())));
        }
        // SOVEREIGN rejected: capability can never upgrade trust.
        $sovereign = F::request(['requestNonce' => 0, 'deadlineHeight' => 10, 'trustClass' => TrustClass::SOVEREIGN]);
        try {
            Scheduler::schedule($sovereign, 'linux/arm64/v8', $records, new ReservationLedger());
            self::fail('Capability promoted provider into SOVEREIGN');
        } catch (SchedulingFailure $e) {
            self::assertSame(SchedulingFailure::NO_TRUST_MATCH, $e->getReason());
        }
        // Architecture rejection uses the finalized capability vocabulary.
        $request = F::request(['requestNonce' => 0, 'deadlineHeight' => 10, 'trustClass' => TrustClass::OPEN]);
        try {
            Scheduler::schedule($request, 'linux/amd64', $records, new ReservationLedger());
            self::fail('Wrong architecture scheduled');
        } catch (SchedulingFailure $e) {
            self::assertSame(SchedulingFailure::NO_ARCH_MATCH, $e->getReason());
        }
        // DRAINING excludes new work.
        $draining = $this->capability(['nonce' => 2, 'status' => ProviderCapability::STATUS_DRAINING]);
        $this->apply($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY, $draining->toBytes(), 1), 2);
        $records = FinalizedStateAdapter::providerRecords($state->getComputeState(), $eligibility, 2);
        try {
            Scheduler::schedule($request, 'linux/arm64/v8', $records, new ReservationLedger());
            self::fail('DRAINING provider scheduled');
        } catch (SchedulingFailure $e) {
            self::assertSame(SchedulingFailure::NO_ACTIVE_PROVIDER, $e->getReason());
        }
    }

    public function testReplayDerivedRecordsPreservesCapabilityAndReservations(): void
    {
        $state = $this->state();
        $request = F::request(['requestNonce' => 1, 'deadlineHeight' => 10]);
        $this->apply($state, $this->tx(Envelope::TYPE_PROVIDER_CAPABILITY, $this->capability()->toBytes(), 0), 1);
        $this->apply($state, $this->tx(7, $request->toBytes(), 1), 2);
        $assignment = new ComputeAssign($request->getId(), $this->providerId, $this->provider->getPublicKey(), 1, 10);
        $this->apply($state, $this->tx(8, $assignment->toBytes(), 2), 3);
        $root = $state->getStateRoot();
        $compute = \Greenwood\Compute\Protocol\ComputeState::fromDerivedRecords(
            $state->getComputeState()->logicalRecords(), $state->getProviderEligibility());
        self::assertSame($state->getComputeState()->logicalRecords(), $compute->logicalRecords());
        self::assertEquals($this->capability(), $compute->capabilities()[$this->providerId]);
        self::assertSame($state->getComputeState()->reserved($this->providerId), $compute->reserved($this->providerId));
        self::assertSame($root, $state->getStateRoot(), 'Replay mutated state');
    }
}
