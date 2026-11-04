<?php

namespace Greenwood\Compute\Containerd;

use Grpc\ChannelCredentials;
use Containerd\Services\Images\V1\ImagesClient;
use Containerd\Services\Version\V1\VersionClient;
use Containerd\Services\Snapshots\V1\SnapshotsClient;
use Containerd\Services\Containers\V1\ContainersClient;
use Containerd\Services\Tasks\V1\TasksClient;
use Containerd\Services\Content\V1\ContentClient;
use Containerd\Services\Transfer\V1\TransferClient;
use Containerd\Services\Leases\V1\LeasesClient;
use Greenwood\Compute\Containerd\Service\SnapshotService;
use Greenwood\Compute\Containerd\Service\ContainerService;
use Greenwood\Compute\Containerd\Service\TaskService;

class ContainerdClientSet
{
    private ContainerdConnection $connection;
    /** Execution snapshotter selected by provider config. Never silently falls back. */
    private string $snapshotter = 'overlayfs';

    public function __construct(ContainerdConnection $connection)
    {
        $this->connection = $connection;
    }

    public function setSnapshotter(string $snapshotter): self
    {
        if (!in_array($snapshotter, ['overlayfs', 'erofs'], true)) throw new \InvalidArgumentException('Unsupported snapshotter');
        $this->snapshotter = $snapshotter;
        return $this;
    }

    public function getSnapshotter(): string
    {
        return $this->snapshotter;
    }

    public function getConnection(): ContainerdConnection
    {
        return $this->connection;
    }

    public function getVersion(): VersionClient
    {
        $channel = $this->connection->getChannel();
        return new VersionClient($channel->getTarget(), ["credentials" => ChannelCredentials::createInsecure()], $channel);
    }

    public function getImages(): ImagesClient
    {
        return new ImagesClient(
            $this->connection->getChannel()->getTarget(),
            ["credentials" => ChannelCredentials::createInsecure()],
            $this->connection->getChannel()
        );
    }

    public function getSnapshots(): SnapshotsClient
    {
        return new SnapshotsClient(
            $this->connection->getChannel()->getTarget(),
            ["credentials" => ChannelCredentials::createInsecure()],
            $this->connection->getChannel()
        );
    }

    public function getContainers(): ContainersClient
    {
        return new ContainersClient(
            $this->connection->getChannel()->getTarget(),
            ["credentials" => ChannelCredentials::createInsecure()],
            $this->connection->getChannel()
        );
    }

    public function getTasks(): TasksClient
    {
        return new TasksClient(
            $this->connection->getChannel()->getTarget(),
            ["credentials" => ChannelCredentials::createInsecure()],
            $this->connection->getChannel()
        );
    }

    public function getContent(): ContentClient
    {
        return new ContentClient(
            $this->connection->getChannel()->getTarget(),
            ["credentials" => ChannelCredentials::createInsecure()],
            $this->connection->getChannel()
        );
    }

    public function getTransfer(): TransferClient
    {
        return new TransferClient(
            $this->connection->getChannel()->getTarget(),
            ["credentials" => ChannelCredentials::createInsecure()],
            $this->connection->getChannel()
        );
    }

    public function getLeases(): LeasesClient
    {
        return new LeasesClient(
            $this->connection->getChannel()->getTarget(),
            ["credentials" => ChannelCredentials::createInsecure()],
            $this->connection->getChannel()
        );
    }

    public function getSnapshotService(): SnapshotService
    {
        return new SnapshotService($this);
    }

    public function getContainerService(): ContainerService
    {
        return new ContainerService($this);
    }

    public function getTaskService(): TaskService
    {
        return new TaskService($this);
    }
}
