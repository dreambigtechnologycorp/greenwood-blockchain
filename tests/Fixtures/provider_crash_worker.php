<?php
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';
Greenwood\Provider\ProviderWorker::run($argv[1], static function (string $stage) use ($argv): void {
    if ($stage !== WORKER_CRASH_STAGE) return;
    Greenwood\Persistence\AtomicFile::write(dirname($argv[1]) . '/run/injected-crash.json', json_encode(['stage' => $stage], JSON_THROW_ON_ERROR));
    posix_kill(getmypid(), SIGKILL);
});
