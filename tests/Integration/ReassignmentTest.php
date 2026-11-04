<?php
declare(strict_types=1);
namespace Greenwood\Tests\Integration;

use Greenwood\Compute\Protocol\{ComputeAssign, ComputeReceiptV2, ProviderId, TrustClass};
use Greenwood\Compute\Verification\GenesisProviderEligibility;
use Greenwood\Crypto\{Ed25519KeyPair, ProtocolHash};
use Greenwood\State\State;
use Greenwood\Tests\Fixtures\ComputeFixture as F;
use Greenwood\Transaction\Envelope;
use PHPUnit\Framework\TestCase;

/**
 * Pass 4E: explicit requester-authorized reassignment over the frozen
 * TYPE_COMPUTE_ASSIGN path. No new wire object; generation N+1 only.
 */
final class ReassignmentTest extends TestCase
{
    private Ed25519KeyPair $requester;
    private Ed25519KeyPair $providerA; // TRUSTED + SOVEREIGN eligible
    private Ed25519KeyPair $providerB; // OPEN-only
    private string $idA;
    private string $idB;

    protected function setUp(): void
    {
        $this->requester = Ed25519KeyPair::generate();
        $this->providerA = Ed25519KeyPair::generate();
        $this->providerB = Ed25519KeyPair::generate();
        $this->idA = ProviderId::fromPublicKey($this->providerA->getPublicKey())->toBytes();
        $this->idB = ProviderId::fromPublicKey($this->providerB->getPublicKey())->toBytes();
    }

    private function state(): State
    {
        return (new State())
            ->setAccountBalance(ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $this->requester->getPublicKey()), 10000)
            ->setProviderEligibility(new GenesisProviderEligibility(
                [$this->providerA->getPublicKey()], [$this->providerA->getPublicKey()]))
            ->recomputeStateRoot();
    }

    private function request(int $trust = TrustClass::OPEN, int $nonce = 0): \Greenwood\Compute\Protocol\ComputeRequest
    {
        return F::request(['requester' => ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $this->requester->getPublicKey()),
            'requestNonce' => $nonce, 'deadlineHeight' => 10, 'trustClass' => $trust]);
    }

    private function assignment(string $requestId, Ed25519KeyPair $provider, int $generation): ComputeAssign
    {
        return new ComputeAssign($requestId, ProviderId::fromPublicKey($provider->getPublicKey())->toBytes(),
            $provider->getPublicKey(), $generation, 10);
    }

    private function tx(int $type, string $payload, int $nonce, ?Ed25519KeyPair $key = null): Envelope
    {
        $key ??= $this->requester;
        $tx = new Envelope(1, 'compute-4e', ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $key->getPublicKey()),
            $nonce, $type, $payload, '', $key->getPublicKey());
        $tx->setSignature($key->sign($tx->getSigningDigest()));
        return Envelope::decode($tx->getCanonicalBytes());
    }

    private function receipt(string $requestId, Ed25519KeyPair $provider, int $generation): ComputeReceiptV2
    {
        return (new ComputeReceiptV2(F::receipt(['requestId' => $requestId,
            'providerId' => ProviderId::fromPublicKey($provider->getPublicKey())->toBytes()]), $generation))->sign($provider);
    }

    private function apply(State $state, Envelope $tx, int $height): void
    {
        $state->applyTransaction($tx, 'compute-4e', $height);
    }

    private function rejects(State $state, Envelope $tx, int $height): void
    {
        $root = $state->getStateRoot();
        try {
            $state->applyTransaction($tx, 'compute-4e', $height);
            self::fail('Invalid reassignment accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame($root, $state->getStateRoot(), 'Rejected reassignment mutated state');
        }
    }

    public function testReassignmentFlowStaleReceiptAndSettlement(): void
    {
        $state = $this->state();
        $request = $this->request();
        $id = $request->getId();
        $this->apply($state, $this->tx(7, $request->toBytes(), 0), 1);
        $this->apply($state, $this->tx(8, $this->assignment($id, $this->providerA, 1)->toBytes(), 1), 2);
        // Explicit requester-authorized reassignment: gen 1 -> gen 2, provider A -> B.
        $this->apply($state, $this->tx(8, $this->assignment($id, $this->providerB, 2)->toBytes(), 2), 3);
        self::assertSame(2, $state->getComputeState()->assignment($id)->generation);
        // Single active generation: only gen2 reservation counts now.
        self::assertSame(['cpu' => 0, 'memory' => 0, 'pids' => 0, 'jobs' => 0], $state->getComputeState()->reserved($this->idA));
        self::assertSame(1, $state->getComputeState()->reserved($this->idB)['jobs']);
        // Late gen1 receipt from the old provider: valid signature, still rejected as stale.
        $this->rejects($state, $this->tx(9, $this->receipt($id, $this->providerA, 1)->toSignedBytes(), 0, $this->providerA), 4);
        // Wrong-provider receipt for gen2 (A is eligible but not assigned) rejected.
        $this->rejects($state, $this->tx(9, $this->receipt($id, $this->providerA, 2)->toSignedBytes(), 0, $this->providerA), 4);
        // Assigned provider gen2 receipt settles.
        $this->apply($state, $this->tx(9, $this->receipt($id, $this->providerB, 2)->toSignedBytes(), 0, $this->providerB), 4);
        // Settled: no further reassignment, duplicate receipt rejected.
        $this->rejects($state, $this->tx(8, $this->assignment($id, $this->providerA, 3)->toBytes(), 3), 5);
        $this->rejects($state, $this->tx(9, $this->receipt($id, $this->providerB, 2)->toSignedBytes(), 1, $this->providerB), 5);
        self::assertSame(['cpu' => 0, 'memory' => 0, 'pids' => 0, 'jobs' => 0], $state->getComputeState()->reserved($this->idB));
    }

    public function testReassignmentAuthorizationAndGenerationRules(): void
    {
        $state = $this->state();
        $request = $this->request();
        $id = $request->getId();
        $this->apply($state, $this->tx(7, $request->toBytes(), 0), 1);
        $this->apply($state, $this->tx(8, $this->assignment($id, $this->providerA, 1)->toBytes(), 1), 2);
        // Wrong account signs gen2.
        $this->rejects($state, $this->tx(8, $this->assignment($id, $this->providerB, 2)->toBytes(), 0, $this->providerB), 3);
        // Provider cannot self-authorize its own reassignment.
        $this->rejects($state, $this->tx(8, $this->assignment($id, $this->providerA, 2)->toBytes(), 0, $this->providerA), 3);
        // Generation skip 1 -> 3 and same-generation 1 -> 1 rejected.
        $this->rejects($state, $this->tx(8, $this->assignment($id, $this->providerB, 3)->toBytes(), 2), 3);
        $this->rejects($state, $this->tx(8, $this->assignment($id, $this->providerB, 1)->toBytes(), 2), 3);
        // Valid gen2 accepted.
        $this->apply($state, $this->tx(8, $this->assignment($id, $this->providerB, 2)->toBytes(), 2), 3);
    }

    public function testReceiptVsReassignmentOrdering(): void
    {
        // Case A: gen1 receipt finalizes first -> gen2 assignment rejected.
        $state = $this->state();
        $request = $this->request();
        $id = $request->getId();
        $this->apply($state, $this->tx(7, $request->toBytes(), 0), 1);
        $this->apply($state, $this->tx(8, $this->assignment($id, $this->providerA, 1)->toBytes(), 1), 2);
        $this->apply($state, $this->tx(9, $this->receipt($id, $this->providerA, 1)->toSignedBytes(), 0, $this->providerA), 3);
        $this->rejects($state, $this->tx(8, $this->assignment($id, $this->providerB, 2)->toBytes(), 2), 4);

        // Case B: gen2 assignment finalizes first -> gen1 receipt rejected as stale.
        $state = $this->state();
        $request = $this->request();
        $id = $request->getId();
        $this->apply($state, $this->tx(7, $request->toBytes(), 0), 1);
        $this->apply($state, $this->tx(8, $this->assignment($id, $this->providerA, 1)->toBytes(), 1), 2);
        $this->apply($state, $this->tx(8, $this->assignment($id, $this->providerB, 2)->toBytes(), 2), 3);
        $this->rejects($state, $this->tx(9, $this->receipt($id, $this->providerA, 1)->toSignedBytes(), 0, $this->providerA), 4);
    }

    public function testTrustPreservedAcrossReassignment(): void
    {
        foreach ([TrustClass::TRUSTED, TrustClass::SOVEREIGN] as $class) {
            $state = $this->state();
            $request = $this->request($class);
            $id = $request->getId();
            $this->apply($state, $this->tx(7, $request->toBytes(), 0), 1);
            $this->apply($state, $this->tx(8, $this->assignment($id, $this->providerA, 1)->toBytes(), 1), 2);
            // OPEN-only provider B can never take over a TRUSTED/SOVEREIGN request.
            $this->rejects($state, $this->tx(8, $this->assignment($id, $this->providerB, 2)->toBytes(), 2), 3);
            // Same-class provider A may be reassigned (requester explicitly authorizes).
            $this->apply($state, $this->tx(8, $this->assignment($id, $this->providerA, 2)->toBytes(), 2), 3);
        }
    }

    public function testMultiGenerationSequenceAndReplay(): void
    {
        $state = $this->state();
        $request = $this->request();
        $id = $request->getId();
        $this->apply($state, $this->tx(7, $request->toBytes(), 0), 1);
        $this->apply($state, $this->tx(8, $this->assignment($id, $this->providerA, 1)->toBytes(), 1), 2);
        $this->apply($state, $this->tx(8, $this->assignment($id, $this->providerB, 2)->toBytes(), 2), 3);
        $this->apply($state, $this->tx(8, $this->assignment($id, $this->providerA, 3)->toBytes(), 3), 4);
        // Old-generation receipts rejected at every step.
        $this->rejects($state, $this->tx(9, $this->receipt($id, $this->providerA, 1)->toSignedBytes(), 0, $this->providerA), 5);
        $this->rejects($state, $this->tx(9, $this->receipt($id, $this->providerB, 2)->toSignedBytes(), 0, $this->providerB), 5);
        $this->apply($state, $this->tx(9, $this->receipt($id, $this->providerA, 3)->toSignedBytes(), 0, $this->providerA), 5);
        // Replay from derived records: identical records, reservations and lifecycle.
        $compute = \Greenwood\Compute\Protocol\ComputeState::fromDerivedRecords(
            $state->getComputeState()->logicalRecords(), $state->getProviderEligibility());
        self::assertSame($state->getComputeState()->logicalRecords(), $compute->logicalRecords());
        self::assertSame(3, $compute->assignment($id)->generation);
        self::assertTrue($compute->terminal($id));
        self::assertSame(['cpu' => 0, 'memory' => 0, 'pids' => 0, 'jobs' => 0], $compute->reserved($this->idA));
    }

    public function testGenerationOverflowBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComputeAssign::nextGeneration(0xffffffff);
    }

    public function testAssignmentHistoryReconstructedFromFinalizedBlocks(): void
    {
        $dir = sys_get_temp_dir().'/greenwood-4e-'.bin2hex(random_bytes(6));
        $genesis = new \Greenwood\Genesis\Genesis('compute-4e');
        $genesis->addValidator('A', $this->providerA);
        $genesis->addInitialAccount('requester', $this->requester, 10000);
        $files = new \Greenwood\Persistence\BlockFilesystem($dir.'/blocks');
        $db = new \Greenwood\Persistence\StateSqlite($dir.'/state.sqlite');
        $chain = new \Greenwood\Node\Chain($genesis, $files, $db);
        $proposer = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $this->providerA->getPublicKey());

        $request = $this->request();
        $id = $request->getId();
        $chain->finalize($chain->build([$this->tx(7, $request->toBytes(), 0)], 0, $proposer));
        $chain->finalize($chain->build([$this->tx(8, $this->assignment($id, $this->providerA, 1)->toBytes(), 1)], 0, $proposer));
        $chain->finalize($chain->build([$this->tx(8, $this->assignment($id, $this->providerB, 2)->toBytes(), 2)], 0, $proposer));
        $chain->finalize($chain->build([$this->tx(9, $this->receipt($id, $this->providerB, 2)->toSignedBytes(), 0, $this->providerB)], 0, $proposer));

        $history = $chain->assignmentHistory($id);
        self::assertSame([
            ['generation' => 1, 'provider_id' => bin2hex($this->idA), 'height' => 2],
            ['generation' => 2, 'provider_id' => bin2hex($this->idB), 'height' => 3],
        ], $history);
        // Query endpoint exposes the same replay-derived history.
        $view = \Greenwood\Rpc\ProviderQueries::handle($chain, '/compute/requests/'.bin2hex($id).'/history');
        self::assertSame($history, $view['assignments']);
        self::assertSame(2, $chain->state->getComputeState()->assignment($id)->generation);
        $db->close();
    }
}
