<?php

declare(strict_types=1);
namespace Greenwood\Provider;

/** Scoped Linux implementation. No global firewall flush, routes or sysctl mutation. */
final class ProviderApplicationLinuxNetwork
{
    public function __construct(private readonly string $providerAddress, private readonly string $edgeAddress,
        private readonly ?string $forwardTable = null, private readonly ?string $forwardChain = null)
    {
        if (($forwardTable === null) !== ($forwardChain === null)) throw new \InvalidArgumentException('Incomplete forward policy anchor');
        foreach (array_filter([$forwardTable, $forwardChain]) as $name) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name)) throw new \InvalidArgumentException('Invalid forward policy anchor');
        }
        foreach ([$providerAddress, $edgeAddress] as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                || !preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $ip)) {
                throw new \InvalidArgumentException('Network policy requires private IPv4 addresses');
            }
        }
    }

    public function attach(array $b, int $pid): array
    {
        $this->validate($b);
        if (trim((string)file_get_contents('/proc/sys/net/ipv4/ip_forward')) !== '1') {
            throw new \RuntimeException('Platform IPv4 forwarding is not enabled');
        }
        $identity = $this->identity($pid);
        // PHP's ordinary file wrapper resolves nsfs magic links as filesystem paths.
        // Open through libc, then duplicate into a PHP stream for proc_open FD passing.
        $libc = \FFI::cdef('int open(const char *pathname, int flags, ...); int close(int fd);', 'libc.so.6');
        $fd = $libc->open('/proc/' . $pid . '/ns/net', 0);
        if ($fd < 0) throw new \RuntimeException('Runtime namespace open failed');
        try { $ns = fopen('php://fd/' . $fd, 'r'); } finally { $libc->close($fd); }
        if ($ns === false) throw new \RuntimeException('Runtime namespace unavailable');
        try {
            if ($identity !== $this->identity($pid)) throw new \RuntimeException('Runtime namespace changed');
            $host = $this->link($b['host_veth']);
            $peer = $this->link($b['application_veth'], $ns);
            if ($host !== null) $this->assertOwned($host, $b);
            if ($peer !== null) $this->assertOwned($peer, $b);
            if ($host !== null && $peer === null) {
                $this->detach($b); // Old namespace/PID. Only owned resources are removed.
                $host = null;
            }
            if ($host === null) {
                if ($peer !== null || $this->link($b['application_veth']) !== null) {
                    throw new \RuntimeException('Network peer collision requires reconciliation');
                }
                $this->run(['/usr/sbin/ip', 'link', 'add', $b['host_veth'], 'alias', $this->owner($b),
                    'type', 'veth', 'peer', 'name', $b['application_veth']]);
                $this->run(['/usr/sbin/ip', 'link', 'set', $b['host_veth'], 'alias', $this->owner($b)]);
                $this->run(['/usr/sbin/ip', 'link', 'set', $b['application_veth'], 'alias', $this->owner($b)]);
                // Pass an open namespace FD: PID reuse cannot redirect the move.
                $this->run(['/usr/sbin/ip', 'link', 'set', $b['application_veth'], 'netns', '/proc/self/fd/3'], '', $ns);
            }
            $this->run(['/usr/sbin/ip', 'addr', 'replace', $b['host_address'], 'dev', $b['host_veth']]);
            $this->run(['/usr/sbin/ip', 'link', 'set', $b['host_veth'], 'up']);
            $this->ns($ns, ['addr', 'replace', $b['application_address'], 'dev', $b['application_veth']]);
            $this->ns($ns, ['link', 'set', $b['application_veth'], 'up']);
            $this->ns($ns, ['link', 'set', 'lo', 'up']);
            $this->ns($ns, ['route', 'replace', 'default', 'via', explode('/', $b['host_address'])[0], 'dev', $b['application_veth']]);
            $this->firewall($b);
            if ($identity !== $this->identity($pid)) throw new \RuntimeException('Runtime changed during attachment');
            return ['namespace_identity' => $identity];
        } finally { fclose($ns); }
    }

    public function probe(array $b): bool
    {
        $this->validate($b);
        if (($b['namespace_identity'] ?? '') !== $this->identity((int)($b['pid'] ?? 0))) return false;
        $host = $this->link($b['host_veth']);
        if ($host === null) return false;
        $this->assertOwned($host, $b);
        if (!$this->tableExists($b)) return false;
        $url = 'http://' . explode('/', $b['application_address'])[0] . ':' . $b['application_port'] . '/';
        $response = $this->run(['/usr/bin/curl', '--noproxy', '*', '--silent', '--show-error', '--output', '/dev/null',
            '--write-out', '%{http_code}', '--connect-timeout', '2', '--max-time', '3', $url]);
        return preg_match('/^2[0-9]{2}$/D', $response) === 1;
    }

    public function detach(array $b): bool
    {
        $this->validate($b);
        $remove = $this->removeForwardRules($b);
        if ($remove !== '') $this->run(['/usr/sbin/nft', '-f', '-'], $remove);
        if ($this->tableExists($b)) $this->run(['/usr/sbin/nft', 'delete', 'table', 'inet', $this->table($b)]);
        foreach ([$b['host_veth'], $b['application_veth']] as $name) {
            $link = $this->link($name);
            if ($link !== null) {
                $this->assertOwned($link, $b);
                $this->run(['/usr/sbin/ip', 'link', 'delete', $name]);
            }
        }
        return !$this->tableExists($b) && $this->link($b['host_veth']) === null && $this->link($b['application_veth']) === null;
    }

    private function firewall(array $b): void
    {
        $table = $this->table($b); $owner = $this->owner($b);
        $host = $b['host_veth']; $port = $b['ingress_port']; $appPort = $b['application_port'];
        $app = explode('/', $b['application_address'])[0];
        $script = $this->tableExists($b) ? "delete table inet {$table}\n" : '';
        $script .= "table inet {$table} {\n comment \"{$owner}\"\n"
            . " chain ingress { type nat hook prerouting priority dstnat; policy accept;\n"
            . " ip saddr {$this->edgeAddress} ip daddr {$this->providerAddress} tcp dport {$port} dnat ip to {$app}:{$appPort}\n }\n"
            . " chain input_guard { type filter hook input priority -5; policy accept;\n"
            . " ip daddr {$this->providerAddress} tcp dport {$port} drop\n }\n"
            . " chain forward_guard { type filter hook forward priority -5; policy accept;\n"
            . " oifname \"{$host}\" ip saddr {$this->edgeAddress} ip daddr {$app} tcp dport {$appPort} accept\n"
            . " iifname \"{$host}\" ip saddr {$app} ip daddr {$this->edgeAddress} ct state established,related accept\n"
            . " iifname \"{$host}\" drop\n oifname \"{$host}\" drop\n }\n"
            . " chain host_guard { type filter hook input priority -5; policy accept;\n"
            . " iifname \"{$host}\" ct state established,related accept\n iifname \"{$host}\" drop\n }\n}\n";
        if ($this->forwardTable !== null) {
            $script .= $this->removeForwardRules($b);
            $anchor = 'inet ' . $this->forwardTable . ' ' . $this->forwardChain;
            $script .= "add rule {$anchor} oifname \"{$host}\" ip saddr {$this->edgeAddress} ip daddr {$app} tcp dport {$appPort} accept comment \"{$owner}\"\n";
            $script .= "add rule {$anchor} iifname \"{$host}\" ip saddr {$app} ip daddr {$this->edgeAddress} ct state established,related accept comment \"{$owner}\"\n";
        }
        $this->run(['/usr/sbin/nft', '--check', '-f', '-'], $script);
        $this->run(['/usr/sbin/nft', '-f', '-'], $script);
    }

    private function removeForwardRules(array $b): string
    {
        if ($this->forwardTable === null) return '';
        $rules = json_decode($this->run(['/usr/sbin/nft', '-j', '-a', 'list', 'chain', 'inet', $this->forwardTable, $this->forwardChain]), true, 512, JSON_THROW_ON_ERROR);
        $script = '';
        foreach ($rules['nftables'] ?? [] as $entry) {
            $rule = $entry['rule'] ?? [];
            if (($rule['comment'] ?? '') === $this->owner($b)) {
                $script .= 'delete rule inet ' . $this->forwardTable . ' ' . $this->forwardChain . ' handle ' . (int)$rule['handle'] . "\n";
            }
        }
        return $script;
    }

    private function tableExists(array $b): bool
    {
        $tables = json_decode($this->run(['/usr/sbin/nft', '-j', 'list', 'tables']), true, 512, JSON_THROW_ON_ERROR);
        foreach ($tables['nftables'] ?? [] as $entry) {
            if (($entry['table']['family'] ?? null) === 'inet' && ($entry['table']['name'] ?? null) === $this->table($b)) {
                $detail = json_decode($this->run(['/usr/sbin/nft', '-j', 'list', 'table', 'inet', $this->table($b)]), true, 512, JSON_THROW_ON_ERROR);
                foreach ($detail['nftables'] as $part) {
                    if (isset($part['table']) && ($part['table']['comment'] ?? '') === $this->owner($b)) return true;
                }
                throw new \RuntimeException('Firewall table ownership mismatch');
            }
        }
        return false;
    }

    private function identity(int $pid): string
    {
        if ($pid < 2) throw new \RuntimeException('Invalid runtime PID');
        $ns = @readlink('/proc/' . $pid . '/ns/net');
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($ns === false || $stat === false || $ns === readlink('/proc/self/ns/net')) {
            throw new \RuntimeException('Runtime requires an isolated live network namespace');
        }
        $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
        return trim((string)file_get_contents('/proc/sys/kernel/random/boot_id')) . ':' . $pid . ':' . $fields[19] . ':' . $ns;
    }

    private function link(string $name, mixed $namespace = null): ?array
    {
        $output = $namespace === null ? $this->run(['/usr/sbin/ip', '-j', 'link', 'show']) : $this->ns($namespace, ['-j', 'link', 'show']);
        foreach (json_decode($output, true, 512, JSON_THROW_ON_ERROR) as $link) if ($link['ifname'] === $name) return $link;
        return null;
    }
    private function assertOwned(array $link, array $b): void
    {
        if (($link['ifalias'] ?? '') !== $this->owner($b)) throw new \RuntimeException('Network link ownership mismatch');
    }
    private function owner(array $b): string { return 'greenwood-' . $b['host_veth']; }
    private function table(array $b): string { return 'gwapp_' . $b['host_veth']; }
    private function ns(mixed $fd, array $args): string
    {
        return $this->run(array_merge(['/usr/bin/nsenter', '--net=/proc/self/fd/3', '--', '/usr/sbin/ip'], $args), '', $fd);
    }
    private function validate(array $b): void
    {
        foreach (['host_veth', 'application_veth'] as $k) if (!preg_match('/^gw[ap][a-f0-9]{10}$/D', $b[$k] ?? '')) throw new \InvalidArgumentException('Invalid owned link');
        foreach (['host_address', 'application_address'] as $k) {
            $parts = explode('/', $b[$k] ?? '');
            if (count($parts) !== 2 || $parts[1] !== '30' || !filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                || !preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $parts[0])) throw new \InvalidArgumentException('Invalid binding subnet');
        }
        foreach (['ingress_port', 'application_port'] as $k) if (!is_int($b[$k] ?? null) || $b[$k] < 1 || $b[$k] > 65535) throw new \InvalidArgumentException('Invalid binding port');
    }

    private function run(array $argv, string $input = '', mixed $namespace = null): string
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if ($namespace !== null) $descriptors[3] = $namespace;
        $process = proc_open($argv, $descriptors, $pipes, null, ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL' => 'C']);
        if (!is_resource($process)) throw new \RuntimeException('Network command unavailable');
        fwrite($pipes[0], $input); fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $output = ''; $error = ''; $deadline = microtime(true) + 10;
        do {
            $output .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) > $deadline) { proc_terminate($process, 9); break; }
            usleep(10000);
        } while (true);
        $output .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        if ($status['running'] || $status['exitcode'] !== 0) throw new \RuntimeException('Network command failed: ' . basename($argv[0]) . ': ' . substr(trim($error), 0, 500));
        return trim($output);
    }
}
