<?php

declare(strict_types=1);
namespace Greenwood\ControlPlane;

/** Bounded, mutually authenticated control messages. Not a public HTTP listener. */
final class MutualTlsChannel
{
    private const MAX_MESSAGE = 65536;
    public function __construct(
        private readonly string $caFile,
        private readonly string $certificateFile,
        #[\SensitiveParameter] private readonly string $privateKeyFile,
        private readonly array $peerIdentities,
        private readonly array $peerUris,
        private readonly string $revocationFile,
        private readonly ?SignedPolicySnapshot $signedRevocations = null,
    ) {
        foreach ([$caFile, $certificateFile, $privateKeyFile] as $path) {
            if (!is_file($path) || !is_readable($path)) throw new \InvalidArgumentException('Control-plane TLS material unavailable');
        }
        if ((fileperms($privateKeyFile) & 0077) !== 0 || !in_array(fileowner($privateKeyFile), [0, posix_geteuid()], true)) {
            throw new \InvalidArgumentException('Control-plane private key must have private ownership and permissions');
        }
        foreach ($peerIdentities as $fingerprint => $identity) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $fingerprint) || !is_string($identity) || $identity === ''
                || !isset($peerUris[$fingerprint])
                || !preg_match('~^spiffe://greenwood-physical-v1/control-plane/(deployment-controller|edge-controller|edge-admission|assignment/node-[14]|provider/node-[123])$~D', $peerUris[$fingerprint])) {
                throw new \InvalidArgumentException('Invalid pinned service identity');
            }
        }
    }

    public function request(string $endpoint, string $peerName, string $expectedIdentity, array $message, int $timeoutSeconds = 5): array
    {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 30) throw new \InvalidArgumentException('Invalid control-plane timeout');
        $this->endpoint($endpoint);
        $context = stream_context_create(['ssl' => $this->options() + ['verify_peer_name' => true, 'peer_name' => $peerName]]);
        $stream = @stream_socket_client($endpoint, $errno, $error, 5, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) throw new \RuntimeException('Control-plane connection unavailable');
        try {
            stream_set_timeout($stream, $timeoutSeconds);
            if (@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                throw new \RuntimeException('Control-plane TLS authentication failed');
            }
            if ($this->peer($stream) !== $expectedIdentity) throw new \RuntimeException('Unexpected control-plane peer');
            $challenge = bin2hex(random_bytes(32));
            $this->write($stream, ['version' => 1, 'challenge' => $challenge, 'message' => $message]);
            $response = $this->read($stream);
            if (($response['version'] ?? null) !== 1 || ($response['challenge'] ?? null) !== $challenge
                || !is_array($response['message'] ?? null)) throw new \RuntimeException('Control-plane response challenge mismatch');
            return $response['message'];
        } finally { fclose($stream); }
    }

    /** Caller owns listener lifecycle and placement; constructing a channel opens nothing. */
    public function listen(string $endpoint): mixed
    {
        $this->endpoint($endpoint);
        $context = stream_context_create(['ssl' => $this->options() + ['verify_peer_name' => false]]);
        $listener = @stream_socket_server($endpoint, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($listener === false) throw new \RuntimeException('Control-plane listener unavailable');
        return $listener;
    }

    /** The handler receives identity exclusively from the verified pinned client cert. */
    public function serveOne(mixed $listener, \Closure $handler): void
    {
        $stream = @stream_socket_accept($listener, 5);
        if ($stream === false) throw new \RuntimeException('Control-plane accept timed out');
        try {
            stream_set_timeout($stream, 5);
            if (@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER) !== true) {
                throw new \RuntimeException('Control-plane client TLS authentication failed');
            }
            $principal = $this->peer($stream);
            $request = $this->read($stream);
            if (($request['version'] ?? null) !== 1 || !is_string($request['challenge'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $request['challenge']) || !is_array($request['message'] ?? null)) {
                throw new \RuntimeException('Invalid control-plane request');
            }
            $result = $handler($principal, $request['message']);
            $this->write($stream, ['version' => 1, 'challenge' => $request['challenge'], 'message' => $result]);
        } finally { fclose($stream); }
    }

    private function options(): array
    {
        return ['cafile' => $this->caFile, 'local_cert' => $this->certificateFile, 'local_pk' => $this->privateKeyFile,
            'verify_peer' => true, 'allow_self_signed' => false, 'capture_peer_cert' => true,
            'verify_depth' => 3, 'disable_compression' => true, 'security_level' => 2];
    }
    private function peer(mixed $stream): string
    {
        $cert = stream_context_get_options($stream)['ssl']['peer_certificate'] ?? null;
        $fingerprint = $cert === null ? false : openssl_x509_fingerprint($cert, 'sha256');
        if ($fingerprint === false || !isset($this->peerIdentities[$fingerprint])) throw new \RuntimeException('Unregistered control-plane certificate');
        $metadata = openssl_x509_parse($cert);
        $sans = explode(', ', $metadata['extensions']['subjectAltName'] ?? '');
        $uris = array_values(array_filter($sans, static fn(string $san): bool => str_starts_with($san, 'URI:')));
        if ($uris !== ['URI:' . $this->peerUris[$fingerprint]]) throw new \RuntimeException('Unexpected control-plane URI identity');
        if (($metadata['validFrom_time_t'] ?? PHP_INT_MAX) > time() || ($metadata['validTo_time_t'] ?? 0) <= time()) {
            throw new \RuntimeException('Expired or not yet valid control-plane certificate');
        }
        // Reload for every handshake; missing, stale or unsafe revocation policy fails closed.
        clearstatcache(true, $this->revocationFile);
        if (is_link($this->revocationFile) || !is_file($this->revocationFile)
            || (fileperms($this->revocationFile) & 0022) !== 0
            || !in_array(fileowner($this->revocationFile), [0, posix_geteuid()], true)) {
            throw new \RuntimeException('Unsafe control-plane revocation policy');
        }
        $policy = $this->signedRevocations?->read()
            ?? json_decode(file_get_contents($this->revocationFile), true, 8, JSON_THROW_ON_ERROR);
        if (!is_int($policy['valid_until'] ?? null) || $policy['valid_until'] <= time()
            || !is_array($policy['revoked_sha256'] ?? null) || !array_is_list($policy['revoked_sha256'])) {
            throw new \RuntimeException('Stale or invalid control-plane revocation policy');
        }
        foreach ($policy['revoked_sha256'] as $revoked) {
            if (!is_string($revoked) || !preg_match('/^[a-f0-9]{64}$/D', $revoked)) throw new \RuntimeException('Invalid revocation fingerprint');
        }
        if (in_array($fingerprint, $policy['revoked_sha256'], true)) throw new \RuntimeException('Revoked control-plane certificate');
        return $this->peerIdentities[$fingerprint];
    }
    private function endpoint(string $endpoint): void
    {
        $url = parse_url($endpoint);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'tcp' || !isset($url['host'], $url['port'])
            || array_diff(array_keys($url), ['scheme','host','port']) !== []
            || !filter_var($url['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || !preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $url['host'])
            || $url['port'] < 0 || $url['port'] > 65535) throw new \InvalidArgumentException('Control-plane endpoint must be explicit private/loopback IPv4');
    }
    private function write(mixed $stream, array $message): void
    {
        $body = json_encode($message, JSON_THROW_ON_ERROR);
        if (strlen($body) > self::MAX_MESSAGE) throw new \RuntimeException('Control-plane message too large');
        $bytes = pack('N', strlen($body)) . $body;
        $deadline = microtime(true) + 5;
        while ($bytes !== '') {
            $n = @fwrite($stream, $bytes);
            if ($n === false || $n === 0 || microtime(true) > $deadline) throw new \RuntimeException('Control-plane write failed');
            $bytes = substr($bytes, $n);
        }
    }
    private function read(mixed $stream): array
    {
        $length = unpack('N', $this->bytes($stream, 4))[1];
        if ($length < 2 || $length > self::MAX_MESSAGE) throw new \RuntimeException('Invalid control-plane frame length');
        $value = json_decode($this->bytes($stream, $length), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) throw new \RuntimeException('Invalid control-plane frame');
        return $value;
    }
    private function bytes(mixed $stream, int $length): string
    {
        $bytes = ''; $deadline = microtime(true) + 5;
        while (strlen($bytes) < $length) {
            $part = @fread($stream, $length - strlen($bytes));
            if ($part === false || $part === '' || microtime(true) > $deadline) throw new \RuntimeException('Incomplete control-plane frame');
            $bytes .= $part;
        }
        return $bytes;
    }
}
