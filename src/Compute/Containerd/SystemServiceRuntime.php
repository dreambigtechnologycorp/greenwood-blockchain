<?php

declare(strict_types=1);

namespace Greenwood\Compute\Containerd;

use Greenwood\Compute\Containerd\Service\ContainerService;
use Greenwood\Compute\Containerd\Service\ImagePreparationService;
use Greenwood\Compute\Containerd\Service\ImageRootfsResolver;
use Greenwood\Compute\Containerd\Service\ImageService;
use Greenwood\Compute\Containerd\Service\LeaseService;
use Greenwood\Compute\Containerd\Service\SnapshotService;
use Greenwood\Compute\Containerd\Service\TaskService;
use Greenwood\Contracts\ContainerdException;

final class SystemServiceRuntime
{
    private const SIGTERM = 15;
    private const SIGKILL = 9;

    private const TERM_WAIT_MICROS = 2_000_000;
    private const KILL_WAIT_MICROS = 5_000_000;

    private ImagePreparationService $preparationService;
    private ImageService $imageService;
    private ImageRootfsResolver $rootfsResolver;
    private SnapshotService $snapshotService;
    private LeaseService $leaseService;
    private ContainerService $containerService;
    private TaskService $taskService;

    public function __construct(
        ContainerdClientSet $clients,
        ?ImagePreparationService $preparationService = null,
        ?ImageService $imageService = null,
        ?ImageRootfsResolver $rootfsResolver = null,
        ?SnapshotService $snapshotService = null,
        ?LeaseService $leaseService = null,
        ?ContainerService $containerService = null,
        ?TaskService $taskService = null
    ) {
        $this->preparationService =
            $preparationService
            ?? new ImagePreparationService($clients);

        $this->imageService =
            $imageService
            ?? new ImageService($clients);

        $this->rootfsResolver =
            $rootfsResolver
            ?? new ImageRootfsResolver($clients);

        $this->snapshotService =
            $snapshotService
            ?? new SnapshotService($clients);

        $this->leaseService =
            $leaseService
            ?? new LeaseService($clients);

        $this->containerService =
            $containerService
            ?? new ContainerService($clients);

        $this->taskService =
            $taskService
            ?? new TaskService($clients);
    }

    public function start(
        SystemServiceDefinition $definition
    ): SystemServiceHandle {
        $containerId = $definition->containerId();
        $viewKey = $definition->viewKey();
        $leaseId = $definition->leaseId();

        $leaseCreated = false;
        $viewCreated = false;
        $containerCreated = false;
        $taskCreated = false;

        try {
            $resolvedImage =
                $this->preparationService->ensureReady(
                    $definition->imageRef
                );

            $image =
                $this->imageService->get(
                    $definition->imageRef
                );

            $this->leaseService->create($leaseId);
            $leaseCreated = true;

            $parentSnapshot =
                $this->rootfsResolver->resolve($image);

            if ($parentSnapshot === '') {
                throw new ContainerdException(
                    'System service image has no committed rootfs parent',
                    ContainerdException::CODE_NOT_FOUND
                );
            }

            $prepared =
                $this->snapshotService->view(
                    $viewKey,
                    $parentSnapshot,
                    $leaseId
                );

            $viewCreated = true;

            // containerd 2.2 may return only the EROFS mount. Its mount manager
            // consumes that mount, so the shim needs a final read-only bind.
            // 2.3 already supplies this; preserve all complete mount sequences.
            $mounts = $prepared->getMounts();
            if (count($mounts) === 1 && $mounts[0]->getType() === 'erofs') {
                $bind = new \Containerd\Types\Mount();
                $bind->setType('format/bind');
                $bind->setSource('{{ mount 0 }}');
                $bind->setOptions(['ro', 'rbind']);
                $mounts[] = $bind;
                $prepared = new \Greenwood\Compute\Containerd\ValueObject\PreparedSnapshot(
                    $prepared->getKey(), $prepared->getSnapshotter(), $mounts
                );
            }

            $builder =
                (new OciSpecBuilder('rootfs'))
                    ->setCommand(
                        $definition->command
                    )
                    ->setEnvironment(
                        $definition->environmentList()
                    )
                    ->setWorkingDirectory(
                        $definition->workingDirectory
                    )
                    ->setUser(
                        $definition->user
                    )
                    ->setHostNetwork(
                        $definition->hostNetwork
                    );

            foreach ($definition->mounts as $mount) {
                $builder->addMount($mount);
            }

            $container =
                $this->containerService->buildContainer(
                    $containerId,
                    $resolvedImage,
                    $viewKey,
                    $builder->build()
                );

            $this->containerService->create(
                $container
            );

            $containerCreated = true;

            $createResponse =
                $this->taskService->create(
                    $containerId,
                    '',
                    $prepared
                );

            $taskCreated = true;

            $this->taskService->start(
                $containerId,
                ''
            );

            return new SystemServiceHandle(
                serviceId: $definition->id,
                imageReference:
                    $resolvedImage->getFullReference(),
                imageDigest:
                    $resolvedImage->getDigest(),
                containerId: $containerId,
                viewKey: $viewKey,
                parentSnapshot: $parentSnapshot,
                leaseId: $leaseId,
                pid: (int) $createResponse->getPid()
            );
        } catch (\Throwable $error) {
            $cleanupErrors =
                $this->cleanupFailedStart(
                    containerId: $containerId,
                    viewKey: $viewKey,
                    leaseId: $leaseId,
                    taskCreated: $taskCreated,
                    containerCreated: $containerCreated,
                    viewCreated: $viewCreated,
                    leaseCreated: $leaseCreated
                );

            if ($cleanupErrors !== []) {
                throw new ContainerdException(
                    'System service start failed: '
                    . $error->getMessage()
                    . '; cleanup failed: '
                    . implode(
                        ' | ',
                        $cleanupErrors
                    ),
                    (int) $error->getCode(),
                    $error
                );
            }

            throw $error;
        }
    }

    public function stop(
        SystemServiceHandle $handle,
        int $termWaitMicros = self::TERM_WAIT_MICROS,
        int $killWaitMicros = self::KILL_WAIT_MICROS
    ): void {
        if (
            $termWaitMicros <= 0
            || $killWaitMicros <= 0
        ) {
            throw new \InvalidArgumentException(
                'System service wait bounds must be positive'
            );
        }

        $reaped = false;

        try {
            $this->taskService->kill(
                $handle->containerId,
                self::SIGTERM,
                '',
                true
            );

            try {
                $this->taskService->wait(
                    $handle->containerId,
                    '',
                    $termWaitMicros
                );

                $reaped = true;
            } catch (\Throwable) {
            }
        } catch (\Throwable) {
        }

        if (!$reaped) {
            try {
                $this->taskService->kill(
                    $handle->containerId,
                    self::SIGKILL,
                    '',
                    true
                );
            } catch (\Throwable) {
            }

            try {
                $this->taskService->wait(
                    $handle->containerId,
                    '',
                    $killWaitMicros
                );

                $reaped = true;
            } catch (\Throwable) {
            }
        }

        if (!$reaped) {
            throw new ContainerdException(
                'System service task exit is unconfirmed; preserving task and backing resources'
            );
        }

        try {
            // Each dependency must be removed before releasing its backing resource.
            $this->taskService->delete($handle->containerId, '');
            $this->containerService->delete($handle->containerId);
            $this->snapshotService->remove($handle->viewKey);
            $this->leaseService->delete($handle->leaseId);
        } catch (\Throwable $error) {
            throw new ContainerdException(
                'System service stop cleanup failed; remaining backing resources preserved: '
                . $error->getMessage(),
                (int) $error->getCode(),
                $error
            );
        }
    }

    private function cleanupFailedStart(
        string $containerId,
        string $viewKey,
        string $leaseId,
        bool $taskCreated,
        bool $containerCreated,
        bool $viewCreated,
        bool $leaseCreated
    ): array {
        $errors = [];

        $operations = [];

        if ($taskCreated) {
            $operations[] =
                fn () =>
                    $this->taskService->delete(
                        $containerId,
                        ''
                    );
        }

        if ($containerCreated) {
            $operations[] =
                fn () =>
                    $this->containerService->delete(
                        $containerId
                    );
        }

        if ($viewCreated) {
            $operations[] =
                fn () =>
                    $this->snapshotService->remove(
                        $viewKey
                    );
        }

        if ($leaseCreated) {
            $operations[] =
                fn () =>
                    $this->leaseService->delete(
                        $leaseId
                    );
        }

        foreach ($operations as $operation) {
            try {
                $operation();
            } catch (\Throwable $error) {
                $errors[] = $error->getMessage();
                // A failed deletion leaves ownership/liveness unresolved.
                // Never release resources beneath that dependency.
                break;
            }
        }

        return $errors;
    }
}
