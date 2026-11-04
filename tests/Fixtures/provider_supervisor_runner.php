<?php
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';
use Greenwood\Provider\{ProviderConfig, ProviderIdentity, ProviderJobRegistry, HttpChainClient, Supervisor};
umask(0077);
$config = ProviderConfig::fromFile($argv[1]);
$identity = ProviderIdentity::fromFile($config->keyFile);
$registry = new ProviderJobRegistry($config->dataPath, $config->chainId, $identity->providerId);
$phase = $argv[2]; $marker = $config->dataPath . '/crash-' . $phase;
$checkpoint = static function (string $event) use ($phase, $marker): void {
    if ($event !== $phase || is_file($marker)) return;
    Greenwood\Persistence\AtomicFile::write($marker, $event);
    posix_kill(getmypid(), SIGKILL);
};
$client = new HttpChainClient($config->rpcEndpoint, $config->chainId);
$supervisor = new Supervisor($config, $identity, $registry, $client, $checkpoint);
while (true) { try { $supervisor->tick(); } catch (Throwable $e) { $supervisor->publish(get_class($e)); } usleep(100000); }
