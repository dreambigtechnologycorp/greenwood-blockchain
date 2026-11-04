<?php
declare(strict_types=1);
namespace Greenwood\Rpc;

/**
 * Bounded blocking RPC client for the CLI and operator tooling.
 * Supports http://host:port and unix:///path endpoints. No shell execution.
 */
final class RpcClient
{
    private const CONNECT_TIMEOUT = 3.0;
    private const READ_TIMEOUT = 5;
    private const MAX_RESPONSE = 2097152;

    public function __construct(public readonly string $endpoint)
    {
        $ok = str_starts_with($endpoint, 'unix:///')
            || (parse_url($endpoint)['scheme'] ?? null) === 'http';
        if (!$ok) throw new \InvalidArgumentException('Endpoint must be http://host:port or unix:///path');
    }

    /** @return array{status:int, content_type:string, body:string} */
    public function request(string $method, string $path, ?string $body = null, ?string $bearerToken = null): array
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, "\r") || str_contains($path, "\n")) {
            throw new \InvalidArgumentException('Invalid request path');
        }
        if (str_starts_with($this->endpoint, 'unix:///')) {
            $socket = @stream_socket_client('unix://' . substr($this->endpoint, 7), $errno, $error, self::CONNECT_TIMEOUT);
            $host = 'localhost';
        } else {
            $url = parse_url($this->endpoint);
            $host = $url['host'];
            $socket = @stream_socket_client('tcp://' . $host . ':' . ($url['port'] ?? 80), $errno, $error, self::CONNECT_TIMEOUT);
        }
        if ($socket === false) throw new \RuntimeException('RPC endpoint unreachable');
        stream_set_timeout($socket, self::READ_TIMEOUT);
        $request = $method . ' ' . $path . " HTTP/1.1\r\nHost: " . $host . "\r\nContent-Type: application/json\r\n"
            . ($bearerToken !== null ? 'Authorization: Bearer ' . $bearerToken . "\r\n" : '')
            . 'Content-Length: ' . strlen($body ?? '') . "\r\nConnection: close\r\n\r\n" . ($body ?? '');
        fwrite($socket, $request);
        $raw = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 65536);
            if ($chunk === false) break;
            $raw .= $chunk;
            if (strlen($raw) > self::MAX_RESPONSE) { fclose($socket); throw new \RuntimeException('RPC response too large'); }
        }
        fclose($socket);
        $end = strpos($raw, "\r\n\r\n");
        if ($end === false || !preg_match('~^HTTP/\S+ (\d{3})~', $raw, $m)) throw new \RuntimeException('Invalid RPC response');
        $headers = [];
        foreach (explode("\r\n", substr($raw, 0, $end)) as $line) {
            if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); }
        }
        return ['status' => (int) $m[1], 'content_type' => $headers['content-type'] ?? '', 'body' => substr($raw, $end + 4)];
    }

    /** @return array{status:int, data:array} */
    public function json(string $method, string $path, ?string $body = null, ?string $bearerToken = null): array
    {
        $r = $this->request($method, $path, $body, $bearerToken);
        $data = json_decode($r['body'], true, 32);
        if (!is_array($data)) throw new \RuntimeException('Invalid RPC JSON response');
        return ['status' => $r['status'], 'data' => $data];
    }
}
