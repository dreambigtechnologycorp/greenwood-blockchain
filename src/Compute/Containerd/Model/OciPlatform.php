<?php

namespace Greenwood\Compute\Containerd\Model;

final class OciPlatform
{
    private const ARCH_MAP = [
        'x86_64' => 'amd64',
        'aarch64' => 'arm64',
        'arm64' => 'arm64',
        'armv7l' => 'arm',
        'armv6l' => 'arm',
        'ppc64le' => 'ppc64le',
        's390x' => 's390x',
        'riscv64' => 'riscv64',
    ];

    private const VARIANT_MAP = [
        'arm64' => 'v8',
        'arm' => 'v7',
    ];

    public function __construct(
        public readonly string $os,
        public readonly string $architecture,
        public readonly ?string $variant = null,
    ) {}

    public static function fromHost(): self
    {
        $unameM = php_uname('m');
        $arch = self::ARCH_MAP[$unameM] ?? $unameM;
        $variant = null;
        if (isset(self::VARIANT_MAP[$arch])) {
            $variant = self::VARIANT_MAP[$arch];
        }
        return new self('linux', $arch, $variant);
    }

    public function matches(?array $descriptorPlatform): bool
    {
        if ($descriptorPlatform === null) {
            return false;
        }
        if (!isset($descriptorPlatform['os']) || $descriptorPlatform['os'] !== $this->os) {
            return false;
        }
        if (!isset($descriptorPlatform['architecture']) || $descriptorPlatform['architecture'] !== $this->architecture) {
            return false;
        }
        if ($this->variant !== null && isset($descriptorPlatform['variant']) && $descriptorPlatform['variant'] !== $this->variant) {
            return false;
        }
        if ($descriptorPlatform['os'] === 'unknown' && $descriptorPlatform['architecture'] === 'unknown') {
            return false;
        }
        return true;
    }

    public function __toString(): string
    {
        $result = $this->os . '/' . $this->architecture;
        if ($this->variant !== null) {
            $result .= '/' . $this->variant;
        }
        return $result;
    }
}
