<?php
/**
 * Resource-enforcement worker for ResourceEnforcementTest.
 *
 * Runs ONE workload (mode selected by argv[1]) under explicit
 * ResourceLimits in its own process; prints the ExecutionResult as JSON
 * on stdout when the workload reaches a terminal state. The parent test
 * inspects the workload's cgroup WHILE this worker is running.
 *
 * Usage: php resource_worker.php <sleep|memtouch|memhog|forkloop|cpuburn>
 */

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\ContainerdConnection;
use Greenwood\Compute\Containerd\ContainerdExecutor;
use Greenwood\Compute\Containerd\ResourceLimits;

$mode = $argv[1] ?? '';
$image = 'docker.io/library/alpine:latest';

// 256 MiB memory, 100 PIDs, 0.5 CPU hard quota (50000/100000).
$limits = ResourceLimits::create(256 * 1024 * 1024, 100, 50_000, 100_000);

$executor = new ContainerdExecutor(new ContainerdClientSet(new ContainerdConnection()));

try {
    switch ($mode) {
        case 'sleep':
            $r = $executor->run($image, "/bin/ash -c 'sleep 30'", terminateAfterMs: 12_000, resources: $limits);
            break;

        case 'memtouch':
            // Grow a string to ~100MiB final (transient doubling peak
            // ~200MiB, UNDER the 256MiB ceiling), then idle.
            $cmd = "/bin/ash -c 'awk \"BEGIN{s=\\\"0123456789\\\"; while(length(s)<100000000){s=s s}; system(\\\"sleep 15\\\")}\"'";
            $r = $executor->run($image, $cmd, terminateAfterMs: 12_000, resources: $limits);
            break;

        case 'memhog':
            // Unbounded growth: must be killed by the cgroup OOM killer.
            $cmd = "/bin/ash -c 'awk \"BEGIN{s=\\\"0123456789\\\"; while(1){s=s s}}\"'";
            $r = $executor->run($image, $cmd, resources: $limits);
            break;

        case 'forkloop':
            // Attempt 300 background processes against pids.limit=100.
            // busybox ash dies on the first failed fork, so an outer
            // supervisor loop keeps the init process alive (and keeps
            // hitting the PID ceiling) until Greenwood terminates it.
            $cmd = "/bin/ash -c 'while true; do /bin/ash -c \"i=0; while [ \\\$i -lt 300 ]; do sleep 60 & i=\\\$((i+1)); done\"; sleep 1; done'";
            $r = $executor->run($image, $cmd, terminateAfterMs: 10_000, resources: $limits);
            break;

        case 'cpuburn':
            // Pure CPU spin against a 0.5-CPU hard quota.
            $r = $executor->run($image, "/bin/ash -c 'while :; do :; done'", timeoutMs: 10_000, resources: $limits);
            break;

        default:
            echo json_encode(['error' => "unknown mode: {$mode}"]);
            exit(2);
    }

    echo json_encode([
        'status' => $r->getStatus()->getValue(),
        'exitCode' => $r->getExitCode(),
        'stdoutLen' => strlen($r->getStdout()),
        'stderrLen' => strlen($r->getStderr()),
        'stderrHead' => substr($r->getStderr(), 0, 4000),
        'stdoutHead' => substr($r->getStdout(), 0, 4000),
        'errorMessage' => $r->getErrorMessage(),
    ]);
    exit(0);
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit(1);
}
