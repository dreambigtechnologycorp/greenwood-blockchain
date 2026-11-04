<?php
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';
use Greenwood\Provider\{ProviderConfig, ProviderJobRegistry, ProcessManager};
$directory = $argv[1]; $label = $argv[2];
file_put_contents($directory . '/' . $label . '.ready', '1');
$deadline = microtime(true) + 5;
while (!is_file($directory . '/go') && microtime(true) < $deadline) usleep(1000);
try {
    $registry = new ProviderJobRegistry($directory, 'race', str_repeat('p', 32));
    $registry->transition(str_repeat('r', 32), 1, 'QUEUED', 'STARTING', executionId: 'native-0000000000000004');
    $config = new ProviderConfig('race', 'http://127.0.0.1:1', $directory . '/key', $directory, workerEntrypoint: __DIR__ . '/provider_process_probe.php');
    $manager = new ProcessManager($config, str_repeat('p', 32), $registry);
    $pid = $manager->launch('native-0000000000000004', str_repeat('r', 32), 1);
    file_put_contents($directory . '/' . $label . '.result', json_encode(['claimed' => true, 'pid' => $pid]));
    usleep(500000); // Hold ownership while the other independent process attempts its claim.
    $manager->reap();
} catch (Throwable $e) { file_put_contents($directory . '/' . $label . '.result', json_encode(['claimed' => false, 'error' => get_class($e)])); }
