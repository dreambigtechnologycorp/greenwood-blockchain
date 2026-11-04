<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Compute\Protocol\{ComputeReceipt, ComputeReceiptV2, ComputeStatus};
use Greenwood\Compute\Runtime\ExecutionStatus;
use Greenwood\Persistence\AtomicFile;

final class ReceiptBuilder
{
    public static function build(AssignmentView $view, ProviderIdentity $identity, string $runtimeId, array $facts, string $outputDirectory, array $job): ComputeReceiptV2
    {
        $request = $view->request ?? throw new \InvalidArgumentException('Missing request');
        $assignment = $view->assignment ?? throw new \InvalidArgumentException('Missing assignment');
        if (($job['request_id'] ?? null) !== $request->getId() || ($job['generation'] ?? null) !== $assignment->generation
            || ($job['provider_id'] ?? null) !== $identity->providerId || ($job['state'] ?? null) !== 'RUNNING'
            || ($job['execution_id'] ?? null) !== $runtimeId || ($job['protocol_execution_id'] ?? null) !== ProviderContent::executionId($runtimeId)) throw new \InvalidArgumentException('Receipt requires matching durable running job');
        if ($assignment->requestId !== $request->getId() || !$view->current($identity->providerId, $assignment->generation)) throw new \InvalidArgumentException('Assignment is not current for this request/provider');
        if (($facts['version'] ?? null) !== 1 || ($facts['runtime_id'] ?? null) !== $runtimeId || $assignment->providerId !== $identity->providerId) throw new \InvalidArgumentException('Wrong worker facts identity');
        $status = ExecutionStatus::fromString($facts['status'] ?? '');
        $exit = $facts['exit_code'] ?? null;
        if (($exit !== null && (!is_int($exit) || $exit < 0 || $exit > 0xffffffff))
            || ($status->isCompleted() && $exit !== 0)
            || ($status->isFailed() && ($exit === null || $exit === 0))
            || ($status->isError() && $exit !== null)) throw new \InvalidArgumentException('Invalid worker status/exit-code relationship');
        if (!$status->isError() && ($facts['image_digest'] ?? '') !== 'sha256:' . bin2hex($request->getWorkloadDigest())) throw new \InvalidArgumentException('Worker executed wrong image');
        foreach (['stdout_length', 'stderr_length', 'cpu_time_ms', 'memory_peak_bytes', 'duration_ms'] as $field) {
            if (!is_int($facts[$field] ?? null) || $facts[$field] < 0) throw new \InvalidArgumentException('Invalid worker accounting');
        }
        $hashes = [];
        foreach (['stdout', 'stderr'] as $stream) {
            $text = $facts[$stream] ?? null; $hex = $facts[$stream . '_hash'] ?? null;
            if (!is_string($text) || !is_string($hex) || !preg_match('/^[0-9a-f]{64}$/D', $hex)) throw new \InvalidArgumentException('Invalid worker output encoding');
            $retained = base64_decode($text, true);
            if ($retained === false || base64_encode($retained) !== $text || strlen($retained) > $request->getOutputRetentionBytes()
                || strlen($retained) > $facts[$stream . '_length'] || !is_bool($facts[$stream . '_truncated'] ?? null)
                || $facts[$stream . '_truncated'] !== ($facts[$stream . '_length'] > strlen($retained))) throw new \InvalidArgumentException('Invalid retained output bounds');
            $hashes[$stream] = hex2bin($hex);
            if (!$facts[$stream . '_truncated'] && hash('sha256', $retained, true) !== $hashes[$stream]) throw new \InvalidArgumentException('Worker output content mismatch');
        }
        $digest = ProviderContent::outputDigest($facts['stdout_length'], $hashes['stdout'], $facts['stderr_length'], $hashes['stderr']);
        if (bin2hex($digest) !== ($facts['output_digest'] ?? null)) throw new \InvalidArgumentException('Worker output commitment mismatch');
        $observed = \Greenwood\Compute\Runtime\OutputCommitment::addBytes($facts['stdout_length'], $facts['stderr_length']);
        $blob = json_encode(['stdout' => $facts['stdout'], 'stderr' => $facts['stderr'], 'stdout_hash' => $facts['stdout_hash'],
            'stderr_hash' => $facts['stderr_hash'], 'stdout_length' => $facts['stdout_length'], 'stderr_length' => $facts['stderr_length'],
            'output_digest' => bin2hex($digest)], JSON_THROW_ON_ERROR);
        $blobId = hash('sha256', $blob);
        AtomicFile::write($outputDirectory . '/' . $blobId . '.json', $blob);
        $body = new ComputeReceipt($request->getId(), $identity->providerId, ProviderContent::executionId($runtimeId),
            $request->getWorkloadDigest(), $request->getInputDigest(), $digest,
            'greenwood-output://' . bin2hex($identity->providerId) . '/' . $blobId,
            ComputeStatus::fromExecutionStatus($status), $facts['exit_code'] ?? null, $facts['cpu_time_ms'], $facts['memory_peak_bytes'],
            $facts['duration_ms'], null, $observed, $facts['stdout_truncated'], $facts['stderr_truncated']);
        return new ComputeReceiptV2($body, $assignment->generation);
    }
    public static function readOutput(string $directory, string $blobId): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $blobId)) throw new \InvalidArgumentException('Invalid output content ID');
        $blob = file_get_contents($directory . '/' . $blobId . '.json', false, null, 0, 3000001);
        if ($blob === false || strlen($blob) > 3000000 || hash('sha256', $blob) !== $blobId) throw new \RuntimeException('Stored output has been altered or exceeds bounds');
        return $blob;
    }
}
