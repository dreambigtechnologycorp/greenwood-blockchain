<?php

namespace Greenwood\Compute\Containerd;

use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;
use Greenwood\Contracts\ContainerdUnavailableException;
use Grpc\Channel;
use Grpc\ChannelCredentials;
use Grpc\Timeval;
use Containerd\Services\Version\V1\VersionClient;
use Google\Protobuf\GPBEmpty;

/**
 * Manages the gRPC connection to containerd
 */
class ContainerdConnection
{
    private const DEFAULT_NAMESPACE = "greenwood";
    private const SOCKET_PATH = "unix:///run/containerd/containerd.sock";
    private const DEADLINE_SECONDS = 30;

    private ?Channel $channel = null;
    private string $namespace;
    private array $channelArgs;

    /**
     * @param string $namespace containerd namespace
     * @param array $channelArgs Extra gRPC channel args. grpc-php SHARES
     *   channels with identical target+args process-wide; after fork() a
     *   child MUST construct a distinct channel (e.g. a unique
     *   'grpc.primary_user_agent') or its RPCs will hang on the parent's
     *   broken channel.
     */
    public function __construct(string $namespace = self::DEFAULT_NAMESPACE, array $channelArgs = [], private readonly string $socketPath = '/run/containerd/containerd.sock')
    {
        if (!str_starts_with($socketPath, '/') || str_contains($socketPath, "\0")) throw new \InvalidArgumentException('Absolute containerd socket path required');
        $this->namespace = $namespace;
        $this->channelArgs = $channelArgs;
    }

    public function getChannel(): Channel
    {
        if ($this->channel === null) {
            $this->channel = new Channel('unix://' . $this->socketPath, [
                "credentials" => ChannelCredentials::createInsecure(),
            ] + $this->channelArgs);
        }
        return $this->channel;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Get containerd namespace metadata for RPC calls.
     * Every namespaced RPC must use this method.
     *
     * @return array<string, list<string>> Metadata array for gRPC calls
     */
    public function getMetadata(): array
    {
        return ["containerd-namespace" => [$this->namespace]];
    }

    /**
     * Check if containerd connection is available.
     * Uses actual Version RPC call rather than connectivity state.
     *
     * @return bool true if connection is healthy
     */
    public function checkConnection(): bool
    {
        try {
            $channel = $this->getChannel();
            $client = new VersionClient(
                $channel->getTarget(),
                ["credentials" => ChannelCredentials::createInsecure()],
                $channel
            );
            $request = new GPBEmpty();
            [$reply, $status] = $client->Version($request, [], ["timeout" => 30_000_000])->wait();
            return $status->code === \Grpc\STATUS_OK;
        } catch (Exception $e) {
            return false;
        }
    }

    public function close(): void
    {
        if ($this->channel !== null) {
            $this->channel->close();
            $this->channel = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
