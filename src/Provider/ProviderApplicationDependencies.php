<?php
declare(strict_types=1);

namespace Greenwood\Provider;

/** Check the installed release before accepting durable application intents. */
final class ProviderApplicationDependencies
{
    public static function assertAvailable(): void
    {
        // Constructing messages also resolves generated GPBMetadata dependencies.
        foreach ([
            \Containerd\Services\Images\V1\GetImageRequest::class,
            \Containerd\Services\Containers\V1\GetContainerRequest::class,
            \Containerd\Services\Tasks\V1\GetRequest::class,
            \Containerd\Services\Snapshots\V1\StatSnapshotRequest::class,
            \Containerd\Services\Leases\V1\ListRequest::class,
            \Containerd\Services\Transfer\V1\TransferRequest::class,
            \Containerd\Types\Transfer\OCIRegistry::class,
            \Containerd\Types\Transfer\RegistryResolver::class,
            \Containerd\Types\Transfer\ImageStore::class,
        ] as $class) {
            try {
                if (!class_exists($class)) throw new \RuntimeException('Missing generated class');
                new $class();
            } catch (\Throwable $error) {
                throw new \RuntimeException('Provider application dependencies incomplete: ' . $class, 0, $error);
            }
        }
    }
}
