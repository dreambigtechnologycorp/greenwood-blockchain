<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Compute\Protocol\ComputeRequest;
use Greenwood\Compute\Containerd\ResourceLimits;

final readonly class ProviderConfig
{
    public function __construct(
        public string $chainId,
        public string $rpcEndpoint,
        public string $keyFile,
        public string $dataPath,
        public array $trustClasses = [0],
        public int $maxConcurrent = 5,
        public int $maxCpuMillis = 4000,
        public int $maxMemoryBytes = 4294967296,
        public int $maxPids = 512,
        public int $maxTimeoutMs = 3600000,
        public int $maxOutputBytes = 1048576,
        public string $containerdSocket = '/run/containerd/containerd.sock',
        public string $snapshotter = 'overlayfs',
        public int $pollIntervalMs = 1000,
        public string $workerEntrypoint = __DIR__ . '/../../bin/greenwood-provider-worker',
        public ?string $expectedProviderId = null,
        public ?string $expectedPublicKey = null,
        public int $workerUid = 65534,
        public int $workerGid = 65534,
        public int $controlTimeoutMs = 120000,
    ) {
        $url = parse_url($rpcEndpoint);
        if ($workerUid < 1 || $workerGid < 1 || $controlTimeoutMs < 1000 || $controlTimeoutMs > 3600000) throw new \InvalidArgumentException('Invalid worker isolation/control configuration');
        if ($chainId === '' || strlen($chainId) > 128 || $url === false
            || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) throw new \InvalidArgumentException('Invalid provider chain/RPC configuration');
        foreach ([$keyFile, $dataPath, $containerdSocket, $workerEntrypoint] as $path) {
            if (!str_starts_with($path, '/') || str_contains($path, "\0")) throw new \InvalidArgumentException('Provider paths must be absolute');
        }
        if (!in_array($snapshotter, ['overlayfs', 'erofs'], true)) throw new \InvalidArgumentException('Unsupported snapshotter');
        if ($trustClasses === [] || count(array_unique($trustClasses, SORT_REGULAR)) !== count($trustClasses)) throw new \InvalidArgumentException('Invalid served trust classes');
        foreach ($trustClasses as $class) if (!is_int($class) || !in_array($class, [0, 1, 2], true)) throw new \InvalidArgumentException('Invalid served trust class');
        foreach ([$maxConcurrent, $maxCpuMillis, $maxMemoryBytes, $maxPids, $maxTimeoutMs, $pollIntervalMs] as $value) {
            if ($value < 1) throw new \InvalidArgumentException('Provider limits must be positive');
        }
        if ($maxConcurrent > 128 || $maxCpuMillis > 0xffffffff || $maxPids > 0xffffffff || $maxOutputBytes < 0 || $maxOutputBytes > 1048576) throw new \InvalidArgumentException('Provider limit exceeds supported range');
        foreach ([$expectedProviderId, $expectedPublicKey] as $id) if ($id !== null && strlen($id) !== 32) throw new \InvalidArgumentException('Invalid configured identity length');
    }

    /** Local JSON configuration only; never consensus encoding. */
    public static function fromFile(string $path): self
    {
        $raw = file_get_contents($path, false, null, 0, 65537);
        if ($raw === false || strlen($raw) > 65536) throw new \InvalidArgumentException('Invalid provider configuration file');
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) throw new \InvalidArgumentException('Provider configuration must be an object');
        foreach (['expectedProviderId', 'expectedPublicKey'] as $name) {
            if (isset($data[$name])) {
                if (!is_string($data[$name]) || !preg_match('/^[0-9a-f]{64}$/D', $data[$name])) throw new \InvalidArgumentException('Invalid configured identity hex');
                $data[$name] = hex2bin($data[$name]);
            }
        }
        try { return new self(...$data); }
        catch (\Error $error) { throw new \InvalidArgumentException('Invalid provider configuration fields or types', 0, $error); }
    }

    public function admits(ComputeRequest $request): bool
    {
        return in_array($request->getTrustClass(), $this->trustClasses, true)
            && $request->getCpuLimitMillis() > 0 && $request->getCpuLimitMillis() <= $this->maxCpuMillis
            && $request->getMemoryLimitBytes() > 0 && $request->getMemoryLimitBytes() <= $this->maxMemoryBytes
            && $request->getPidLimit() > 0 && $request->getPidLimit() <= $this->maxPids
            && $request->getExecutionTimeoutMs() > 0 && $request->getExecutionTimeoutMs() <= $this->maxTimeoutMs
            && $request->getOutputRetentionBytes() <= $this->maxOutputBytes;
    }

    public function resourceLimits(ComputeRequest $request): ResourceLimits
    {
        if (!$this->admits($request)) throw new \InvalidArgumentException('Assignment exceeds local provider policy');
        return ResourceLimits::create($request->getMemoryLimitBytes(), $request->getPidLimit(), $request->getCpuLimitMillis() * 100, 100000);
    }
}
