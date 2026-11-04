<?php

declare(strict_types=1);

namespace Greenwood\Edge;

/** Dedicated loopback ingress. Never changes existing port-80/operator vhosts. */
final class ApacheIngressConfiguration
{
    public function __construct(private readonly int $listenPort = 8081, private readonly ?string $proxySourceAddress = null)
    {
        if ($listenPort < 1024 || $listenPort > 65535) throw new \InvalidArgumentException('Invalid ingress port');
        if ($proxySourceAddress !== null && !filter_var($proxySourceAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new \InvalidArgumentException('Invalid proxy source address');
    }

    /** Render only trusted controller routes. Installation/reload is a separate adapter. */
    public function render(array $routes, ?string $generation = null): string
    {
        if ($generation !== null && !preg_match('/^[a-f0-9]{64}$/D', $generation)) throw new \InvalidArgumentException('Invalid generation');
        $listen = '127.0.0.1:' . $this->listenPort;
        $config = "# Managed by Greenwood Edge Controller.\nListen {$listen}\n"
            . "<VirtualHost {$listen}>\n    ServerName greenwood-unpublished.invalid\n"
            . "    ProxyRequests Off\n" . ($generation === null ? "" : "    ErrorDocument 403 \"greenwood-generation-{$generation}\"\n") . "    <Location />\n        Require all denied\n    </Location>\n</VirtualHost>\n";
        usort($routes, static fn(array $a, array $b): int => strcmp($a['hostname'], $b['hostname']));
        $seen = [];
        foreach ($routes as $route) {
            $host = $route['hostname'] ?? '';
            $binding = $route['binding'] ?? [];
            $address = $binding['address'] ?? '';
            $port = $binding['port'] ?? 0;
            $id = $route['application_id'] ?? null;
            $service = $route['service'] ?? '';
            $prefixes = is_int($id) && $id > 0 && is_string($service) && preg_match('/^[a-z][a-z0-9-]{0,62}$/D',$service)
                ? ['gw-'.$id.'-'.$service.'.','app-'.$id.'.'] : [];
            $owned = is_string($host) && array_filter($prefixes, static fn(string $prefix):bool=>str_starts_with($host,$prefix)) !== [];
            if (!$owned || !preg_match('/^(?:gw-[1-9][0-9]*-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?|app-[1-9][0-9]*)\.(?:[a-z0-9-]+\.)+[a-z]{2,}$/D', $host)
                || strlen($host) > 253 || isset($seen[$host])
                || !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                || !preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $address)
                || !is_int($port) || $port < 1024 || $port > 65535
                || ($route['desired'] ?? null) !== 'PUBLISHED' || ($route['state'] ?? null) !== 'HEALTHY') {
                throw new \InvalidArgumentException('Invalid authorized ingress route');
            }
            $seen[$host] = true;
            $origin = 'http://' . $address . ':' . $port . '/';
            $config .= "<VirtualHost {$listen}>\n    ServerName {$host}\n    ProxyRequests Off\n"
                . ($this->proxySourceAddress === null ? "" : "    ProxySourceAddress {$this->proxySourceAddress}\n")
                . "    ProxyPreserveHost On\n    <Location />\n        Require all granted\n    </Location>\n"
                . "    ProxyPass / {$origin} connectiontimeout=5 timeout=30\n"
                . "    ProxyPassReverse / {$origin}\n</VirtualHost>\n";
        }
        return $config;
    }
}
