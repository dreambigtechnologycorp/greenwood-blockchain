<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Registry;

final class ReadOnlyRpc
{
    public static function connect(string $endpoint, string $chain): GreenwoodRpc
    {
        $url = parse_url($endpoint);
        if (!$url || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || !isset($url['host'])
            || array_diff(array_keys($url), ['scheme', 'host', 'port'])) {
            throw new \InvalidArgumentException('RPC_ORIGIN_REQUIRED');
        }
        return new GreenwoodRpc($endpoint, $chain, static function ($method, $path, $body, $timeout) use ($endpoint): array {
            if ($method !== 'GET' && !($method === 'POST' && preg_match('~^/v1/contracts/[a-f0-9]{64}/call$~D', $path))) {
                throw new \RuntimeException('READ_ONLY_RPC_REQUIRED');
            }
            $context = stream_context_create(['http' => [
                'method' => $method, 'timeout' => $timeout, 'follow_location' => 0, 'ignore_errors' => true,
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
            ]]);
            $stream = @fopen($endpoint . $path, 'rb', false, $context);
            if ($stream === false) throw new \RuntimeException('RPC_UNAVAILABLE');
            try {
                $raw = stream_get_contents($stream, 1048577);
                $meta = stream_get_meta_data($stream);
                if ($raw === false || strlen($raw) > 1048576 || $meta['timed_out']) throw new \RuntimeException('RPC_RESPONSE_INVALID');
                $headers = $meta['wrapper_data'] ?? [];
                if (!preg_match('~^HTTP/\S+ ([0-9]{3})~', $headers[0] ?? '', $match)) throw new \RuntimeException('RPC_STATUS_INVALID');
                return [(int)$match[1], $raw, []];
            } finally { fclose($stream); }
        });
    }
}
