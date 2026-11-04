<?php
declare(strict_types=1);
namespace Greenwood\Tests\Integration;

use Greenwood\Compute\Codec\{ComputeReceiptCodec, ComputeReceiptV2Codec};
use Greenwood\Compute\Protocol\{ComputeAssign, ComputeReceiptV2, ProviderId, RequestState};
use Greenwood\Compute\Verification\GenesisProviderEligibility;
use Greenwood\Crypto\{Ed25519KeyPair, ProtocolHash};
use Greenwood\Genesis\Genesis;
use Greenwood\Node\Chain;
use Greenwood\Persistence\{BlockFilesystem, StateSqlite};
use Greenwood\State\State;
use Greenwood\Tests\Fixtures\ComputeFixture as F;
use Greenwood\Transaction\Envelope;
use PHPUnit\Framework\TestCase;

final class ComputeL1Test extends TestCase
{
    private function tx(int $type, string $payload, int $nonce, ?Ed25519KeyPair $key = null): Envelope
    {
        $key ??= F::key();
        $tx = new Envelope(1, 'compute-l1', ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $key->getPublicKey()), $nonce, $type, $payload, '', $key->getPublicKey());
        $tx->setSignature($key->sign($tx->getSigningDigest()));
        $roundtrip = Envelope::decode($tx->getCanonicalBytes());
        self::assertSame($tx->getCanonicalBytes(), $roundtrip->getCanonicalBytes());
        return $roundtrip;
    }
    private function state(): State
    {
        return (new State())->setAccountBalance(F::requester(), 10000)
            ->setProviderEligibility(new GenesisProviderEligibility([F::key()->getPublicKey()], [F::key()->getPublicKey()]))->recomputeStateRoot();
    }
    private function request(array $overrides = []): \Greenwood\Compute\Protocol\ComputeRequest
    {
        return F::request(array_merge(['requestNonce' => 0, 'deadlineHeight' => 10], $overrides));
    }
    private function assignment(string $id, int $generation = 1, int $deadline = 10, ?Ed25519KeyPair $provider = null): ComputeAssign
    {
        $provider ??= F::key();
        return new ComputeAssign($id, ProviderId::fromPublicKey($provider->getPublicKey())->toBytes(), $provider->getPublicKey(), $generation, $deadline);
    }
    private function receipt(string $id, int $generation = 1, array $changes = []): ComputeReceiptV2
    {
        return (new ComputeReceiptV2(F::receipt(array_merge(['requestId' => $id], $changes)), $generation))->sign(F::key());
    }
    private function apply(State $state, Envelope $tx, int $height): void
    {
        $root = $state->getStateRoot();
        $state->applyTransaction($tx, 'compute-l1', $height);
        self::assertNotSame($root, $state->getStateRoot());
    }
    private function rejects(State $state, Envelope $tx, ?int $height): void
    {
        $root = $state->getStateRoot(); $records = $state->logicalRecords();
        try {
            $state->applyTransaction($tx, 'compute-l1', $height);
            self::fail('Invalid compute transaction accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame($root, $state->getStateRoot());
            self::assertSame($records, $state->logicalRecords(), 'Rejected transaction changed balances, nonces or compute state');
        }
    }

    public function testRequestAuthorizationLimitsAndExplicitHeight(): void
    {
        $state = $this->state(); $request = $this->request();
        $valid = $this->tx(7, $request->toBytes(), 0);
        foreach ([null, 0, 11] as $height) $this->rejects($state, $valid, $height);
        foreach (['cpuLimitMillis', 'memoryLimitBytes', 'pidLimit', 'executionTimeoutMs'] as $limit) {
            $this->rejects($state, $this->tx(7, $this->request([$limit => 0])->toBytes(), 0), 1);
        }
        $this->rejects($state, $this->tx(7, $this->request(['requester' => str_repeat('x', 32)])->toBytes(), 0), 1);
        $this->rejects($state, $this->tx(7, $this->request(['requestNonce' => 1])->toBytes(), 0), 1);
        $this->rejects($state, $this->tx(7, $request->toBytes(), 1), 1);
        $bad = $request->toBytes(); $bad[1] = "\x02";
        $this->rejects($state, $this->tx(7, $bad, 0), 1);
        // Trust byte follows request fields and both bounded references.
        $offset = 2 + 32 + 4 + 32 + 4 + strlen($request->getWorkloadRef()) + 32 + 4 + strlen($request->getInputRef());
        $bad = $request->toBytes(); $bad[$offset] = "\x03";
        $this->rejects($state, $this->tx(7, $bad, 0), 1);
        $this->apply($state, $valid, 10); // Inclusive request deadline.
        self::assertSame(RequestState::PENDING, $state->getComputeState()->lifecycle($request->getId()));
        $this->rejects($state, $this->tx(7, $request->toBytes(), 1), 10);
        $this->rejects($state, $valid, 10);
    }

    public function testAssignmentsRequireOwnerEligibilityBindingAndNextGeneration(): void
    {
        $state = $this->state(); $request = $this->request(); $id = $request->getId();
        $this->apply($state, $this->tx(7, $request->toBytes(), 0), 1);
        $other = Ed25519KeyPair::generate();
        $this->rejects($state, $this->tx(8, $this->assignment($id)->toBytes(), 0, $other), 2);
        $this->rejects($state, $this->tx(8, $this->assignment(str_repeat('x', 32))->toBytes(), 1), 2);
        foreach ([2, 0xffffffff] as $generation) $this->rejects($state, $this->tx(8, $this->assignment($id, $generation)->toBytes(), 1), 2);
        foreach ([1, 11] as $deadline) $this->rejects($state, $this->tx(8, $this->assignment($id, 1, $deadline)->toBytes(), 1), 2);
        $this->rejects($state, $this->tx(8, $this->assignment($id, 1, 10, $other)->toBytes(), 1), 2);
        $bytes = $this->assignment($id)->toBytes();
        $bad = substr_replace($bytes, str_repeat("\0", 4), 98, 4);
        $this->rejects($state, $this->tx(8, $bad, 1), 2);
        $bad = $bytes; $bad[34] = chr(ord($bad[34]) ^ 1);
        $this->rejects($state, $this->tx(8, $bad, 1), 2);
        $this->apply($state, $this->tx(8, $this->assignment($id, 1, 2)->toBytes(), 1), 2);
        $this->rejects($state, $this->tx(8, $bytes, 2), 2);
        $this->apply($state, $this->tx(8, $this->assignment($id, 2)->toBytes(), 2), 3);
        self::assertSame(2, $state->getComputeState()->assignment($id)->generation);
        self::assertSame(0xffffffff, ComputeAssign::nextGeneration(0xfffffffe));
        $this->expectException(\InvalidArgumentException::class);
        ComputeAssign::nextGeneration(0xffffffff);
    }

    public function testEligibilityPoolsAreIndependentFromValidatorMutations(): void
    {
        $other = Ed25519KeyPair::generate();
        $policy = new GenesisProviderEligibility([F::key()->getPublicKey()], [$other->getPublicKey()]);
        $id = F::providerId()->toBytes(); $otherId = ProviderId::fromPublicKey($other->getPublicKey())->toBytes();
        self::assertTrue($policy->isEligible($otherId, 0, 1));
        self::assertTrue($policy->isEligible($id, 1, 1));
        self::assertFalse($policy->isEligible($id, 2, 1));
        self::assertFalse($policy->isEligible($otherId, 1, 1));
        self::assertTrue($policy->isEligible($otherId, 2, 1));
        $state = $this->state()->setProviderEligibility($policy)->setValidators([]);
        self::assertSame($policy, $state->getProviderEligibility());
        foreach ([0, 2] as $trust) {
            $s = $this->state()->setProviderEligibility($policy);
            $request = $this->request(['trustClass' => $trust]);
            $this->apply($s, $this->tx(7, $request->toBytes(), 0), 1);
            $this->apply($s, $this->tx(8, $this->assignment($request->getId(), 1, 10, $other)->toBytes(), 1), 2);
        }
    }

    public function testReceiptSecurityReassignmentAndTerminality(): void
    {
        $state = $this->state(); $request = $this->request(); $id = $request->getId();
        $this->apply($state, $this->tx(7, $request->toBytes(), 0), 1);
        $this->rejects($state, $this->tx(9, $this->receipt($id)->toSignedBytes(), 1), 1);
        $this->apply($state, $this->tx(8, $this->assignment($id)->toBytes(), 1), 2);
        $this->apply($state, $this->tx(8, $this->assignment($id, 2)->toBytes(), 2), 3);
        $this->rejects($state, $this->tx(9, F::receipt(['requestId' => $id])->sign(F::key())->toSignedBytes(), 3), 4);
        foreach ([1, 3] as $generation) $this->rejects($state, $this->tx(9, $this->receipt($id, $generation)->toSignedBytes(), 3), 4);
        foreach (['providerId', 'requestId', 'workloadDigest', 'inputDigest'] as $field) {
            $this->rejects($state, $this->tx(9, $this->receipt($id, 2, [$field => str_repeat('x', 32)])->toSignedBytes(), 3), 4);
        }
        $bad = $this->receipt($id, 2)->setSignature(str_repeat('x', 64));
        $this->rejects($state, $this->tx(9, $bad->toSignedBytes(), 3), 4);
        $valid = $this->receipt($id, 2)->toSignedBytes();
        $this->rejects($state, $this->tx(9, $valid, 0, Ed25519KeyPair::generate()), 4);
        $this->rejects($state, $this->tx(9, $valid, 3), 11);
        $this->apply($state, $this->tx(9, $valid, 3), 10);
        self::assertSame(RequestState::TERMINAL, $state->getComputeState()->lifecycle($id));
        $this->rejects($state, $this->tx(9, $valid, 4), 10);
        $this->rejects($state, $this->tx(9, $this->receipt($id, 2, ['outputDigest' => str_repeat('x', 32)])->toSignedBytes(), 4), 10);
        $this->rejects($state, $this->tx(8, $this->assignment($id, 3)->toBytes(), 4), 10);
    }

    public function testAssignmentReceiptDeadlineBoundary(): void
    {
        $state = $this->state(); $request = $this->request(); $id = $request->getId();
        $this->apply($state, $this->tx(7, $request->toBytes(), 0), 1);
        $this->apply($state, $this->tx(8, $this->assignment($id, 1, 3)->toBytes(), 1), 2);
        $tx = $this->tx(9, $this->receipt($id)->toSignedBytes(), 2);
        $this->rejects($state, $tx, 4);
        $this->apply($state, $tx, 3);
    }

    public function testProviderAccountIsNotProviderIdAndDifferentProviderCanSettle(): void
    {
        $provider = Ed25519KeyPair::generate();
        $state = $this->state(); $request = $this->request(['trustClass' => 0]); $id = $request->getId();
        $this->apply($state, $this->tx(7, $request->toBytes(), 0), 1);
        $assignment = $this->assignment($id, 1, 10, $provider);
        $this->apply($state, $this->tx(8, $assignment->toBytes(), 1), 2);
        $receipt = (new ComputeReceiptV2(F::receipt(['requestId' => $id, 'providerId' => $assignment->providerId]), 1))->sign($provider);
        self::assertNotSame($assignment->providerId, ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $provider->getPublicKey()));
        $this->rejects($state, $this->tx(9, $receipt->toSignedBytes(), 2), 3);
        $this->apply($state, $this->tx(9, $receipt->toSignedBytes(), 0, $provider), 3);
    }

    public function testV2VersionSeparationAndEveryTruncatedPrefix(): void
    {
        $receipt = $this->receipt(F::request()->getId());
        $bytes = $receipt->toSignedBytes();
        self::assertSame($bytes, ComputeReceiptV2Codec::decode($bytes)->toSignedBytes());
        self::assertNotSame($receipt->getId(), F::receipt()->getId());
        $different = $this->receipt(F::request()->getId(), 2)->setSignature($receipt->getSignature());
        self::assertFalse($different->verifySignature(F::key()->getPublicKey()));
        $assignment = $this->assignment(F::request()->getId())->toBytes();
        foreach ([[$bytes, ComputeReceiptV2Codec::decode(...)], [$assignment, ComputeAssign::decode(...)]] as [$valid, $decode]) {
            self::assertSame($valid, $decode($valid) instanceof ComputeAssign ? $decode($valid)->toBytes() : $decode($valid)->toSignedBytes());
            for ($n = 0; $n < strlen($valid); $n++) {
                try { $decode(substr($valid, 0, $n)); self::fail('Accepted truncated payload'); }
                catch (\InvalidArgumentException) { self::assertTrue(true); }
            }
            try { $decode($valid . 'x'); self::fail('Accepted trailing byte'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        try { ComputeReceiptV2Codec::decode(F::receipt()->sign(F::key())->toSignedBytes()); self::fail('V1 accepted as V2'); }
        catch (\InvalidArgumentException) { self::assertTrue(true); }
        $this->expectException(\InvalidArgumentException::class);
        ComputeReceiptCodec::decode($bytes);
    }

    public function testFrozenV2Vectors(): void
    {
        $v = json_decode(file_get_contents(__DIR__ . '/../Fixtures/compute-v2.json'), true, 16, JSON_THROW_ON_ERROR);
        $receipt = (new ComputeReceiptV2(F::receipt(), $v['generation']))->sign(F::key());
        self::assertSame($v['unsigned_hex'], bin2hex($receipt->toUnsignedBytes()));
        self::assertSame($v['signed_hex'], bin2hex($receipt->toSignedBytes()));
        self::assertSame($v['receipt_id'], bin2hex($receipt->getId()));
        self::assertSame($v['signing_digest'], bin2hex($receipt->getSigningDigest()));
        self::assertSame($v['signature'], bin2hex($receipt->getSignature()));
        self::assertTrue(ComputeReceiptV2Codec::decode(hex2bin($v['signed_hex']))->verifySignature(F::key()->getPublicKey()));
        self::assertSame($v['assignment_hex'], bin2hex($this->assignment(F::request()->getId(), 1, 123456)->toBytes()));
    }

    public function testExpiredRequestRejectedByBuilderValidatorAndDirectApplication(): void
    {
        $g = new Genesis('compute-l1'); $keys = [];
        foreach (['A','B','C','D'] as $name) { $keys[] = Ed25519KeyPair::generate(); $g->addValidator($name, end($keys)); }
        $g->addInitialAccount('requester', F::key(), 10000);
        $dir = sys_get_temp_dir() . '/greenwood-compute-height-' . bin2hex(random_bytes(6));
        $chain = new Chain($g, new BlockFilesystem($dir . '/blocks'), new StateSqlite($dir . '/state.sqlite'));
        $tx = $this->tx(7, $this->request(['deadlineHeight' => 1])->toBytes(), 0);
        $proposer1 = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $keys[1]->getPublicKey());
        $boundary = $chain->build([$tx], 0, $proposer1);
        $direct = clone $chain->state; $direct->applyTransaction($tx, 'compute-l1', 1);
        self::assertSame($direct->getStateRoot(), $boundary->getStateRoot());
        self::assertSame($direct->getStateRoot(), $chain->execute($boundary)->getStateRoot());
        // Leave request unsubmitted and advance chain to candidate height two.
        $chain->finalize($chain->build([], 0, $proposer1));
        $proposer2 = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $keys[2]->getPublicKey());
        $this->rejects($chain->state, $tx, 2);
        try { $chain->build([$tx], 0, $proposer2); self::fail('Builder accepted expired request'); }
        catch (\InvalidArgumentException) { self::assertTrue(true); }
        $tree = new \Greenwood\Crypto\MerkleTree('GREENWOOD_TX_LEAF_V1', 'GREENWOOD_TX_NODE_V1', 'GREENWOOD_TX_EMPTY_V1');
        $tree->addLeaf($tx->getHash());
        $header = new \Greenwood\Block\Header(1, 'compute-l1', 2, 0, $chain->tip->getHash(),
            $chain->state->getStateRoot(), $tree->getRoot(), $direct->getStateRoot(), $proposer2, $g->getGenesisHash(), 0);
        try { $chain->execute(new \Greenwood\Block\Block($header, [$tx])); self::fail('Validator accepted expired request'); }
        catch (\InvalidArgumentException $error) { self::assertStringContainsString('deadline', $error->getMessage()); }
    }

    public function testRecordOrderingIsIndependentOfRequestInsertionOrder(): void
    {
        $other = Ed25519KeyPair::generate();
        $address = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $other->getPublicKey());
        $one = $this->tx(7, $this->request(['trustClass' => 0])->toBytes(), 0);
        $two = $this->tx(7, $this->request(['requester' => $address, 'trustClass' => 0])->toBytes(), 0, $other);
        $a = $this->state(); $b = $this->state();
        foreach ([$one, $two] as $tx) $a->applyTransaction($tx, 'compute-l1', 1);
        foreach ([$two, $one] as $tx) $b->applyTransaction($tx, 'compute-l1', 1);
        self::assertSame($a->logicalRecords(), $b->logicalRecords());
        self::assertSame($a->getStateRoot(), $b->getStateRoot());
    }

    public function testBlockHeightPersistenceReplayAndFailureAtomicity(): void
    {
        foreach ([false, true] as $reassign) {
            $dir = sys_get_temp_dir() . '/greenwood-compute-l1-' . bin2hex(random_bytes(6));
            $g = new Genesis('compute-l1'); $keys = [];
            foreach (['A','B','C','D'] as $name) { $keys[$name] = Ed25519KeyPair::generate(); $g->addValidator($name, $keys[$name]); }
            $g->addInitialAccount('requester', F::key(), 10000);
            $files = new BlockFilesystem($dir . '/blocks'); $db = new StateSqlite($dir . '/state.sqlite');
            $verify = static function ($block, $bytes) use ($g): void {
                \Greenwood\Consensus\Messages\Certificate::decode($bytes)->verify($g->getValidators(), $g->getChainId(), $g->getGenesisHash(), 'precommit', $block->getHeight(), $block->getHash());
            };
            $chain = new Chain($g, $files, $db, $verify);
            $request = $this->request(['trustClass' => 0, 'deadlineHeight' => $reassign ? 4 : 3]); $id = $request->getId();
            $generation = $reassign ? 2 : 1;
            $transactions = [$this->tx(7, $request->toBytes(), 0), $this->tx(8, $this->assignment($id, 1, $request->getDeadlineHeight())->toBytes(), 1)];
            if ($reassign) $transactions[] = $this->tx(8, $this->assignment($id, 2, 4)->toBytes(), 2);
            $transactions[] = $this->tx(9, $this->receipt($id, $generation)->toSignedBytes(), count($transactions));
            foreach ($transactions as $index => $tx) {
                $height = $index + 1;
                $proposer = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', array_values($keys)[$height % 4]->getPublicKey());
                $root = $chain->state->getStateRoot();
                $block = $chain->build([$tx], 0, $proposer);
                self::assertSame($root, $chain->state->getStateRoot());
                $normal = clone $chain->state; $normal->applyTransaction($tx, 'compute-l1', $height);
                self::assertSame($normal->getStateRoot(), $block->getHeader()->getStateRoot());
                self::assertSame($normal->logicalRecords(), $chain->execute($block)->logicalRecords());
                $votes = [];
                foreach (array_slice($keys, 0, 3, true) as $name => $key) {
                    $vote = new \Greenwood\Consensus\Messages\Message('precommit', $g->getChainId(), $g->getGenesisHash(), $height, 0, $name, $block->getHash());
                    $votes[] = $vote->signed($key->sign($vote->digest()));
                }
                $certificate = new \Greenwood\Consensus\Messages\Certificate($votes);
                $chain->finalize(\Greenwood\Block\Block::fromBytes($block->toBytes()), $certificate->bytes());
                self::assertSame($chain->state->getStateRoot(), $db->computeStateRoot());
                self::assertSame($chain->state->getComputeState()->logicalRecords(), $db->loadState()->getComputeState()->logicalRecords());
                if ($reassign && $height === 3) $this->rejects($chain->state, $this->tx(9, $this->receipt($id, 1)->toSignedBytes(), 3), 4);
            }
            $root = $chain->state->getStateRoot(); $hash = $chain->tip->getHash(); $records = $chain->state->logicalRecords();
            // Inject SQLite failure after DELETEs/inserts have begun; all index tables must roll back.
            $pdo = new \PDO('sqlite:' . $dir . '/state.sqlite');
            $pdo->exec("CREATE TRIGGER compute_insert_failure BEFORE INSERT ON compute_records BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
            try { $db->commitState($chain->state, $chain->tip, $g->getValidators()); self::fail('Expected persistence failure'); }
            catch (\PDOException) { self::assertSame($records, $db->loadState()->logicalRecords()); }
            $pdo = null; $db->close(); unset($chain, $db);
            unlink($dir . '/state.sqlite');
            $rebuilt = new Chain($g, $files, new StateSqlite($dir . '/state.sqlite'), $verify);
            self::assertSame($root, $rebuilt->state->getStateRoot());
            self::assertSame($hash, $rebuilt->tip->getHash());
            self::assertSame($records, $rebuilt->state->logicalRecords());
            self::assertSame($root, $rebuilt->index->computeStateRoot());
            self::assertSame(RequestState::TERMINAL, $rebuilt->state->getComputeState()->lifecycle($id));
        }
    }
}
