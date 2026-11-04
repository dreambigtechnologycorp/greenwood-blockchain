<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Compute\Codec\ComputeReceiptV2Codec;
use Greenwood\Persistence\AtomicFile;
use Greenwood\Transaction\Envelope;

/** Durable orchestration only: no containerd clients, OCI execution or worker signing. */
final class Supervisor
{
    private ProcessManager $processes;
    private bool $ready = false;
    public function __construct(private readonly ProviderConfig $config, private readonly ProviderIdentity $identity,
        private readonly ProviderJobRegistry $registry, private readonly ChainClient $chain, private readonly ?\Closure $checkpoint = null)
    { $this->processes = new ProcessManager($config, $identity->providerId, $registry, $checkpoint); }

    public function tick(): void
    {
        $this->ready = false; $this->processes->reap();
        $runtimeAvailable = $this->processes->prerequisitesAvailable();
        $page = $this->chain->assignments($this->identity->providerId);
        $this->registry->observeHeight($page['finalized_height']);
        if (!$this->registry->isDraining()) do {
            foreach ($page['request_ids'] as $hex) {
                $view = AssignmentView::decode($this->chain->request(hex2bin($hex)));
                if ($view->assignment !== null && $view->current($this->identity->providerId, $view->assignment->generation)) $this->registry->discover($view->request->getId(), $view->assignment->generation);
            }
            $next = $page['next'];
            if ($next !== null) $page = $this->chain->assignments($this->identity->providerId, hex2bin($next));
        } while ($next !== null);
        $active = count(array_filter($this->registry->jobs(), fn ($j) => in_array($j['state'], ['STARTING', 'RUNNING'], true)));
        $usedCpu = 0; $usedMemory = 0; $usedPids = 0;
        foreach ($this->registry->jobs() as $job) if (in_array($job['state'], ['STARTING', 'RUNNING'], true)) {
            $request = AssignmentView::decode($this->chain->request($job['request_id']))->request ?? throw new \RuntimeException('Active request missing');
            $usedCpu = \Greenwood\Compute\Runtime\OutputCommitment::addBytes($usedCpu, $request->getCpuLimitMillis());
            $usedMemory = \Greenwood\Compute\Runtime\OutputCommitment::addBytes($usedMemory, $request->getMemoryLimitBytes());
            $usedPids = \Greenwood\Compute\Runtime\OutputCommitment::addBytes($usedPids, $request->getPidLimit());
        }
        $mayLaunchNext = true;
        foreach ($this->registry->jobs() as $job) {
            $id = $job['request_id']; $gen = $job['generation']; $state = $job['state'];
            if (in_array($state, ['SETTLED', 'STALE', 'FAILED_LOCAL'], true)) continue;
            $view = AssignmentView::decode($this->chain->request($id));
            if (in_array($state, ['SUBMITTING', 'SUBMITTED'], true)) { $this->settle($job, $view); continue; }
            if ($state === 'RECEIPT_READY') continue;
            if (in_array($state, ['STARTING', 'RUNNING'], true)) {
                $runtime = $job['execution_id'];
                if ($this->processes->busy($runtime)) {
                    $now = hrtime(true); $started = $job['worker_started_ns'] ?? $now;
                    if ($now < $started || intdiv($now - $started, 1000000) > $this->config->controlTimeoutMs + ($view->request?->getExecutionTimeoutMs() ?? 0)) {
                        $this->registry->noteFailure($id, $gen, 'Provider control deadline exceeded');
                        $this->processes->stop($runtime, $job['worker_pid']);
                    }
                    continue;
                }
                try { $facts = $this->processes->facts($runtime); }
                catch (\Throwable) { $this->registry->noteFailure($id, $gen, 'Malformed worker facts'); $this->processes->quarantine($runtime); continue; }
                if ($facts === null) {
                    if (($job['worker_launches'] >= 3 || $job['last_error'] !== null) && $this->processes->recordPrestartFailure($runtime)) { $this->fail($job, 'Bounded worker startup failure'); continue; }
                    if (!$runtimeAvailable) continue;
                    $pid = $this->processes->launch($runtime, $id, $gen);
                    if ($state === 'STARTING') $this->registry->transition($id, $gen, $state, 'RUNNING', workerPid: $pid);
                    continue;
                }
                if (!$view->current($this->identity->providerId, $gen) || isset($facts['stale'])) { $this->processes->archive($runtime); $this->registry->transition($id, $gen, $state, 'STALE'); continue; }
                if (isset($facts['local_error']) || $job['last_error'] !== null) { $this->fail($job, 'Unrecoverable interrupted execution or rejected facts'); continue; }
                if ($state === 'STARTING') { $this->registry->transition($id, $gen, $state, 'RUNNING'); $job = $this->registry->get($id, $gen); }
                try {
                    $unsigned = ReceiptBuilder::build($view, $this->identity, $runtime, $facts, $this->config->dataPath . '/output', $job);
                    $this->checkpoint?->__invoke('receipt_built');
                    $signed = $this->identity->signReceipt($unsigned);
                    $this->checkpoint?->__invoke('signed');
                    $this->registry->transition($id, $gen, 'RUNNING', 'RECEIPT_READY', receipt: $signed->toSignedBytes());
                    $this->checkpoint?->__invoke('receipt_persisted');
                } catch (\InvalidArgumentException) { $this->registry->noteFailure($id, $gen, 'Rejected worker facts'); $this->processes->quarantine($runtime); }
                continue;
            }
            if (!$view->current($this->identity->providerId, $gen)) { $this->registry->transition($id, $gen, $state, 'STALE'); continue; }
            if ($state === 'DISCOVERED') {
                try { if (!$this->config->admits($view->request)) throw new \InvalidArgumentException('Policy'); ProviderWorkload::resolve($view->request); }
                catch (\InvalidArgumentException) { $this->fail($job, 'Unsupported workload or local limits'); continue; }
                $this->registry->transition($id, $gen, $state, 'QUEUED'); $state = 'QUEUED';
                $this->checkpoint?->__invoke('queued');
            }
            if ($state === 'QUEUED' && $mayLaunchNext && $runtimeAvailable && !$this->registry->isDraining() && $active < $this->config->maxConcurrent
                && $view->request->getCpuLimitMillis() <= $this->config->maxCpuMillis - $usedCpu
                && $view->request->getMemoryLimitBytes() <= $this->config->maxMemoryBytes - $usedMemory
                && $view->request->getPidLimit() <= $this->config->maxPids - $usedPids) {
                $runtime = 'native-' . bin2hex(random_bytes(8));
                $this->registry->transition($id, $gen, 'QUEUED', 'STARTING', executionId: $runtime);
                $this->checkpoint?->__invoke('claimed');
                $pid = $this->processes->launch($runtime, $id, $gen);
                $this->checkpoint?->__invoke('spawned');
                $this->registry->transition($id, $gen, 'STARTING', 'RUNNING', workerPid: $pid); $active++;
                $usedCpu += $view->request->getCpuLimitMillis(); $usedMemory += $view->request->getMemoryLimitBytes(); $usedPids += $view->request->getPidLimit();
                $state = 'RUNNING';
            }
            if ($state === 'QUEUED') $mayLaunchNext = false;
        }
        $jobs = $this->registry->jobs();
        if (!array_filter($jobs, fn ($j) => in_array($j['state'], ['SUBMITTING', 'SUBMITTED'], true))) foreach ($jobs as $job) if ($job['state'] === 'RECEIPT_READY') {
            $view = AssignmentView::decode($this->chain->request($job['request_id']));
            if (!$view->current($this->identity->providerId, $job['generation'])) { $this->processes->archive($job['execution_id']); $this->registry->transition($job['request_id'], $job['generation'], 'RECEIPT_READY', 'STALE'); continue; }
            $receipt = ComputeReceiptV2Codec::decode($job['receipt']);
            $tx = $this->identity->receiptTransaction($this->config->chainId, $this->chain->nonce($this->identity->accountId), $receipt);
            $this->registry->transition($job['request_id'], $job['generation'], 'RECEIPT_READY', 'SUBMITTING', transaction: $tx->getCanonicalBytes());
            $this->checkpoint?->__invoke('outbox_persisted');
            $this->settle($this->registry->get($job['request_id'], $job['generation']), $view); break;
        }
        $this->ready = $this->processes->prerequisitesAvailable() && !array_filter($this->registry->jobs(), fn ($j) => in_array($j['state'], ['STARTING', 'RUNNING', 'SUBMITTING', 'SUBMITTED'], true));
        $this->publish();
    }
    private function settle(array $job, AssignmentView $view): void
    {
        $id = $job['request_id']; $gen = $job['generation']; $state = $job['state'];
        if ($view->receipt !== null) { $this->processes->archive($job['execution_id']); $this->registry->transition($id, $gen, $state, hash_equals($job['receipt'], $view->receipt->toSignedBytes()) ? 'SETTLED' : 'STALE'); return; }
        if (!$view->current($this->identity->providerId, $gen)) { $this->processes->archive($job['execution_id']); $this->registry->transition($id, $gen, $state, 'STALE'); return; }
        $tx = Envelope::decode($job['transaction_bytes']);
        // External nonce advancement blocks this lane; never rebase/re-sign an uncertain transaction.
        if ($this->chain->nonce($this->identity->accountId) !== $tx->getNonce()) return;
        try { $this->checkpoint?->__invoke('before_submit'); $this->chain->submit($tx); $this->checkpoint?->__invoke('after_submit'); if ($state === 'SUBMITTING') $this->registry->transition($id, $gen, $state, 'SUBMITTED'); }
        catch (\Throwable) { /* keep exact bytes and uncertain state */ }
    }
    private function fail(array $job, string $error): void
    {
        if ($job['execution_id'] !== null) $this->processes->archive($job['execution_id']);
        $this->registry->transition($job['request_id'], $job['generation'], $job['state'], 'FAILED_LOCAL', error: $error);
    }
    public function publish(?string $error = null): void
    {
        $jobs = array_map(fn ($j) => ['request_id' => bin2hex($j['request_id']), 'generation' => $j['generation'], 'state' => $j['state'], 'runtime_id' => $j['execution_id'], 'worker_pid' => $j['worker_pid'], 'error' => $j['last_error']], $this->registry->jobs());
        AtomicFile::write($this->config->dataPath . '/status.json', json_encode(['healthy' => true, 'ready' => $error === null && $this->ready, 'pid' => getmypid(), 'observed_at' => time(), 'height' => $this->registry->observedHeight(), 'error' => $error, 'jobs' => $jobs], JSON_THROW_ON_ERROR));
    }
}
