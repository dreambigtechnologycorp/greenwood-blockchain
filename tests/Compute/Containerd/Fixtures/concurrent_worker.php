<?php
/**
 * Concurrency worker for RuntimeHardeningTest.
 *
 * Runs ONE workload through ContainerdExecutor in its own process and
 * prints the result as JSON on stdout. Separate PHP processes are used
 * instead of pcntl_fork because the grpc extension's shared channel state
 * is not fork-safe.
 *
 * Usage: php concurrent_worker.php <workerIndex>
 */

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\ContainerdConnection;
use Greenwood\Compute\Containerd\ContainerdExecutor;

$i = (int) ($argv[1] ?? -1);

try {
    $executor = new ContainerdExecutor(new ContainerdClientSet(new ContainerdConnection()));
    $result = $executor->run(
        'docker.io/library/alpine:latest',
        "/bin/ash -c 'printf \"worker{$i}\\n\"'"
    );
    echo json_encode([
        'executionId' => $result->getExecutionId(),
        'status' => $result->getStatus()->getValue(),
        'exitCode' => $result->getExitCode(),
        'stdout' => $result->getStdout(),
        'stderr' => $result->getStderr(),
        'errorMessage' => $result->getErrorMessage(),
    ]);
    exit(0);
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit(1);
}
