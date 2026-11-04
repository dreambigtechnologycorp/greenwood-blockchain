<?php
declare(strict_types=1);
namespace Greenwood\Compute\Verification;

use Greenwood\Compute\Protocol\ComputeReceipt;
use Greenwood\Compute\Protocol\ComputeStatus;
use Greenwood\Compute\Protocol\RequestState;

/**
 * Validator-side compute receipt verification (Pass 4B section 18).
 *
 * Validators NEVER rerun OCI workloads. Verification is purely:
 *   - request exists and request_id recomputes from the canonical request
 *   - provider is assigned to the request (assignment rule, section 21)
 *   - provider signature is valid for the canonical unsigned bytes
 *   - receipt workload/input digests match the request
 *   - status is frozen-valid and the exit-code relationship holds
 *   - resource accounting fields are structurally valid (enforced by the
 *     ComputeReceipt constructor and codec)
 *   - lifecycle: first protocol-valid authorized terminal receipt wins;
 *     any second terminal receipt is rejected as a duplicate/conflict
 *
 * Eligibility against the provider set for the request's trust class is a
 * chain-integration concern; this verifier proves the provider is the
 * assigned party and holds a valid signature.
 */
final class ComputeReceiptVerifier
{
    /** The sole V2 settlement authorization check, shared by application and derived-index inspection. */
    public static function verifyV2(
        \Greenwood\Compute\Protocol\ComputeRequest $request,
        ?\Greenwood\Compute\Protocol\ComputeAssign $assignment,
        \Greenwood\Compute\Protocol\ComputeReceiptV2 $receipt,
        string $sender,
        int $height,
        bool $terminal,
    ): void {
        $body = $receipt->body();
        if ($terminal) throw new \InvalidArgumentException('Duplicate terminal receipt');
        if ($assignment === null) throw new \InvalidArgumentException('No assignment');
        if ($receipt->generation < $assignment->generation) throw new \InvalidArgumentException('Stale receipt');
        if ($receipt->generation > $assignment->generation) throw new \InvalidArgumentException('Future receipt');
        if ($height < 1 || $height > $request->getDeadlineHeight() || $height > $assignment->deadline
            || $body->getRequestId() !== $request->getId() || $assignment->requestId !== $request->getId()
            || $body->getProviderId() !== $assignment->providerId
            || $body->getWorkloadDigest() !== $request->getWorkloadDigest()
            || $body->getInputDigest() !== $request->getInputDigest()
            || $sender !== \Greenwood\Crypto\ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $assignment->providerPublicKey)
            || !$receipt->verifySignature($assignment->providerPublicKey)) {
            throw new \InvalidArgumentException('Invalid V2 receipt authorization, linkage or deadline');
        }
        // Shared field/status/resource constraints are enforced by the frozen body codec.
    }
    public function __construct(
        private readonly ComputeRegistry $registry,
    ) {}

    /**
     * Full verification. Returns nothing on success; throws
     * \InvalidArgumentException on any failure, matching Greenwood
     * validation error conventions.
     */
    public function verify(ComputeReceipt $receipt, string $providerPublicKey): void
    {
        $requestId = $receipt->getRequestId();

        // Request must exist and receipt must bind to it exactly.
        if (!$this->registry->hasRequest($requestId)) {
            throw new \InvalidArgumentException('Receipt references unknown request');
        }
        $request = $this->registry->getRequest($requestId);

        // Request ID must be cryptographically derived from the request.
        if (!hash_equals($requestId, $request->getId())) {
            throw new \InvalidArgumentException('Request ID does not match canonical request');
        }

        // Assignment linkage: only the assigned provider may settle.
        $assigned = $this->registry->getAssignment($requestId);
        if ($assigned === null) {
            throw new \InvalidArgumentException('Request has no provider assignment');
        }
        if (!hash_equals($assigned, $receipt->getProviderId())) {
            throw new \InvalidArgumentException('Receipt provider is not the assigned provider');
        }

        // Duplicate / conflicting terminal receipts are rejected.
        if ($this->registry->getState($requestId) === RequestState::TERMINAL) {
            throw new \InvalidArgumentException('Duplicate terminal receipt');
        }

        // Request binding fields must match exactly.
        if (!hash_equals($request->getWorkloadDigest(), $receipt->getWorkloadDigest())) {
            throw new \InvalidArgumentException('Workload digest mismatch');
        }
        if (!hash_equals($request->getInputDigest(), $receipt->getInputDigest())) {
            throw new \InvalidArgumentException('Input digest mismatch');
        }

        // Frozen status and exit-code relationship (section 15).
        ComputeStatus::check($receipt->getStatus());
        if (ComputeStatus::requiresExitCode($receipt->getStatus()) && $receipt->getExitCode() === null) {
            throw new \InvalidArgumentException('Receipt status requires an exit code');
        }
        if (ComputeStatus::forbidsExitCode($receipt->getStatus()) && $receipt->getExitCode() !== null) {
            throw new \InvalidArgumentException('RUNTIME_ERROR receipts must not carry an exit code');
        }

        // Provider identity + Ed25519 signature over canonical unsigned bytes.
        if (!$receipt->verifySignature($providerPublicKey)) {
            throw new \InvalidArgumentException('Invalid provider signature');
        }
    }

    /** verify() then record settlement. First valid terminal receipt wins. */
    public function accept(ComputeReceipt $receipt, string $providerPublicKey): void
    {
        $this->registry->acceptTerminalReceipt($receipt, $providerPublicKey);
    }
}
