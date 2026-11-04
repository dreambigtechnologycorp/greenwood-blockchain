<?php
declare(strict_types=1);
// Test-only entrypoint: inspect the actual process boundary without gRPC.
$job = json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
$keyPath = dirname(dirname(dirname($argv[1]))) . '/key';
$inheritedKey = false;
foreach (glob('/proc/self/fd/*') ?: [] as $fd) if (@readlink($fd) === $keyPath) $inheritedKey = true;
file_put_contents(dirname($argv[1]) . '/run/facts.json', json_encode([
    'pid' => getmypid(), 'uid' => posix_geteuid(),
    'inherited_secret' => getenv('GREENWOOD_AUDIT_SECRET') !== false,
    'job_fields' => array_keys($job),
    'key_readable' => @fopen($keyPath, 'rb') !== false,
    'inherited_key_fd' => $inheritedKey,
], JSON_THROW_ON_ERROR));
