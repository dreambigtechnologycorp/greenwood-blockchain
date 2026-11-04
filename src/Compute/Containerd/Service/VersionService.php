<?php

namespace Greenwood\Compute\Containerd\Service;

use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;
use Greenwood\Contracts\ContainerdUnavailableException;
use Google\Protobuf\GPBEmpty;

/**
 * Version service wrapper for containerd
 */
class VersionService
{
    private ContainerdClientSet $clients;

    public function __construct(ContainerdClientSet $clients)
    {
        $this->clients = $clients;
    }

    /**
     * Get containerd version information
     *
     * @return array{version: string, revision: string}
     *
     * @throws ContainerdUnavailableException
     * @throws ContainerdRpcException
     */
    public function getVersion(): array
    {
        try {
            $client = $this->clients->getVersion();
            $namespace = $this->clients->getConnection()->getNamespace();
            $timeoutMicros = 30_000_000; // 30 seconds in microseconds

            $request = new GPBEmpty();
            $metadata = [];

            [$reply, $status] = $client->Version($request, $metadata, ["timeout" => $timeoutMicros])->wait();

            if ($status->code !== \Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    "Version RPC failed: " . $status->details,
                    $status->code
                );
            }

            if ($reply === null) {
                throw new ContainerdRpcException(
                    "Version RPC returned null response",
                    Grpc\STATUS_UNKNOWN
                );
            }

            return [
                "version" => $reply->getVersion(),
                "revision" => $reply->getRevision(),
            ];
        } catch (\Grpc\Exception\RpcException $e) {
            if ($e->getCode() === Grpc\STATUS_UNAVAILABLE) {
                throw new ContainerdUnavailableException("containerd service unavailable: " . $e->getMessage(), $e->getCode(), $e);
            }
            throw new ContainerdRpcException("RPC failed: " . $e->getMessage(), $e->getCode(), $e);
        } catch (\Exception $e) {
            throw new ContainerdException("Version check failed: " . $e->getMessage(), $e->getCode(), $e);
        }
    }
}
