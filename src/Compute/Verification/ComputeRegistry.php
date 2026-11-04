<?php
declare(strict_types=1);
namespace Greenwood\Compute\Verification;

use Greenwood\Compute\Protocol\ComputeReceipt;
use Greenwood\Compute\Protocol\ComputeRequest;
use Greenwood\Compute\Protocol\RequestState;

/**
 * Protocol-visible compute state (Pass 4B section 22).
 *
 * Tracks requests, their single provider assignment, and the accepted
 * terminal receipt. Holds only protocol metadata — never output blobs.
 * This is the reference in-memory implementation; persistent backends must
 * preserve identical lifecycle semantics.
 */
final class ComputeRegistry
{
    /** @var array<string, ComputeRequest> request_id (binary) => request */
    private array $requests = [];

    /** @var array<string, string> request_id => provider_id (binary) */
    private array $assignments = [];

    /** @var array<string, string> request_id => RequestState::* */
    private array $states = [];

    /** @var array<string, string> request_id => terminal receipt_id (binary) */
    private array $terminalReceipts = [];

    public function registerRequest(ComputeRequest $request): void
    {
        $id = $request->getId();
        if (isset($this->requests[$id])) {
            throw new \InvalidArgumentException('Compute request already registered');
        }
        $this->requests[$id] = $request;
        $this->states[$id] = RequestState::PENDING;
    }

    /** Assignment hook (Pass 4B section 21): exactly one provider per request. */
    public function assign(string $requestId, string $providerId): void
    {
        $this->requireRequest($requestId);
        if (strlen($providerId) !== 32) {
            throw new \InvalidArgumentException('Provider ID must be 32 bytes');
        }
        if ($this->states[$requestId] === RequestState::TERMINAL) {
            throw new \InvalidArgumentException('Request already terminal');
        }
        if (isset($this->assignments[$requestId]) && $this->assignments[$requestId] !== $providerId) {
            throw new \InvalidArgumentException('Request already assigned to a different provider');
        }
        $this->assignments[$requestId] = $providerId;
        $this->states[$requestId] = RequestState::ASSIGNED;
    }

    /**
     * Verifies and records a terminal receipt. No public mutation path may
     * settle a request without authenticating the assigned provider.
     * First protocol-valid authorized terminal receipt wins; every second
     * terminal receipt for the same request is a conflict and is rejected.
     */
    public function acceptTerminalReceipt(ComputeReceipt $receipt, string $providerPublicKey): void
    {
        (new ComputeReceiptVerifier($this))->verify($receipt, $providerPublicKey);
        $requestId = $receipt->getRequestId();
        $this->requireRequest($requestId);
        if ($this->states[$requestId] === RequestState::TERMINAL) {
            throw new \InvalidArgumentException('Conflicting terminal receipt: request already settled');
        }
        $this->states[$requestId] = RequestState::TERMINAL;
        $this->terminalReceipts[$requestId] = $receipt->getId();
    }

    public function hasRequest(string $requestId): bool
    {
        return isset($this->requests[$requestId]);
    }

    public function getRequest(string $requestId): ComputeRequest
    {
        $this->requireRequest($requestId);
        return $this->requests[$requestId];
    }

    public function getState(string $requestId): string
    {
        $this->requireRequest($requestId);
        return $this->states[$requestId];
    }

    public function getAssignment(string $requestId): ?string
    {
        $this->requireRequest($requestId);
        return $this->assignments[$requestId] ?? null;
    }

    public function getTerminalReceiptId(string $requestId): ?string
    {
        $this->requireRequest($requestId);
        return $this->terminalReceipts[$requestId] ?? null;
    }

    private function requireRequest(string $requestId): void
    {
        if (!isset($this->requests[$requestId])) {
            throw new \InvalidArgumentException('Unknown compute request');
        }
    }
}
