<?php
declare(strict_types=1);

namespace Greenwood\Compute\Containerd\Ttrpc;

use Google\Protobuf\Internal\Message;
use Ttrpc\KeyValue;
use Ttrpc\Request;
use Ttrpc\Response;

/** Unary TTRPC v1.2.7 transport; protobuf remains entirely generated. */
final class TtrpcClient
{
    public const MAX_FRAME_LENGTH = 4 << 20;
    private int $nextStreamId = 1;

    public function __construct(
        private readonly string $socketPath = '/run/containerd/containerd.sock.ttrpc',
    ) {
        if (!str_starts_with($socketPath, '/') || str_contains($socketPath, "\0")) {
            throw new \InvalidArgumentException('An absolute Unix socket path is required');
        }
    }

    /**
     * Each unary call owns its connection, including failure cleanup. No retry:
     * a timeout does not imply that the server cancelled an operation's effects.
     * @param class-string<Message> $responseClass
     * @param array<string, string> $metadata
     */
    public function unary(
        string $service,
        string $method,
        Message $payload,
        string $responseClass,
        array $metadata = [],
        ?float $timeout = null,
    ): Message {
        $timeout ??= 30.0;
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 3600) {
            throw new \InvalidArgumentException('Timeout must be in (0, 3600] seconds');
        }
        if ($service === '' || $method === '' || !is_subclass_of($responseClass, Message::class)) {
            throw new \InvalidArgumentException('Service, method and protobuf response class required');
        }
        $deadline = hrtime(true) + (int) ceil($timeout * 1_000_000_000);
        $request = new Request();
        $request->setService($service);
        $request->setMethod($method);
        $request->setPayload($payload->serializeToString());
        $request->setTimeoutNano((int) ceil($timeout * 1_000_000_000));
        $entries = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || $key === '' || !is_string($value)) {
                throw new \InvalidArgumentException('Metadata must contain string keys and values');
            }
            $entries[] = (new KeyValue())->setKey($key)->setValue($value);
        }
        $request->setMetadata($entries);
        $bytes = $request->serializeToString();
        if (strlen($bytes) > self::MAX_FRAME_LENGTH) {
            throw new TtrpcException('Request exceeds TTRPC frame limit');
        }
        if ($this->nextStreamId > 0xffffffff) {
            throw new TtrpcException('TTRPC stream IDs exhausted');
        }
        $id = $this->nextStreamId;
        $this->nextStreamId += 2;
        $socket = @stream_socket_client(
            'unix://' . $this->socketPath, $errno, $error,
            $this->remaining($deadline), STREAM_CLIENT_CONNECT,
        );
        if ($socket === false) {
            throw new TtrpcException('TTRPC connect failed: ' . $error, $errno);
        }
        try {
            if (!stream_set_blocking($socket, false)) {
                throw new TtrpcException('Cannot configure TTRPC socket');
            }
            $this->writeAll($socket, pack('NNCC', strlen($bytes), $id, 1, 0) . $bytes, $deadline);
            $header = unpack('Nlength/Nstream/Ctype/Cflags', $this->readExact($socket, 10, $deadline));
            if ($header['length'] > self::MAX_FRAME_LENGTH) {
                throw new TtrpcException('Response exceeds TTRPC frame limit');
            }
            // Pinned server.go sends unary Responses with flags=0.
            if ($header['stream'] !== $id || $header['type'] !== 2 || $header['flags'] !== 0) {
                throw new TtrpcException('Invalid TTRPC response stream, type or flags');
            }
            $response = new Response();
            $response->mergeFromString($this->readExact($socket, $header['length'], $deadline));
            $status = $response->getStatus();
            if ($status === null) {
                throw new TtrpcException('Missing TTRPC response status');
            }
            if ($status->getCode() !== 0) {
                // The pinned schema defines global Status(code); unknown fields
                // survive generated parsing, allowing Google's complete status.
                $details = new \Google\Rpc\Status();
                $details->mergeFromString($status->serializeToString());
                throw new TtrpcException('TTRPC status: ' . $details->getMessage(), $status->getCode());
            }
            $result = new $responseClass();
            $result->mergeFromString($response->getPayload());
            return $result;
        } catch (TtrpcException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TtrpcException('Invalid TTRPC protobuf response', 0, $e);
        } finally {
            fclose($socket);
        }
    }

    private function remaining(int $deadline): float
    {
        $remaining = ($deadline - hrtime(true)) / 1_000_000_000;
        if ($remaining <= 0) {
            throw new TtrpcException('TTRPC deadline exceeded');
        }
        return $remaining;
    }

    /** @param resource $socket */
    private function ready($socket, bool $writing, int $deadline): void
    {
        $remaining = $this->remaining($deadline);
        $seconds = (int) $remaining;
        $micros = (int) (($remaining - $seconds) * 1_000_000);
        $read = $writing ? [] : [$socket];
        $write = $writing ? [$socket] : [];
        $except = [];
        $count = @stream_select($read, $write, $except, $seconds, $micros);
        if ($count === false) {
            throw new TtrpcException('TTRPC socket wait failed');
        }
        if ($count === 0) {
            throw new TtrpcException('TTRPC deadline exceeded');
        }
    }

    /** @param resource $socket */
    private function writeAll($socket, string $bytes, int $deadline): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $this->ready($socket, true, $deadline);
            $written = @fwrite($socket, substr($bytes, $offset, 65536));
            if ($written === false || $written === 0) {
                throw new TtrpcException('TTRPC write stalled or failed');
            }
            $offset += $written;
        }
    }

    /** @param resource $socket */
    private function readExact($socket, int $length, int $deadline): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $this->ready($socket, false, $deadline);
            $chunk = @fread($socket, min(65536, $length - strlen($bytes)));
            if ($chunk === false || $chunk === '') {
                throw new TtrpcException('TTRPC short read or EOF');
            }
            $bytes .= $chunk;
        }
        return $bytes;
    }
}
