<?php
declare(strict_types=1);
namespace Greenwood\Compute\Verification;

use Greenwood\Compute\Protocol\{ProviderId, TrustClass};

/** Immutable explicit provider pools, initially populated from trusted genesis. */
final readonly class GenesisProviderEligibility implements ProviderEligibility
{
    public function __construct(public array $trustedKeys = [], public array $sovereignKeys = [])
    {
        foreach (array_merge($trustedKeys, $sovereignKeys) as $key) {
            if (!is_string($key) || strlen($key) !== 32) throw new \InvalidArgumentException('Invalid eligibility key');
        }
    }
    public function isEligible(string $providerId, int $trustClass, int $height): bool
    {
        if (strlen($providerId) !== 32 || $height < 1) return false;
        if ($trustClass === TrustClass::OPEN) return true; // Assignment already verifies key binding.
        $keys = match ($trustClass) {
            TrustClass::TRUSTED => $this->trustedKeys,
            TrustClass::SOVEREIGN => $this->sovereignKeys,
            default => [],
        };
        foreach ($keys as $key) if (ProviderId::fromPublicKey($key)->toBytes() === $providerId) return true;
        return false;
    }
}
