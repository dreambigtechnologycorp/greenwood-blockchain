<?php
/**
 * DIAGNOSTIC concurrency worker (Pass 4A blocker investigation).
 *
 * Replicates ContainerdExecutor::run() step-by-step with structured JSONL
 * logging so the reproduced rootfs/overlay race can be localized.
 * Production code is NOT modified; this mirrors its semantics exactly.
 *
 * Usage: php diag_worker.php <logFile> <mode> [imageRef]
 *   mode: transfer -> call ImagePreparationService::ensureReady() (always Transfers)
 *         noxfer   -> assume image prewarmed; skip ensureReady, resolve only
 */
declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\ContainerdConnection;
use Greenwood\Compute\Containerd\ExecutionIo;
use Greenwood\Compute\Containerd\OciSpecBuilder;
use Greenwood\Compute\Containerd\ResolvedImage;
use Greenwood\Compute\Containerd\Service\ContainerService;
use Greenwood\Compute\Containerd\Service\ImagePreparationService;
use Greenwood\Compute\Containerd\Service\ImageRootfsResolver;
use Greenwood\Compute\Containerd\Service\ImageService;
use Greenwood\Compute\Containerd\Service\SnapshotService;
use Greenwood\Compute\Containerd\Service\TaskService;

$logFile = $argv[1] ?? null;
$mode = $argv[2] ?? 'transfer';
$imageRef = $argv[3] ?? 'docker.io/library/alpine:latest';

$pid = getmypid();
$executionId = 'native-' . bin2hex(random_bytes(8));

$log = function (string $event, array $extra = []) use ($logFile, $pid, $executionId): void {
    $line = json_encode([
        'ts' => microtime(true),
        'pid' => $pid,
        'executionId' => $executionId,
        'event' => $event,
    ] + $extra) . "\n";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

$clients = new ContainerdClientSet(new ContainerdConnection());
$imageService = new ImageService($clients);
$snapshotService = new SnapshotService($clients);
$containerService = new ContainerService($clients);
$taskService = new TaskService($clients);
$rootfsResolver = new ImageRootfsResolver($clients);
$preparationService = new ImagePreparationService($clients);

$snapshotId = $snapshotService->generateSnapshotId($executionId);
$containerId = $containerService->generateContainerId($executionId);
$io = new ExecutionIo(sys_get_temp_dir());

$log('begin', [
    'snapshotKey' => $snapshotId,
    'containerId' => $containerId,
    'imageRef' => $imageRef,
    'mode' => $mode,
]);

$reaped = false;
$fatal = null;
$imageRootfsParent = null;

try {
    if ($mode === 'transfer') {
        $log('ensureReady_begin');
        $preparationService->ensureReady($imageRef);
        $log('ensureReady_complete');
    }

    $image = $imageService->get($imageRef);
    $log('image_get', ['digest' => $image->getTarget()?->getDigest()]);

    $imageRootfsParent = $rootfsResolver->resolve($image);
    $log('rootfs_resolved', ['rootfsParent' => $imageRootfsParent]);

    try {
        $baseInfo = $snapshotService->stat($imageRootfsParent);
        $log('base_snapshot_stat', ['kind' => $baseInfo->getKind()]);
    } catch (\Throwable $e) {
        $log('base_snapshot_stat_FAILED', ['error' => $e->getMessage()]);
    }

    $log('snapshot_prepare_begin', ['snapshotKey' => $snapshotId, 'parent' => $imageRootfsParent]);
    $prepared = $snapshotService->prepare($snapshotId, $imageRootfsParent);
    $mounts = [];
    foreach ($prepared->getMounts() as $m) {
        $mounts[] = ['type' => $m->getType(), 'source' => $m->getSource(), 'options' => iterator_to_array($m->getOptions())];
    }
    $log('snapshot_prepare_complete', ['snapshotKey' => $snapshotId, 'mounts' => $mounts]);

    $spec = OciSpecBuilder::forShellCommand('rootfs', "/bin/ash -c 'printf \"ok\\n\"'")->build();

    $log('container_create_begin', ['containerId' => $containerId]);
    $container = $containerService->buildContainer($containerId, new ResolvedImage(
        $imageRef, $image->getName(), $image->getTarget()->getDigest(),
        $image->getTarget()->getMediaType(), ''
    ), $snapshotId, $spec);
    $containerService->create($container);
    $log('container_create_complete', ['containerId' => $containerId]);

    $io->create();
    $io->openReaders();

    $log('task_create_begin', ['containerId' => $containerId]);
    $taskService->create($containerId, '', $prepared, '', $io->getStdoutPath(), $io->getStderrPath());
    $log('task_create_complete', ['containerId' => $containerId]);

    $log('task_start_begin', ['containerId' => $containerId]);
    $taskService->start($containerId, '');
    $log('task_start_complete', ['containerId' => $containerId]);

    $exitCode = $taskService->wait($containerId, '', 60_000_000)->getExitStatus();
    $reaped = true;
    $io->drainAvailable();
    $log('reaped', ['exitCode' => $exitCode]);
} catch (\Throwable $e) {
    $fatal = $e->getMessage();
    $log('FAILURE', ['error' => $fatal, 'class' => get_class($e)]);
    try { $snapshotService->stat($snapshotId); $log('post_failure_own_snapshot', ['present' => true]); }
    catch (\Throwable) { $log('post_failure_own_snapshot', ['present' => false]); }
    if ($imageRootfsParent !== null) {
        try { $snapshotService->stat($imageRootfsParent); $log('post_failure_base_snapshot', ['present' => true]); }
        catch (\Throwable) { $log('post_failure_base_snapshot', ['present' => false]); }
    }
} finally {
    // Same dependency-ordered cleanup as ContainerdExecutor, but logged.
    if (!$reaped) {
        try { $taskService->kill($containerId, 9, '', true); } catch (\Throwable) {}
        try { $taskService->wait($containerId, '', 5_000_000); } catch (\Throwable) {}
    }
    try { $taskService->delete($containerId, ''); $log('task_delete'); } catch (\Throwable $e) { $log('task_delete_failed', ['error' => $e->getMessage()]); }
    try { $containerService->delete($containerId); $log('container_delete'); } catch (\Throwable $e) { $log('container_delete_failed', ['error' => $e->getMessage()]); }
    try { $snapshotService->remove($snapshotId); $log('snapshot_remove', ['snapshotKey' => $snapshotId]); } catch (\Throwable $e) { $log('snapshot_remove_failed', ['error' => $e->getMessage()]); }
    $io->cleanup();
    $log('cleanup_complete');
}

echo json_encode([
    'executionId' => $executionId,
    'pid' => $pid,
    'containerId' => $containerId,
    'snapshotKey' => $snapshotId,
    'fatal' => $fatal,
]);
exit($fatal === null ? 0 : 1);
