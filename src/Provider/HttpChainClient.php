<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Consensus\Network\Transport;
use Greenwood\Transaction\Envelope;

/** Bounded polling client. A configured node supplies finalized views, never worker facts. */
final class HttpChainClient implements ChainClient
{
    public function __construct(private readonly string $endpoint, private readonly string $chainId) {}

    private function call(string $path, ?string $body = null): array
    {
        $context = stream_context_create(['http' => ['method' => $body === null ? 'GET' : 'POST',
            'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($body ?? '') . "\r\nConnection: close\r\n",
            'content' => $body ?? '', 'timeout' => 3, 'follow_location' => 0, 'ignore_errors' => true]]);
        $stream = @fopen(rtrim($this->endpoint, '/') . $path, 'rb', false, $context);
        if ($stream === false) throw new \RuntimeException('Chain transport unavailable');
        try {
            $metadata = stream_get_meta_data($stream);
            $raw = stream_get_contents($stream, 1048577);
            if ($raw === false || strlen($raw) > 1048576 || !preg_match('~^HTTP/\S+ 200\b~', $metadata['wrapper_data'][0] ?? '')) throw new \RuntimeException('Chain HTTP response rejected');
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new \RuntimeException('Invalid chain response');
            return $data;
        } finally { fclose($stream); }
    }
    private function view(string $path): array
    {
        $view = $this->call($path);
        if (($view['chain_id'] ?? null) !== $this->chainId || !is_int($view['finalized_height'] ?? null) || $view['finalized_height'] < 0) throw new \RuntimeException('Wrong chain or invalid finalized height');
        foreach (['state_root', 'finalized_hash'] as $name) if (!is_string($view[$name] ?? null) || !preg_match('/^[0-9a-f]{64}$/D', $view[$name])) throw new \RuntimeException('Invalid chain commitment');
        return $view;
    }
    private static function id(string $id): string
    {
        if (strlen($id) !== 32) throw new \InvalidArgumentException('Expected 32-byte identifier');
        return bin2hex($id);
    }
    public function assignments(string $providerId, ?string $after = null): array
    {
        $data = $this->view('/compute/providers/' . self::id($providerId) . '/assignments/' . ($after === null ? '-' : self::id($after)));
        if (!is_array($data['request_ids'] ?? null) || count($data['request_ids']) > 64) throw new \RuntimeException('Invalid assignment page');
        $previous = $after === null ? '' : bin2hex($after);
        foreach ($data['request_ids'] as $id) {
            if (!is_string($id) || !preg_match('/^[0-9a-f]{64}$/D', $id) || strcmp($id, $previous) <= 0) throw new \RuntimeException('Invalid assignment ordering');
            $previous = $id;
        }
        if (!array_key_exists('next', $data) || ($data['next'] !== null && ($data['request_ids'] === [] || $data['next'] !== $previous))) throw new \RuntimeException('Invalid assignment cursor');
        return $data;
    }
    public function request(string $requestId): array
    {
        $data = $this->view('/compute/requests/' . self::id($requestId));
        if (($data['request_id'] ?? null) !== bin2hex($requestId) || !is_array($data['records'] ?? null) || count($data['records']) > 3) throw new \RuntimeException('Invalid request view');
        return $data;
    }
    public function nonce(string $accountId): int
    {
        $data = $this->view('/accounts/' . self::id($accountId) . '/nonce');
        if (!is_int($data['nonce'] ?? null) || $data['nonce'] < 0 || $data['nonce'] > 0xffffffff) throw new \RuntimeException('Invalid provider account nonce');
        return $data['nonce'];
    }
    public function capability(string $providerId): ?array
    {
        // Read-only Pass 5 provider view; 404 means no capability registered.
        $context = stream_context_create(['http' => ['method' => 'GET',
            'header' => "Content-Type: application/json\r\nContent-Length: 0\r\nConnection: close\r\n",
            'timeout' => 3, 'follow_location' => 0, 'ignore_errors' => true]]);
        $stream = @fopen(rtrim($this->endpoint, '/') . '/v1/compute/providers/' . self::id($providerId), 'rb', false, $context);
        if ($stream === false) throw new \RuntimeException('Chain transport unavailable');
        try {
            $metadata = stream_get_meta_data($stream);
            $raw = stream_get_contents($stream, 65537);
            $statusLine = $metadata['wrapper_data'][0] ?? '';
            if (preg_match('~^HTTP/\S+ 404\b~', $statusLine)) return null;
            if ($raw === false || strlen($raw) > 65536 || !preg_match('~^HTTP/\S+ 200\b~', $statusLine)) throw new \RuntimeException('Chain capability view rejected');
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (($data['provider_id'] ?? null) !== self::id($providerId) || !in_array($data['status'] ?? null, ['ACTIVE', 'DRAINING', 'SUSPENDED'], true)
                || !is_array($data['architectures'] ?? null) || !is_int($data['capability_nonce'] ?? null)
                || !is_int($data['cpu_capacity_millis'] ?? null) || !is_string($data['memory_capacity_bytes'] ?? null)
                || !is_int($data['pid_capacity'] ?? null) || !is_int($data['max_concurrent_jobs'] ?? null)) {
                throw new \RuntimeException('Invalid chain capability view');
            }
            return $data;
        } finally { fclose($stream); }
    }
    public function submit(Envelope $transaction): string
    {
        $transaction->verify($this->chainId);
        $data = $this->call('/transactions', Transport::envelope('transaction', $transaction->getCanonicalBytes()));
        $hash = bin2hex($transaction->getHash());
        if (($data['accepted'] ?? null) !== true || ($data['hash'] ?? null) !== $hash) throw new \RuntimeException('Transaction acceptance uncertain');
        return $hash;
    }
}
