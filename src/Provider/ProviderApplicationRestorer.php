<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\Service\{ContainerService, TaskService};
use Greenwood\Compute\Containerd\ValueObject\PreparedSnapshot;
use Containerd\Services\Snapshots\V1\MountsRequest;

/** Restore previously confirmed running services; never replay uncertain initial deployments. */
final class ProviderApplicationRestorer
{
    public function __construct(
        private readonly ProviderApplicationRegistry $registry,
        private readonly \Closure $inspect,
        private readonly \Closure $resumeTask,
        private readonly ProviderApplicationNetwork $network,
        private readonly \Closure $guard,
    ) {}

    public static function fromClients(ContainerdClientSet $clients, ProviderApplicationRegistry $registry,
        ProviderApplicationNetwork $network, \Closure $guard): self
    {
        $inspector = ProviderApplicationRuntimeInspector::fromClients($clients);
        $tasks = new TaskService($clients);
        $containers = new ContainerService($clients);
        return new self($registry, $inspector->inspect(...),
            static function (array $record, array $view) use ($clients, $tasks, $containers): void {
                $id = $record['container_id'];
                $container = $containers->get($id);
                if ($container->getSnapshotter() !== $clients->getSnapshotter()) {
                    throw new \RuntimeException('Recovery snapshotter mismatch');
                }
                if ($view['task_state'] === 'STOPPED') $tasks->delete($id);
                if (!$view['task_present'] || $view['task_state'] === 'STOPPED') {
                    $request = new MountsRequest(['snapshotter' => $clients->getSnapshotter(), 'key' => $record['view_key']]);
                    [$reply, $status] = $clients->getSnapshots()->Mounts($request,
                        $clients->getConnection()->getMetadata(), ['timeout' => 30_000_000])->wait();
                    if ($status->code !== \Grpc\STATUS_OK) throw new \RuntimeException('Recovery mounts unavailable');
                    $mounts = iterator_to_array($reply->getMounts());
                    if ($mounts === []) throw new \RuntimeException('Recovery mounts empty');
                    $tasks->create($id, $id, new PreparedSnapshot($record['view_key'], $clients->getSnapshotter(), $mounts));
                }
                // A timeout leaves the task intact; the next pass inspects before retrying.
                $tasks->start($id);
            }, $network, $guard);
    }

    public function tick(): array
    {
        $results = [];
        foreach ($this->registry->active() as $candidate) {
            if ($candidate['state'] !== 'RUNNING') continue;
            $id = (int)$candidate['application_id']; $service = $candidate['service'];
            try {
                $results[] = $this->registry->exclusive($id, $service, function () use ($id, $service): array {
                    $record = $this->registry->get($id, $service);
                    if ($record['state'] !== 'RUNNING') return compact('id', 'service') + ['outcome' => 'SKIPPED'];
                    ($this->guard)($id, $service);
                    $binding = $this->network->find($id, $service);
                    if ($binding === null || ($binding['image_digest'] ?? null) !== $record['image_digest']) {
                        throw new \RuntimeException('Recovery requires matching existing network binding');
                    }
                    try {
                        $view = ($this->inspect)($record);
                        $this->assertOwned($record, $view);
                        if ($view['runtime_state'] !== 'RUNNING') {
                            $this->network->invalidate($id, $service);
                            if ($view['task_present'] && !in_array($view['task_state'], ['STOPPED', 'CREATED'], true)) {
                                throw new \RuntimeException('Recovery task state uncertain');
                            }
                            ($this->resumeTask)($record, $view);
                            $view = ($this->inspect)($record);
                            $this->assertOwned($record, $view);
                            if ($view['runtime_state'] !== 'RUNNING') throw new \RuntimeException('Recovery task not running');
                        }
                        if ((int)$record['pid'] !== (int)$view['pid']) {
                            $record = $this->registry->refreshRunningPid($id, $service, (int)$view['pid']);
                        }
                        $health = $this->network->observe($id, $service);
                        if (($health['ready'] ?? false) !== true || ($health['pid'] ?? null) !== $view['pid']) {
                            $this->network->reconcile($record, $this->inspect);
                        }
                    } catch (\Throwable $e) {
                        $this->network->invalidate($id, $service);
                        throw $e;
                    }
                    return compact('id', 'service') + ['outcome' => 'HEALTHY', 'pid' => $view['pid']];
                });
            } catch (\Throwable $e) {
                $results[] = compact('id', 'service') + ['outcome' => 'RETRY_REQUIRED', 'error' => $e->getMessage()];
            }
        }
        return $results;
    }

    private function assertOwned(array $record, array $view): void
    {
        if ($view['runtime_state'] === 'INCONSISTENT' || !$view['container_present']
            || !$view['snapshot_present'] || $view['lease_present'] !== true
            || empty($record['parent_snapshot']) || $view['parent_snapshot'] !== $record['parent_snapshot']) {
            throw new \RuntimeException('Recovery runtime ownership mismatch or missing backing resources');
        }
    }
}
