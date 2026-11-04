<?php

declare(strict_types=1);

namespace Greenwood\Provider;

use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\OciImageRuntimeConfig;
use Greenwood\Compute\Containerd\RegistryTransferAuth;
use Greenwood\Compute\Containerd\SystemServiceDefinition;
use Greenwood\Compute\Containerd\SystemServiceHandle;
use Greenwood\Compute\Containerd\SystemServiceRuntime;
use Greenwood\Compute\Containerd\Service\ImagePreparationService;
use Greenwood\Compute\Containerd\Service\ImageService;
use Greenwood\Compute\Containerd\Service\OciImageRuntimeConfigResolver;

final class ProviderApplicationOperator
{
    public function __construct(
        private readonly ProviderApplicationRegistry $registry,
        private readonly ProviderApplicationReconciler $reconciler,
        private readonly \Closure $prepareImage,
        private readonly \Closure $resolveRuntimeConfig,
        private readonly \Closure $startRuntime,
        private readonly ?\Closure $inspectRuntime = null,
        private readonly ?ProviderApplicationNetwork $network = null,
        private readonly ?\Closure $mutationGuard = null
    ) {
    }

    public static function fromClients(
        ContainerdClientSet $clients,
        ProviderApplicationRegistry $registry,
        ?ProviderApplicationNetwork $network = null,
        ?\Closure $mutationGuard = null
    ): self {
        $preparation =
            new ImagePreparationService(
                $clients
            );

        $images =
            new ImageService(
                $clients
            );

        $configResolver =
            new OciImageRuntimeConfigResolver(
                $clients
            );

        $runtime =
            new SystemServiceRuntime(
                $clients
            );

        $inspector =
            ProviderApplicationRuntimeInspector::fromClients(
                $clients
            );

        $cleaner =
            ProviderApplicationRuntimeCleaner::fromClients(
                $clients
            );

        $reconciler =
            new ProviderApplicationReconciler(
                $registry,
                $inspector,
                $cleaner
            );

        return new self(
            $registry,
            $reconciler,
            prepareImage:
                static fn (
                    string $imageRef,
                    RegistryTransferAuth $registryAuth
                ) =>
                    $preparation->ensureReady(
                        $imageRef,
                        $registryAuth
                    ),
            resolveRuntimeConfig:
                static function (string $imageRef) use ($configResolver, $images): OciImageRuntimeConfig {
                    $image = $images->get($imageRef);
                    if ($image->getTarget()?->getDigest() !== self::imageDigest($imageRef)) {
                        throw new \RuntimeException('Local image digest mismatch');
                    }
                    return $configResolver->resolve($image);
                },
            startRuntime:
                static fn (
                    SystemServiceDefinition $definition
                ) =>
                    $runtime->start(
                        $definition
                    ),
            inspectRuntime:
                static fn (
                    array $record
                ): array =>
                    $inspector->inspect(
                        $record
                    ),
            network: $network,
            mutationGuard: $mutationGuard
        );
    }

    /** Reserve local capacity only; never prepare images, start tasks or attach networking. */
    public function reserve(int $applicationId, string $service, string $imageRef): array
    {
        $digest = self::imageDigest($imageRef);
        $instance = self::serviceId($applicationId, $service);
        return $this->registry->exclusive($applicationId, $service, function () use ($applicationId, $service, $imageRef, $digest, $instance): array {
            ($this->mutationGuard)?->__invoke($applicationId, $service);
            if ($this->network === null) throw new \RuntimeException('Network reservation unavailable');
            $existing = $this->registry->find($applicationId, $service);
            if ($existing !== null && (($existing['image_ref'] ?? null) !== $imageRef || ($existing['image_digest'] ?? null) !== $digest)) {
                $this->verifyRetiredForReplacement($existing, $digest);
            }
            $config = ($this->resolveRuntimeConfig)($imageRef);
            if (!$config instanceof OciImageRuntimeConfig) throw new \RuntimeException('Local runtime config unavailable');
            $this->network->reserve($applicationId, $service, $config, $digest);
            $binding = $this->network->find($applicationId, $service);
            if (!is_int($binding['ingress_port'] ?? null)) throw new \RuntimeException('Reservation unavailable');
            return ['application_id'=>$applicationId,'service'=>$service,'image_ref'=>$imageRef,
                'image_digest'=>$digest,'instance_id'=>$instance,'ingress_port'=>$binding['ingress_port'],
                'reserved'=>true,'ready'=>false];
        });
    }

    public function start(int $applicationId, string $service, string $imageRef, RegistryTransferAuth $registryAuth): array
    {
        return $this->registry->exclusive($applicationId, $service, function () use ($applicationId, $service, $imageRef, $registryAuth): array {
            ($this->mutationGuard)?->__invoke($applicationId, $service);
            $record = $this->startRuntimeOnly($applicationId, $service, $imageRef, $registryAuth);
            if ($this->network !== null) {
                if ($this->inspectRuntime === null) throw new \LogicException('Networking requires a runtime inspector');
                $this->network->reserve($applicationId, $service, ($this->resolveRuntimeConfig)($imageRef));
                // Outside runtime recovery catch: attachment/health failure can never
                // be converted to success just because the container is RUNNING.
                $record['network'] = $this->network->reconcile($record, $this->inspectRuntime);
            }
            return $record;
        });
    }

    private function startRuntimeOnly(
        int $applicationId,
        string $service,
        string $imageRef,
        RegistryTransferAuth $registryAuth
    ): array {
        $imageDigest =
            self::imageDigest(
                $imageRef
            );

        $serviceId =
            self::serviceId(
                $applicationId,
                $service
            );

        $existing =
            $this->registry->find(
                $applicationId,
                $service
            );

        if (
            $existing !== null
            && in_array(
                $existing['state'],
                [
                    'STARTING',
                    'RUNNING',
                    'STOPPING',
                ],
                true
            )
        ) {
            $this->assertSameIdentity(
                $existing,
                $serviceId,
                $imageRef,
                $imageDigest
            );

            $recovery =
                $this->reconciler->reconcile(
                    $applicationId,
                    $service
                );

            if (
                $recovery['record']['state']
                === 'RUNNING'
            ) {
                return $recovery['record'];
            }

            if (
                !in_array(
                    $recovery['record']['state'],
                    [
                        'FAILED',
                        'STOPPED',
                    ],
                    true
                )
            ) {
                throw new \RuntimeException(
                    'Application start requires recovery'
                );
            }
        }

        if ($existing !== null && ($existing['image_ref'] !== $imageRef || $existing['image_digest'] !== $imageDigest)) {
            $this->verifyRetiredForReplacement($existing, $imageDigest);
        }

        $this->registry->beginStart(
            $applicationId,
            $service,
            $serviceId,
            $imageRef,
            $imageDigest
        );

        try {
            $resolved =
                ($this->prepareImage)(
                    $imageRef,
                    $registryAuth
                );

            if (
                $resolved->getDigest()
                !== $imageDigest
            ) {
                throw new \RuntimeException(
                    'Prepared image digest does not match requested image'
                );
            }

            $runtimeConfig =
                ($this->resolveRuntimeConfig)(
                    $imageRef
                );

            if (
                !$runtimeConfig
                    instanceof OciImageRuntimeConfig
            ) {
                throw new \LogicException(
                    'OCI runtime config resolver returned invalid value'
                );
            }

            $this->network?->reserve($applicationId, $service, $runtimeConfig);

            $definition =
                new SystemServiceDefinition(
                    id: $serviceId,
                    imageRef: $imageRef,
                    command:
                        $runtimeConfig->command,
                    environment:
                        $runtimeConfig->environment,
                    mounts: [],
                    user: '65534:65534',
                    hostNetwork: false,
                    workingDirectory:
                        $runtimeConfig
                            ->workingDirectory
                );

            $handle =
                ($this->startRuntime)(
                    $definition
                );

            if (
                !$handle
                    instanceof SystemServiceHandle
            ) {
                throw new \LogicException(
                    'Application runtime returned invalid handle'
                );
            }

            return $this->registry->markRunning(
                $applicationId,
                $service,
                $handle->containerId,
                $handle->viewKey,
                $handle->parentSnapshot,
                $handle->leaseId,
                $handle->pid
            );
        } catch (\Throwable $error) {
            try {
                $recovery =
                    $this->reconciler->reconcile(
                        $applicationId,
                        $service
                    );

                if (
                    $recovery['record']['state']
                    === 'RUNNING'
                ) {
                    return $recovery['record'];
                }
            } catch (\Throwable) {
            }

            throw $error;
        }
    }

    public function inspect(
        int $applicationId,
        string $service
    ): array {
        $record =
            $this->registry->find(
                $applicationId,
                $service
            );

        if ($record === null) {
            // A missing row alone cannot prove a safe first start. Check the
            // deterministic resource IDs for orphaned runtime ownership.
            if ($this->inspectRuntime === null) {
                throw new \RuntimeException('Application runtime inspection unavailable');
            }
            $id = self::serviceId($applicationId, $service);
            $runtime = ($this->inspectRuntime)([
                'container_id' => $id,
                'view_key' => 'gw-system-view-' . $id,
                'lease_id' => 'gw-system-lease-' . $id,
                'image_ref' => 'unowned-application',
            ]);
            if (($runtime['runtime_state'] ?? null) !== 'ABSENT') {
                throw new \RuntimeException('Unowned application resources require reconciliation');
            }
            $network = $this->network?->observe($applicationId, $service);
            if ($network !== null && ($network['state'] ?? null) !== 'DETACHED') {
                throw new \RuntimeException('Unowned application network requires reconciliation');
            }
            return ['record' => null, 'runtime' => $runtime, 'network' => $network];
        }

        $runtime =
            $this->inspectRuntime !== null
                ? ($this->inspectRuntime)(
                    $record
                )
                : null;

        $result = ['record' => $record, 'runtime' => $runtime];
        if ($this->network !== null) {
            $result['network'] = $this->network->observe($applicationId, $service);
            if ($result['network'] !== null && (($runtime['runtime_state'] ?? null) !== 'RUNNING'
                || ($runtime['pid'] ?? null) !== ($result['network']['pid'] ?? null))) {
                $result['network']['ready'] = false;
            }
        }
        return $result;
    }

    public function stop(int $applicationId, string $service): array
    {
        return $this->registry->exclusive($applicationId, $service, function () use ($applicationId, $service): array {
            ($this->mutationGuard)?->__invoke($applicationId, $service);
            // Revoke network access first. Uncertain cleanup blocks a successful stop.
            $network = $this->network?->cleanup($applicationId, $service);
            $result = $this->stopRuntimeOnly($applicationId, $service);
            if ($this->network !== null) $result['network'] = $network;
            return $result;
        });
    }

    private function stopRuntimeOnly(
        int $applicationId,
        string $service
    ): array {
        $record =
            $this->registry->get(
                $applicationId,
                $service
            );

        if (
            in_array(
                $record['state'],
                [
                    'STOPPED',
                    'FAILED',
                ],
                true
            )
        ) {
            return [
                'record' => $record,
                'runtime' => null,
                'outcome' =>
                    'ALREADY_INACTIVE',
            ];
        }

        $recovery =
            $this->reconciler->reconcile(
                $applicationId,
                $service
            );

        $state =
            $recovery['record']['state'];

        if (
            in_array(
                $state,
                [
                    'STOPPED',
                    'FAILED',
                ],
                true
            )
        ) {
            return $recovery;
        }

        if (
            $state !== 'RUNNING'
            || (
                $recovery['runtime']['runtime_state']
                    ?? null
            ) !== 'RUNNING'
        ) {
            throw new \RuntimeException(
                'Application stop requires recovery'
            );
        }

        $this->registry->markStopping(
            $applicationId,
            $service
        );

        $stopped =
            $this->reconciler->reconcile(
                $applicationId,
                $service
            );

        if (
            $stopped['record']['state']
            !== 'STOPPED'
        ) {
            throw new \RuntimeException(
                'Application stop is not confirmed'
            );
        }

        return $stopped;
    }

    private function verifyRetiredForReplacement(array $record, string $digest): void
    {
        if (!in_array($record['state'] ?? null, ['STOPPED','FAILED'], true) || $this->inspectRuntime === null) {
            throw new \RuntimeException('Existing application identity requires verified retirement');
        }
        $runtime = ($this->inspectRuntime)($record);
        if (($runtime['runtime_state'] ?? null) !== 'ABSENT') throw new \RuntimeException('Previous runtime is not absent');
        foreach (['container_present','task_present','snapshot_present','lease_present'] as $field) {
            if (($runtime[$field] ?? null) !== false) throw new \RuntimeException('Previous runtime cleanup is unverified');
        }
        $network = $this->network?->find((int)$record['application_id'], $record['service']);
        if ($network !== null && !(($network['state'] ?? null) === 'DETACHED'
            || (($network['state'] ?? null) === 'RESERVED' && ($network['reservation_image_digest'] ?? null) === $digest))) {
            throw new \RuntimeException('Previous network is not retired or reservation conflicts');
        }
    }

    private static function imageDigest(
        string $imageRef
    ): string {
        if (
            str_contains(
                $imageRef,
                "\0"
            )
            || !preg_match(
                '/@(?<digest>sha256:[a-f0-9]{64})$/D',
                $imageRef,
                $match
            )
        ) {
            throw new \InvalidArgumentException(
                'Application image must be digest-qualified'
            );
        }

        return $match['digest'];
    }

    private static function serviceId(
        int $applicationId,
        string $service
    ): string {
        if (
            $applicationId < 1
            || $applicationId > 0xffffffff
        ) {
            throw new \InvalidArgumentException(
                'Invalid application ID'
            );
        }

        if (
            !preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D',
                $service
            )
        ) {
            throw new \InvalidArgumentException(
                'Invalid application service'
            );
        }

        return 'app-'
            . $applicationId
            . '-'
            . substr(
                hash(
                    'sha256',
                    $service
                ),
                0,
                48
            );
    }

    private function assertSameIdentity(
        array $record,
        string $serviceId,
        string $imageRef,
        string $imageDigest
    ): void {
        if (
            $record['service_id']
                !== $serviceId
            || $record['image_ref']
                !== $imageRef
            || $record['image_digest']
                !== $imageDigest
        ) {
            throw new \RuntimeException(
                'Application service already owns another runtime'
            );
        }
    }
}
