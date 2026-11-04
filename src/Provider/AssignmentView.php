<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Codec\Reader;
use Greenwood\Compute\Codec\{ComputeRequestCodec, ComputeReceiptV2Codec};
use Greenwood\Compute\Protocol\{ComputeRequest, ComputeAssign, ComputeReceiptV2};

final readonly class AssignmentView
{
    public function __construct(public int $height, public ?ComputeRequest $request, public ?ComputeAssign $assignment, public ?ComputeReceiptV2 $receipt) {}
    public static function decode(array $view): self
    {
        $height = $view['finalized_height'] ?? null;
        if (!is_int($height) || $height < 0 || !is_array($view['records'] ?? null)) throw new \RuntimeException('Invalid finalized request view');
        $values = [];
        foreach ($view['records'] as $name => $encoded) {
            if (!in_array($name, ['compute_requests', 'compute_assignments', 'compute_receipts'], true) || !is_string($encoded)) throw new \RuntimeException('Unknown finalized compute record');
            $raw = base64_decode($encoded, true);
            if ($raw === false || base64_encode($raw) !== $encoded) throw new \RuntimeException('Invalid compute record transport');
            $r = new Reader($raw, 2048); $bytes = $r->bytes(1100); $accepted = $r->u64(); $r->end();
            if ($accepted < 1 || $accepted > $height) throw new \RuntimeException('Unfinalized compute record');
            $values[$name] = match ($name) {
                'compute_requests' => ComputeRequestCodec::decode($bytes),
                'compute_assignments' => ComputeAssign::decode($bytes),
                'compute_receipts' => ComputeReceiptV2Codec::decode($bytes),
            };
        }
        $request = $values['compute_requests'] ?? null;
        $assignment = $values['compute_assignments'] ?? null;
        $receipt = $values['compute_receipts'] ?? null;
        if ($request === null && ($assignment !== null || $receipt !== null)) throw new \RuntimeException('Missing request record');
        if ($request !== null && (bin2hex($request->getId()) !== ($view['request_id'] ?? '')
            || ($assignment !== null && $assignment->requestId !== $request->getId())
            || ($receipt !== null && ($assignment === null || $receipt->body()->getRequestId() !== $request->getId()
                || $receipt->generation !== $assignment->generation || !$receipt->verifySignature($assignment->providerPublicKey))))) throw new \RuntimeException('Inconsistent finalized compute records');
        return new self($height, $request, $assignment, $receipt);
    }
    public function current(string $providerId, int $generation): bool
    {
        return $this->request !== null && $this->assignment !== null && $this->receipt === null
            && $this->assignment->providerId === $providerId && $this->assignment->generation === $generation
            && $this->height <= $this->request->getDeadlineHeight() && $this->height <= $this->assignment->deadline;
    }
}
