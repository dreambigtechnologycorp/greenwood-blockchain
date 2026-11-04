<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Compute\Containerd\{ContainerdConnection, ContainerdClientSet, ContainerdExecutor, ExecutionLaunch, ExecutionRegistry, ResourceLimits};
use Greenwood\Compute\Containerd\Service\{ExecutionRegistryService, LeaseService};
use Greenwood\Contracts\ContainerdRpcException;
use Greenwood\Persistence\AtomicFile;

/** New PHP process entry point. No signing material is loaded here. */
final class ProviderWorker
{
    public static function run(string $jobPath, ?\Closure $checkpoint = null): void
    {
        $job = WorkerJob::read($jobPath);
        $directory = dirname($jobPath) . '/run';
        $lock = fopen($directory . '/worker.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) return;
        try {
            if (is_file($directory . '/facts.json')) return;
            $providerId = hex2bin($job['provider_id']);
            if ($providerId === false || strlen($providerId) !== 32) throw new \RuntimeException('Invalid worker provider identity');
            $connection = new ContainerdConnection('greenwood-provider-' . substr($job['provider_id'], 0, 16), [], $job['containerd_socket']);
            $clients = (new ContainerdClientSet($connection))->setSnapshotter($job['snapshotter']); // Created only in this fresh process; no snapshotter fallback.
            if (is_file($directory . '/started.json')) {
                self::recover($job, $directory, $clients);
                $pending = $directory . '/pending-facts.json';
                AtomicFile::write($directory . '/facts.json', is_file($pending) ? file_get_contents($pending, false, null, 0, 3000001)
                    : json_encode(['local_error' => 'Interrupted execution reconciled; complete terminal facts unavailable'], JSON_THROW_ON_ERROR));
                return;
            }
            $client = new HttpChainClient($job['rpc_endpoint'], $job['chain_id']);
            $view = AssignmentView::decode($client->request(hex2bin($job['request_id'])));
            if (!$view->current($providerId, $job['generation'])) {
                AtomicFile::write($directory . '/facts.json', '{"stale":true}');
                return;
            }
            $workload = ProviderWorkload::resolve($view->request);
            AtomicFile::write($directory . '/started.json', json_encode(['runtime_id' => $job['runtime_id'], 'pid' => getmypid()], JSON_THROW_ON_ERROR));
            $limits = ResourceLimits::create($view->request->getMemoryLimitBytes(), $view->request->getPidLimit(), $view->request->getCpuLimitMillis() * 100, 100000);
            $launch = new ExecutionLaunch($job['runtime_id'], $directory, $view->request->getId(), $job['generation'], $view->request->getOutputRetentionBytes(), $checkpoint);
            $result = (new ContainerdExecutor($clients))->run($workload->image, $workload->input,
                timeoutMs: $view->request->getExecutionTimeoutMs(), resources: $limits, launch: $launch);
            $checkpoint?->__invoke('terminal_before_facts');
            $facts = json_encode(WorkerFacts::fromResult($result), JSON_THROW_ON_ERROR);
            AtomicFile::write($directory . '/pending-facts.json', $facts);
            self::recover($job, $directory, $clients);
            AtomicFile::write($directory . '/facts.json', $facts);
        } catch (\Throwable $error) {
            // No raw input, credentials, or runtime error string enters worker logs.
            AtomicFile::write($directory . '/retry.json', json_encode(['retry_recovery' => true, 'error_class' => get_class($error), 'failure_site' => basename($error->getFile()) . ':' . $error->getLine()], JSON_THROW_ON_ERROR));
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function recover(array $job, string $directory, ContainerdClientSet $clients): void
    {
        $registry = new ExecutionRegistry($directory);
        $entry = $registry->findExecution($job['runtime_id']);
        // Query failures must propagate. No empty-result inference during outages.
        (new ExecutionRegistryService($directory, $clients))->reconcile();
        if ($entry === null) return; // Persisted launch intent but no runtime side effects began.
        $tasks = $clients->getTaskService();
        try {
            $process = $tasks->get($entry['container_id'])->getProcess();
            if ($process->getStatus() !== \Containerd\V1\Types\Status::STOPPED) {
                $tasks->kill($entry['container_id'], 9, '', true);
                $tasks->wait($entry['container_id'], '', 5000000);
            }
        } catch (\Throwable $error) { if ($error->getCode() !== ContainerdRpcException::RPC_NOT_FOUND) throw $error; }
        foreach ([fn () => $tasks->delete($entry['container_id']),
            fn () => $clients->getContainerService()->delete($entry['container_id']),
            fn () => $clients->getSnapshotService()->remove($entry['snapshot_key']),
            fn () => (new LeaseService($clients))->delete('gw-lease-' . $job['runtime_id'])] as $remove) {
            try { $remove(); }
            catch (\Throwable $error) { if ($error->getCode() !== ContainerdRpcException::RPC_NOT_FOUND) throw $error; }
        }
        foreach (glob($directory . '/*.fifo_gw_*') ?: [] as $fifo) {
            if (filetype($fifo) === 'fifo' && !unlink($fifo)) throw new \RuntimeException('Cannot remove recovered FIFO');
        }
        $registry->updateRuntimeState($job['runtime_id'], 'STOPPED');
        foreach ([fn () => $tasks->get($entry['container_id']), fn () => $clients->getContainerService()->get($entry['container_id']),
            fn () => $clients->getSnapshotService()->stat($entry['snapshot_key'])] as $query) {
            try { $query(); }
            catch (\Throwable $error) { if ($error->getCode() === ContainerdRpcException::RPC_NOT_FOUND) continue; throw $error; }
            throw new \RuntimeException('Owned runtime residue remains');
        }
        [$leases, $status] = $clients->getLeases()->List(new \Containerd\Services\Leases\V1\ListRequest(), $clients->getConnection()->getMetadata(), ['timeout' => 30000000])->wait();
        if ($status->code !== \Grpc\STATUS_OK) throw new \RuntimeException('Cannot confirm lease cleanup');
        foreach ($leases->getLeases() as $lease) if ($lease->getId() === 'gw-lease-' . $job['runtime_id']) throw new \RuntimeException('Owned lease remains');
    }
}
