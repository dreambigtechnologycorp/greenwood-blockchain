<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Compute\Protocol\ComputeRequest;

/** Initial OCI profile: digest-pinned image and exact ash program bytes as input. */
final readonly class ProviderWorkload
{
    public function __construct(public string $image, public string $input) {}
    public static function resolve(ComputeRequest $request): self
    {
        $image = $request->getWorkloadRef();
        if (!preg_match('~^[a-z0-9][a-z0-9./:_-]*@(sha256:[0-9a-f]{64})$~D', $image, $m)
            || ProviderContent::workloadDigest($m[1]) !== $request->getWorkloadDigest()) throw new \InvalidArgumentException('Image reference does not match immutable workload digest');
        $ref = $request->getInputRef();
        if ($ref === '') $input = '';
        else {
            $prefix = 'data:application/octet-stream;base64,';
            if (!str_starts_with($ref, $prefix)) throw new \InvalidArgumentException('Input retrieval profile unsupported locally');
            $encoded = substr($ref, strlen($prefix)); $input = base64_decode($encoded, true);
            if ($input === false || base64_encode($input) !== $encoded) throw new \InvalidArgumentException('Invalid input encoding');
        }
        if (ProviderContent::inputDigest($input) !== $request->getInputDigest()) throw new \InvalidArgumentException('Input content digest mismatch');
        if (str_contains($input, "\0")) throw new \InvalidArgumentException('OCI ash command profile cannot accept NUL input');
        return new self($image, $input);
    }
}
