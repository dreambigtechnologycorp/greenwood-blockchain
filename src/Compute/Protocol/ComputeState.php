<?php
declare(strict_types=1);
namespace Greenwood\Compute\Protocol;

use Greenwood\Codec\{Canonical as C, Reader};
use Greenwood\Compute\Codec\{ComputeRequestCodec, ComputeReceiptV2Codec};
use Greenwood\Compute\Verification\{ComputeReceiptVerifier, ProviderEligibility};
use Greenwood\Crypto\ProtocolHash;
use Greenwood\Transaction\Envelope;

/** Immutable consensus records owned by State. No runtime or containerd dependencies. */
final class ComputeState
{
    /** @var array<string,array<string,string>> canonical namespace/key/value records */
    private array $records = ['compute_requests' => [], 'compute_assignments' => [], 'compute_receipts' => [],
        'provider_capabilities' => []];

    private static function record(string $bytes, int $height): string { return C::encodeBytes($bytes) . C::encodeU64BE($height); }
    private static function unpack(string $value): array
    {
        $r = new Reader($value, 2048);
        $bytes = $r->bytes(1100); $height = $r->u64(); $r->end();
        if ($height < 1) throw new \InvalidArgumentException('Invalid compute inclusion height');
        return [$bytes, $height];
    }
    public function request(string $id): ComputeRequest
    {
        if (!isset($this->records['compute_requests'][$id])) throw new \InvalidArgumentException('Unknown compute request');
        return ComputeRequestCodec::decode(self::unpack($this->records['compute_requests'][$id])[0]);
    }
    public function assignment(string $id): ?ComputeAssign
    {
        $value = $this->records['compute_assignments'][$id] ?? null;
        return $value === null ? null : ComputeAssign::decode(self::unpack($value)[0]);
    }
    public function terminal(string $id): bool { return isset($this->records['compute_receipts'][$id]); }
    /** @return array<string, ProviderCapability> finalized capabilities keyed by provider_id */
    public function capabilities(): array
    {
        $out = [];
        foreach ($this->records['provider_capabilities'] as $id => $value) {
            $out[$id] = ProviderCapability::decode(self::unpack($value)[0]);
        }
        return $out;
    }
    /**
     * Reservation authority (Pass 4D section 10): finalized non-terminal
     * assignments. Returns [cpu, memory, pids, jobs] reserved on $providerId.
     */
    public function reserved(string $providerId): array
    {
        $total = ['cpu' => 0, 'memory' => 0, 'pids' => 0, 'jobs' => 0];
        foreach ($this->records['compute_assignments'] as $id => $value) {
            if ($this->terminal($id)) continue;
            $assignment = ComputeAssign::decode(self::unpack($value)[0]);
            if ($assignment->providerId !== $providerId) continue;
            $request = $this->request($id);
            $total['cpu'] += $request->getCpuLimitMillis();
            $total['memory'] += $request->getMemoryLimitBytes();
            $total['pids'] += $request->getPidLimit();
            $total['jobs']++;
        }
        return $total;
    }
    /** Capability update guard: proposal must cover currently reserved capacity. */
    private function assertCapacityCoversReservations(ProviderCapability $capability): void
    {
        $reserved = $this->reserved($capability->providerId);
        if ($reserved['cpu'] > $capability->cpuCapacityMillis || $reserved['memory'] > $capability->memoryCapacityBytes
            || $reserved['pids'] > $capability->pidCapacity || $reserved['jobs'] > $capability->maxConcurrentJobs) {
            throw new \InvalidArgumentException('Capability below existing reservations');
        }
    }
    public function lifecycle(string $id): string
    {
        $this->request($id);
        return $this->terminal($id) ? RequestState::TERMINAL : ($this->assignment($id) === null ? RequestState::PENDING : RequestState::ASSIGNED);
    }
    private static function validateRequest(ComputeRequest $request, int $height): void
    {
        if ($height < 1 || $height > $request->getDeadlineHeight()
            || $request->getCpuLimitMillis() < 1 || $request->getMemoryLimitBytes() < 1
            || $request->getPidLimit() < 1 || $request->getExecutionTimeoutMs() < 1) {
            throw new \InvalidArgumentException('Invalid request resource budget or deadline');
        }
    }

    /** Caller State authenticates the envelope and nonce before dispatching. */
    public function apply(Envelope $tx, int $height, ProviderEligibility $eligibility): self
    {
        if ($height < 1) throw new \InvalidArgumentException('Candidate inclusion height required');
        $next = clone $this;
        switch ($tx->getType()) {
            case Envelope::TYPE_COMPUTE_REQUEST:
                $request = ComputeRequestCodec::decode($tx->getPayload());
                self::validateRequest($request, $height);
                $id = $request->getId();
                if ($tx->getSender() !== $request->getRequester() || $tx->getNonce() !== $request->getRequestNonce()
                    || isset($this->records['compute_requests'][$id])) throw new \InvalidArgumentException('Invalid requester, request nonce or duplicate request');
                $next->records['compute_requests'][$id] = self::record($request->toBytes(), $height);
                break;
            case Envelope::TYPE_COMPUTE_ASSIGN:
                $assignment = ComputeAssign::decode($tx->getPayload());
                $id = $assignment->requestId;
                $request = $this->request($id);
                $previous = $this->assignment($id)?->generation ?? 0;
                if ($this->terminal($id) || $tx->getSender() !== $request->getRequester()
                    || $assignment->generation !== ComputeAssign::nextGeneration($previous)
                    || $height > $assignment->deadline || $assignment->deadline > $request->getDeadlineHeight()
                    || !$eligibility->isEligible($assignment->providerId, $request->getTrustClass(), $height)) {
                    throw new \InvalidArgumentException('Unauthorized assignment, generation, eligibility or deadline');
                }
                $next->records['compute_assignments'][$id] = self::record($assignment->toBytes(), $height);
                break;
            case Envelope::TYPE_COMPUTE_RECEIPT:
                $receipt = ComputeReceiptV2Codec::decode($tx->getPayload());
                $id = $receipt->body()->getRequestId();
                ComputeReceiptVerifier::verifyV2($this->request($id), $this->assignment($id), $receipt, $tx->getSender(), $height, $this->terminal($id));
                $next->records['compute_receipts'][$id] = self::record($receipt->toSignedBytes(), $height);
                break;
            case Envelope::TYPE_PROVIDER_CAPABILITY:
                $capability = ProviderCapability::decode($tx->getPayload());
                $id = $capability->providerId;
                $previous = $this->capabilities()[$id] ?? null;
                // Signing identity is account-bound to the provider key; the
                // provider_key ↔ provider_id binding is enforced by decode.
                if ($tx->getSender() !== ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $capability->providerPublicKey)
                    || $capability->nonce !== ($previous === null ? 1 : ProviderCapability::nextNonce($previous->nonce))) {
                    throw new \InvalidArgumentException('Unauthorized capability or bad nonce');
                }
                $this->assertCapacityCoversReservations($capability);
                $next->records['provider_capabilities'][$id] = self::record($capability->toBytes(), $height);
                break;
            default: throw new \InvalidArgumentException('Not a compute transaction');
        }
        return $next;
    }

    public function logicalRecords(): array
    {
        $result = [];
        foreach ($this->records as $namespace => $entries) foreach ($entries as $key => $value) $result[] = [$namespace, $key, $value];
        usort($result, fn ($a, $b) => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));
        return $result;
    }

    /** Derived-index inspection only. Chain startup always replays finalized blocks instead. */
    public static function fromDerivedRecords(array $records, ProviderEligibility $eligibility): self
    {
        $state = new self();
        foreach ($records as [$namespace, $id, $value]) {
            if (!array_key_exists($namespace, $state->records) || strlen($id) !== 32 || isset($state->records[$namespace][$id])) throw new \InvalidArgumentException('Invalid derived compute record');
            self::unpack($value);
            $state->records[$namespace][$id] = $value;
        }
        foreach ($state->records['compute_requests'] as $id => $value) {
            [$bytes, $height] = self::unpack($value);
            $request = ComputeRequestCodec::decode($bytes);
            self::validateRequest($request, $height);
            if ($request->getId() !== $id) throw new \InvalidArgumentException('Derived request ID mismatch');
        }
        foreach ($state->records['compute_assignments'] as $id => $value) {
            [$bytes, $height] = self::unpack($value);
            $assignment = ComputeAssign::decode($bytes);
            $request = $state->request($id);
            $requestHeight = self::unpack($state->records['compute_requests'][$id])[1];
            if ($assignment->requestId !== $id || $height < $requestHeight || $height > $assignment->deadline
                || $assignment->deadline > $request->getDeadlineHeight()
                || !$eligibility->isEligible($assignment->providerId, $request->getTrustClass(), $height)) throw new \InvalidArgumentException('Invalid derived assignment');
        }
        // Derived capability hydration re-validates binding and the
        // reservation guard against the already-validated assignments.
        foreach ($state->capabilities() as $id => $capability) {
            if ($capability->providerId !== $id) throw new \InvalidArgumentException('Derived capability key mismatch');
            $state->assertCapacityCoversReservations($capability);
        }
        // Even derived receipt hydration goes through the settlement verifier.
        foreach ($state->records['compute_receipts'] as $id => $value) {
            [$bytes, $height] = self::unpack($value);
            $assignment = $state->assignment($id);
            if ($assignment === null || $height < self::unpack($state->records['compute_assignments'][$id])[1]) throw new \InvalidArgumentException('Invalid derived receipt height');
            $receipt = ComputeReceiptV2Codec::decode($bytes);
            if ($receipt->body()->getRequestId() !== $id) throw new \InvalidArgumentException('Derived receipt key mismatch');
            ComputeReceiptVerifier::verifyV2($state->request($id), $assignment, $receipt,
                ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $assignment->providerPublicKey), $height, false);
        }
        return $state;
    }
}
