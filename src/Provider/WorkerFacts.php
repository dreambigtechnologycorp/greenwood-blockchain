<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Compute\Runtime\ExecutionResult;

/** Local IPC only. Binary inspection data and hashes have explicit encodings. */
final class WorkerFacts
{
    public static function fromResult(ExecutionResult $result): array
    {
        $usage = $result->getResourceUsage();
        if ($result->getOutputDigest() === null || $usage?->getCpuTimeMs() === null || $usage->getMemoryPeakBytes() === null || $result->getDurationMs() === null) throw new \RuntimeException('Complete execution accounting unavailable');
        return ['version' => 1, 'runtime_id' => $result->getExecutionId(), 'image_digest' => $result->getResolvedImageDigest(),
            'status' => $result->getStatus()->getValue(), 'exit_code' => $result->isError() ? null : $result->getExitCode(),
            'stdout' => base64_encode($result->getStdout()), 'stderr' => base64_encode($result->getStderr()),
            'stdout_length' => $result->getStdoutBytesObserved(), 'stderr_length' => $result->getStderrBytesObserved(),
            'stdout_hash' => bin2hex($result->getFullStdoutHash()), 'stderr_hash' => bin2hex($result->getFullStderrHash()),
            'output_digest' => bin2hex($result->getOutputDigest()),
            'stdout_truncated' => $result->isStdoutTruncated(), 'stderr_truncated' => $result->isStderrTruncated(),
            'cpu_time_ms' => $usage->getCpuTimeMs(),
            'memory_peak_bytes' => $usage->getMemoryPeakBytes(), 'duration_ms' => $result->getDurationMs()];
    }
}
