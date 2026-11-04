<?php
declare(strict_types=1);
namespace Greenwood\Rpc;

/** Narrow LAN facade; the Unix operator API retains its existing route set. */
final class ProviderApplicationHttpApi
{
    private const ROUTES = [
        '/v1/operator/provider/applications/reserve',
        '/v1/operator/provider/applications/start',
        '/v1/operator/provider/applications/inspect',
        '/v1/operator/provider/applications/stop',
    ];

    public function __construct(
        private readonly OperatorApi $operator,
        private readonly OperatorAuth $auth,
        private readonly string $allowedPeer,
    ) {
        self::privateIpv4($allowedPeer);
    }

    public static function validateEndpoint(string $endpoint): string
    {
        $u = parse_url($endpoint);
        if (!is_array($u) || array_diff(array_keys($u), ['scheme','host','port']) !== []
            || ($u['scheme'] ?? '') !== 'http' || !isset($u['host'], $u['port'])
            || $u['port'] < 1 || $u['port'] > 65535) {
            throw new \InvalidArgumentException('Application listener requires an explicit private IPv4 HTTP address and port');
        }
        self::privateIpv4($u['host']);
        return $endpoint;
    }

    private static function privateIpv4(string $ip): void
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || !preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $ip)) {
            throw new \InvalidArgumentException('Application listener and connector peer must use RFC1918 IPv4 addresses');
        }
    }

    public function handle(string $method, string $path, string $body, array $headers, string $peer): HttpResponse
    {
        // Only Transport's socket peer is trusted, never forwarding headers.
        if (!preg_match('/^'.preg_quote($this->allowedPeer, '/').':[0-9]+$/D', $peer)) {
            return HttpResponse::json(['error'=>['code'=>'CONNECTOR_REQUIRED']], 403);
        }
        try { $this->auth->authorize($headers); }
        catch (ApiError $error) { return HttpResponse::error($error); }
        if ($method !== 'POST' || !in_array($path, self::ROUTES, true)) {
            return HttpResponse::error(ApiError::notFound('NOT_FOUND','Unknown application route'));
        }
        return $this->operator->handle($method, $path, $body, $headers);
    }
}
