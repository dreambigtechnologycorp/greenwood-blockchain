<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Transaction\Envelope;

interface ChainClient
{
    public function assignments(string $providerId, ?string $after = null): array;
    public function request(string $requestId): array;
    public function nonce(string $accountId): int;
    /** Current finalized provider capability DTO, or null when none is registered. */
    public function capability(string $providerId): ?array;
    public function submit(Envelope $transaction): string;
}
