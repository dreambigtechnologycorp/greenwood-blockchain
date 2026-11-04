<?php
declare(strict_types=1);
namespace Greenwood\Compute\Verification;

interface ProviderEligibility
{
    public function isEligible(string $providerId, int $trustClass, int $height): bool;
}
