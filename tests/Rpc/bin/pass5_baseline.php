#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Pass 5 host-independent RPC baseline: boots a real four-validator network
 * of greenwoodd processes on loopback, seeds a chain (transfers + full compute
 * lifecycle), then measures:
 *
 *   1. Per-endpoint latency baseline (p50/p95/p99/max/errors)
 *   2. Scan-based paths (block-by-hash, tx-by-hash)
 *   3. Concurrent reads during normal consensus activity + bounded memory
 *   4. Over-the-wire rate limiting (below -> accepted, above -> 429, recovery)
 *
 * Diagnostic tooling only; no production code. Usage:
 *   php tests/Rpc/bin/pass5_baseline.php [--samples=200] [--keep]
 */
require __DIR__ . '/../../../vendor/autoload.php';

use Greenwood\Compute\Protocol\{ComputeAssign, ComputeReceiptV2, ProviderCapability};
use Greenwood\Consensus\Network\Transport;
use Greenwood\Crypto\{Ed25519KeyPair, ProtocolHash};
use Greenwood\Tests\Fixtures\ComputeFixture as F;
use Greenwood\Transaction\{Envelope, Transfer};

$options = getopt('', ['samples:', 'keep', 'help']);
if (isset($options['help'])) { fwrite(STDERR, "Usage: pass5_baseline.php [--samples=N] [--keep]\n"); exit(0); }
$SAMPLES = max(20, (int)($options['samples'] ?? 200));
$KEEP = isset($options['keep']);

$root = sys_get_temp_dir() . '/gw-p5-baseline-' . bin2hex(random_bytes(6));
mkdir($root, 0755, true);

$report = ['environment' => [
    'php' => PHP_VERSION,
    'os' => PHP_OS_FAMILY,
    'arch' => php_uname('m'),
    'samples_per_endpoint' => $SAMPLES,
]];

// ---- 1. Genesis + keys ------------------------------------------------------
$accountKey = F::key();
$accountAddress = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $accountKey->getPublicKey());
$validators = [];
$ports = [];
$basePort = 18300 + random_int(0, 50) * 10;
foreach (['A', 'B', 'C', 'D'] as $i => $id) {
    // Validator A uses the fixture key so the fixture provider is in the
    // genesis trusted provider pool (eligibility = genesis validator keys).
    $key = $id === 'A' ? F::key() : Ed25519KeyPair::generate();
    $port = $basePort + $i;
    $ports[$id] = $port;
    $validators[$id] = ['key' => $key, 'port' => $port];
    // greenwoodd key file format: public(32) || private(64), per Ed25519KeyPair::fromBytes.
    file_put_contents("$root/key-$id.bin", $key->getPublicKey() . $key->getPrivateKey());
}
$genesis = [
    'chain_id' => 'pass5-baseline',
    'validators' => [],
    'accounts' => [
        'funded' => ['public_key' => bin2hex($accountKey->getPublicKey()), 'balance' => 100000000],
    ],
];
foreach ($validators as $id => $v) {
    $genesis['validators'][$id] = [
        'public_key' => bin2hex($v['key']->getPublicKey()),
        'power' => 1,
        'endpoint' => 'http://127.0.0.1:' . $v['port'],
    ];
}
file_put_contents("$root/genesis.json", json_encode($genesis, JSON_PRETTY_PRINT));

// ---- 2. Start four greenwoodd processes -------------------------------------
$procs = [];
$logs = [];
foreach ($validators as $id => $v) {
    $dir = "$root/node-$id";
    mkdir($dir, 0700, true);
    $cmd = [
        PHP_BINARY, __DIR__ . '/../../../bin/greenwoodd',
        '--genesis', "$root/genesis.json",
        '--data-dir', $dir,
        '--node', $id,
        '--key', "$root/key-$id.bin",
        '--endpoint', 'http://127.0.0.1:' . $v['port'],
    ];
    $logs[$id] = fopen("$root/node-$id.log", 'ab');
    $procs[$id] = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => $logs[$id], 2 => $logs[$id]], $pipes);
    if (!is_resource($procs[$id])) fail("Failed to start node $id");
}

$cleanup = function () use ($procs, $root, $KEEP) {
    foreach ($procs as $p) { @proc_terminate($p); }
    foreach ($procs as $p) { @proc_close($p); }
    if (!$KEEP) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($root);
    } else {
        fwrite(STDERR, "Kept $root\n");
    }
};
register_shutdown_function($cleanup);

function fail(string $msg): never { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }

/** Single HTTP request; returns [status, body, seconds]. */
function http(string $method, string $url, ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
        // The node transport requires explicit Content-Length framing on every request.
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Connection: close', 'Content-Length: ' . strlen($body ?? '')],
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $start = microtime(true);
    $result = curl_exec($ch);
    $elapsed = microtime(true) - $start;
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$status, (string)$result, $elapsed];
}

// ---- 3. Wait for all nodes to serve -----------------------------------------
$deadline = time() + 30;
foreach ($ports as $id => $port) {
    $ok = false;
    while (time() < $deadline) {
        [$status] = http('GET', "http://127.0.0.1:$port/health");
        if ($status === 200) { $ok = true; break; }
        usleep(200_000);
    }
    if (!$ok) fail("Node $id did not become healthy (see $root/node-$id.log)");
}
$report['network'] = ['validators' => 4, 'all_healthy' => true];

// ---- 4. Seed the chain: compute lifecycle + padding transfers ---------------
$submit = function (Envelope $tx) use ($ports): string {
    $body = Transport::envelope('transaction', $tx->getCanonicalBytes());
    [$status, $respBody] = http('POST', 'http://127.0.0.1:' . $ports['A'] . '/v1/transactions', $body);
    if ($status !== 202 && $status !== 200) fail("Submit failed ($status) for nonce {$tx->getNonce()} type {$tx->getType()}: $respBody");
    $data = json_decode($respBody, true);
    return $data['tx_hash'] ?? $data['hash'] ?? fail("No hash in submit response: $respBody");
};
$awaitFinal = function (string $hash) use ($ports): void {
    $deadline = microtime(true) + 30;
    while (microtime(true) < $deadline) {
        [$status, $body] = http('GET', 'http://127.0.0.1:' . $ports['B'] . "/v1/transactions/$hash/status");
        if ($status === 200 && (json_decode($body, true)['status'] ?? '') === 'FINALIZED') return;
        usleep(150_000);
    }
    fail("Transaction $hash not finalized within 30s");
};
$makeTx = function (int $type, string $payload, int $nonce) use ($accountKey, $accountAddress): Envelope {
    $tx = new Envelope(1, 'pass5-baseline', $accountAddress, $nonce, $type, $payload, '', $accountKey->getPublicKey());
    $tx->setSignature($accountKey->sign($tx->getSigningDigest()));
    return Envelope::decode($tx->getCanonicalBytes());
};

// Fresh transfer built against the currently finalized account nonce.
$base = 'http://127.0.0.1:' . $ports['A'];
$freshTransfer = function () use ($base, $accountKey, $accountAddress): Envelope {
    [$s, $body] = http('GET', "$base/v1/accounts/" . bin2hex($accountAddress) . '/nonce');
    $n = json_decode($body, true)['nonce'] ?? fail("Nonce query failed: $body");
    $recipient = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', random_bytes(32));
    return (new Transfer($recipient, 1))->createEnvelope('pass5-baseline', $accountAddress, $n, $accountKey);
};

$nonce = 0;
$request = F::request(['requestNonce' => 0, 'deadlineHeight' => 500]);
$requestId = bin2hex($request->getId());
$requestTxHash = $submit($makeTx(7, $request->toBytes(), $nonce++));
$awaitFinal($requestTxHash);

foreach ([1, 2, 3] as $gen) {
    $assign = new ComputeAssign($request->getId(), F::providerId()->toBytes(), $accountKey->getPublicKey(), $gen, 500);
    $awaitFinal($submit($makeTx(8, $assign->toBytes(), $nonce++)));
}
$receipt = (new ComputeReceiptV2(F::receipt(['requestId' => $request->getId()]), 3))->sign(F::key());
$awaitFinal($submit($makeTx(9, $receipt->toSignedBytes(), $nonce++)));

$cap = new ProviderCapability(F::providerId()->toBytes(), $accountKey->getPublicKey(), ProviderCapability::STATUS_ACTIVE, 1, ['linux/arm64/v8'], 4000, 1 << 30, 128, 4);
$awaitFinal($submit($makeTx(10, $cap->toBytes(), $nonce++)));

// Padding transfers to give the scan-based paths a non-trivial chain.
$padHashes = [];
for ($i = 0; $i < 40; $i++) {
    $tx = $freshTransfer();
    $padHashes[] = $submit($tx);
    // Admission checks the account nonce against finalized state; wait for
    // finality before submitting the next transfer.
    $awaitFinal(end($padHashes));
}

[$s, $statusBody] = http('GET', 'http://127.0.0.1:' . $ports['A'] . '/v1/chain/status');
$chainStatus = json_decode($statusBody, true);
$tipHeight = $chainStatus['finalized_height'] ?? $chainStatus['height'] ?? null;
if (!is_int($tipHeight) || $tipHeight < 10) fail("Chain did not grow: $statusBody");
[$s, $tipBlockBody] = http('GET', 'http://127.0.0.1:' . $ports['A'] . "/v1/blocks/$tipHeight");
$tipBlockHash = json_decode($tipBlockBody, true)['block_hash'] ?? fail("No block hash: $tipBlockBody");
$report['seed'] = ['finalized_height' => $tipHeight, 'transactions' => $nonce];



// ---- 5. Per-endpoint latency baseline ----------------------------------------
$endpoints = [
    '/v1/chain/status' => "$base/v1/chain/status",
    '/v1/accounts/{id}' => "$base/v1/accounts/" . bin2hex($accountAddress),
    '/v1/accounts/{id}/nonce' => "$base/v1/accounts/" . bin2hex($accountAddress) . '/nonce',
    '/v1/transactions/{hash}' => "$base/v1/transactions/$requestTxHash",
    '/v1/transactions/{hash}/status' => "$base/v1/transactions/$requestTxHash/status",
    '/v1/compute/requests/{id}' => "$base/v1/compute/requests/$requestId",
    '/v1/compute/providers' => "$base/v1/compute/providers",
];
$scanEndpoints = [
    'block-by-hash' => "$base/v1/blocks/hash/$tipBlockHash",
    'tx-by-hash' => "$base/v1/transactions/" . $padHashes[17],
];

$measure = function (string $url, int $samples): array {
    $lat = [];
    $errors = 0;
    for ($i = 0; $i < $samples; $i++) {
        [$status, , $elapsed] = http('GET', $url);
        if ($status !== 200) { $errors++; continue; }
        $lat[] = $elapsed * 1000.0; // ms
    }
    sort($lat);
    $n = count($lat);
    if ($n === 0) return ['requests' => $samples, 'errors' => $errors];
    $pct = fn(float $p): float => $lat[min($n - 1, (int)ceil($p * $n) - 1)];
    return [
        'requests' => $samples,
        'p50_ms' => round($pct(0.50), 3),
        'p95_ms' => round($pct(0.95), 3),
        'p99_ms' => $n >= 100 ? round($pct(0.99), 3) : null,
        'max_ms' => round($lat[$n - 1], 3),
        'errors' => $errors,
    ];
};

$report['performance'] = [];
foreach ($endpoints as $name => $url) {
    $report['performance'][$name] = $measure($url, $SAMPLES);
}
$report['performance_scan'] = [];
foreach ($scanEndpoints as $name => $url) {
    $report['performance_scan'][$name] = $measure($url, $SAMPLES);
}

// ---- 6. Concurrent reads during normal activity ------------------------------
$rss = function () use ($ports): array {
    $out = [];
    foreach ($ports as $id => $port) {
        [$s, $body] = http('GET', "http://127.0.0.1:$port/v1/chain/status");
        $out[$id] = $s === 200 ? (json_decode($body, true)['finalized_height'] ?? null) : null;
    }
    return $out;
};
$nodeRss = function (): array {
    $out = [];
    exec("ps -ax -o rss=,command= | grep '[g]reenwoodd'", $lines);
    foreach ($lines as $line) $out[] = (int)$line;
    return $out;
};

$heightBefore = $rss();
$rssBefore = $nodeRss();

// 16 concurrent readers hammering mixed read endpoints for ~6 seconds while
// padding transfers continue to be submitted below the rate limit.
$readUrls = array_values($endpoints);
$multi = curl_multi_init();
$running = 0;
$handles = [];
$stats = ['requests' => 0, '2xx' => 0, '4xx' => 0, '5xx' => 0, 'transport_errors' => 0, 'corrupt' => 0];
$addHandle = function () use ($multi, $readUrls, &$handles) {
    $url = $readUrls[array_rand($readUrls)];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Connection: close', 'Content-Length: 0']]);
    curl_multi_add_handle($multi, $ch);
    $handles[(int)$ch] = $ch;
};
for ($i = 0; $i < 16; $i++) $addHandle();

$stopAt = microtime(true) + 6.0;
$submitted = 0;
$nextSubmit = microtime(true) + 1.0;
while (microtime(true) < $stopAt || $running > 0) {
    while (microtime(true) < $stopAt && count($handles) < 16) $addHandle();
    // Submit a transfer roughly once per second (well below the 20-burst/5-per-second limit).
    if (microtime(true) >= $nextSubmit && microtime(true) < $stopAt) {
        $tx = $freshTransfer();
        [$st] = http('POST', "$base/v1/transactions", Transport::envelope('transaction', $tx->getCanonicalBytes()));
        if ($st === 200 || $st === 202) $submitted++;
        $nextSubmit = microtime(true) + 1.0;
    }
    curl_multi_exec($multi, $running);
    while ($info = curl_multi_info_read($multi)) {
        $ch = $info['handle'];
        $stats['requests']++;
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $body = curl_multi_getcontent($ch);
        if ($info['result'] !== CURLE_OK) $stats['transport_errors']++;
        elseif ($code >= 500) $stats['5xx']++;
        elseif ($code >= 400) $stats['4xx']++;
        else {
            $stats['2xx']++;
            if (json_decode((string)$body, true) === null && trim((string)$body) !== 'null') $stats['corrupt']++;
        }
        curl_multi_remove_handle($multi, $ch);
        unset($handles[(int)$ch]);
        if (microtime(true) < $stopAt) $addHandle();
    }
    curl_multi_select($multi, 0.05);
}
curl_multi_close($multi);

$heightAfter = $rss();
$rssAfter = $nodeRss();
$report['concurrency'] = $stats + [
    'parallel_readers' => 16,
    'submissions_below_limit_accepted' => $submitted,
    'height_before' => $heightBefore,
    'height_after' => $heightAfter,
    'consensus_progressed' => min(array_filter($heightAfter, fn($h) => $h !== null)) > min(array_filter($heightBefore, fn($h) => $h !== null)),
    'node_rss_kb_before' => $rssBefore,
    'node_rss_kb_after' => $rssAfter,
];


// ---- 7. Over-the-wire rate limiting -------------------------------------------
// Below threshold: 5 sequential submissions (capacity 20, refill 5/s) accepted.
$below = 0;
for ($i = 0; $i < 5; $i++) {
    $tx = $freshTransfer();
    [$st] = http('POST', "$base/v1/transactions", Transport::envelope('transaction', $tx->getCanonicalBytes()));
    if ($st === 200 || $st === 202) $below++;
    usleep(250_000);
}

// Above threshold: rapid burst of 40 must exhaust the bucket and yield 429s.
$burst = ['accepted' => 0, 'rate_limited' => 0, 'other' => 0, 'retry_after_seen' => false, 'stable_error' => false];
for ($i = 0; $i < 40; $i++) {
    $tx = $freshTransfer();
    $ch = curl_init("$base/v1/transactions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => Transport::envelope('transaction', $tx->getCanonicalBytes()),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Connection: close'],
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    if ($st === 429) {
        $burst['rate_limited']++;
        if (stripos($headers, 'Retry-After:') !== false) $burst['retry_after_seen'] = true;
        $decoded = json_decode($body, true);
        if (($decoded['error']['code'] ?? $decoded['code'] ?? '') === 'RATE_LIMITED') $burst['stable_error'] = true;
    } elseif ($st === 200 || $st === 202) {
        $burst['accepted']++;
    } else {
        $burst['other']++;
    }
}

// Recovery: after idle refill, submissions are accepted again.
sleep(5);
$recovered = 0;
for ($i = 0; $i < 3; $i++) {
    $tx = $freshTransfer();
    [$st] = http('POST', "$base/v1/transactions", Transport::envelope('transaction', $tx->getCanonicalBytes()));
    if ($st === 200 || $st === 202) $recovered++;
    usleep(300_000);
}
$report['rate_limiting'] = [
    'config' => 'capacity=20 refill=5/s (transport-level, POST /v1/transactions only)',
    'below_threshold_accepted' => $below,
    'burst' => $burst,
    'recovered_after_idle' => $recovered,
    'note' => 'per-client isolation is covered by tests/Rpc/RateLimitTest.php (loopback peers share one IP over the wire)',
];

// ---- 8. Final health + report --------------------------------------------------
$finalHeights = $rss();
$report['final'] = ['heights' => $finalHeights];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

