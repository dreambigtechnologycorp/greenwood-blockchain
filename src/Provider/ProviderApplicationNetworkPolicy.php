<?php
declare(strict_types=1);
namespace Greenwood\Provider;

/** Local administrator policy only. Never populated from an application request. */
final readonly class ProviderApplicationNetworkPolicy
{
    public function __construct(
        public string $providerAddress,
        public string $edgeAddress,
        public string $subnet,
        public int $firstPort,
        public int $lastPort,
        public string $forwardTable,
        public string $forwardChain,
        public array $protectedServices,
    ) {
        // This driver constructor validates addresses/anchors without host effects.
        new ProviderApplicationLinuxNetwork($providerAddress, $edgeAddress, $forwardTable, $forwardChain);
        if ($providerAddress === $edgeAddress) throw new \InvalidArgumentException('Provider and edge must be distinct');
        [$ip, $prefix] = array_pad(explode('/', $subnet), 2, '');
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || !preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $ip)
            || !ctype_digit($prefix) || (int)$prefix < 16 || (int)$prefix > 28
            || $firstPort < 1024 || $lastPort > 65535 || $firstPort > $lastPort) {
            throw new \InvalidArgumentException('Invalid application network pool');
        }
        $base = (int)sprintf('%u', ip2long($ip));
        $size = 1 << (32 - (int)$prefix);
        if ($base % $size !== 0) throw new \InvalidArgumentException('Application subnet must be aligned');
        foreach ([$providerAddress, $edgeAddress] as $address) {
            $value = (int)sprintf('%u', ip2long($address));
            if ($value >= $base && $value < $base + $size) throw new \InvalidArgumentException('Application pool overlaps control address');
        }
        if (!array_is_list($protectedServices)) throw new \InvalidArgumentException('Protected services must be a list');
        $seen = [];
        foreach ($protectedServices as $service) {
            if (!is_array($service) || count($service) !== 2
                || !is_int($service['application_id'] ?? null) || $service['application_id'] < 1 || $service['application_id'] > 0xffffffff
                || !is_string($service['service'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/D', $service['service'])) {
                throw new \InvalidArgumentException('Invalid protected service identity');
            }
            $key = $service['application_id'].':'.$service['service'];
            if (isset($seen[$key])) throw new \InvalidArgumentException('Duplicate protected service');
            $seen[$key] = true;
        }
    }

    public static function fromFile(string $path): self
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || realpath($path) !== $path) {
            throw new \InvalidArgumentException('Policy requires a canonical absolute path');
        }
        for ($part = $path; ; $part = dirname($part)) {
            $stat = lstat($part);
            if ($stat === false || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0
                || ($stat['mode'] & 0170000) !== ($part === $path ? 0100000 : 0040000)) {
                throw new \RuntimeException('Policy path must be root-owned and protected from other writers');
            }
            if ($part === '/') break;
        }
        $bytes = file_get_contents($path, false, null, 0, 65537);
        if ($bytes === false || strlen($bytes) > 65536) throw new \RuntimeException('Invalid policy size');
        $data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) throw new \InvalidArgumentException('Policy must be an object');
        try { return new self(...$data); }
        catch (\Error $error) { throw new \InvalidArgumentException('Invalid network policy fields', 0, $error); }
    }

    public function assertMutable(int $applicationId, string $service): void
    {
        foreach ($this->protectedServices as $protected) {
            if ($protected['application_id'] === $applicationId && $protected['service'] === $service) {
                throw new \RuntimeException('Application is protected by platform policy; explicit migration required');
            }
        }
    }

    /** Startup inventory is read-only; legacy ownership must never be inferred. */
    public function assertCoverage(array $activeApplications, ProviderApplicationNetwork $network): void
    {
        foreach ($activeApplications as $record) {
            $id = (int)$record['application_id'];
            $service = $record['service'];
            $protected = false;
            foreach ($this->protectedServices as $identity) {
                if ($identity['application_id'] === $id && $identity['service'] === $service) $protected = true;
            }
            if (!$protected && $network->find($id, $service) === null) {
                throw new \RuntimeException('Active application has no managed binding or explicit legacy protection');
            }
        }
    }

    public function createNetwork(string $directory): ProviderApplicationNetwork
    {
        $bindings = new ProviderApplicationBindingRegistry($directory, $this->subnet, $this->firstPort, $this->lastPort);
        $db = new \PDO('sqlite:' . $directory . '/bindings.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout=5000; PRAGMA synchronous=FULL');
        $db->exec('CREATE TABLE IF NOT EXISTS platform_network_policy (id INTEGER PRIMARY KEY CHECK(id=1), fingerprint TEXT NOT NULL)');
        $fingerprint = hash('sha256', json_encode(get_object_vars($this), JSON_THROW_ON_ERROR));
        $insert = $db->prepare('INSERT OR IGNORE INTO platform_network_policy VALUES (1,?)');
        $insert->execute([$fingerprint]);
        if ($db->query('SELECT fingerprint FROM platform_network_policy WHERE id=1')->fetchColumn() !== $fingerprint) {
            throw new \RuntimeException('Platform network policy changed; explicit migration required');
        }
        $driver = new ProviderApplicationLinuxNetwork($this->providerAddress, $this->edgeAddress, $this->forwardTable, $this->forwardChain);
        return new ProviderApplicationNetwork($directory, $bindings, $driver->attach(...), $driver->probe(...), $driver->detach(...));
    }
}
