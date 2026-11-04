<?php
declare(strict_types=1);
// Test-only HTTP relay: L1 accepts a receipt, but its acknowledgement is lost.
$body = file_get_contents('php://input');
$context = stream_context_create(['http' => ['method' => $_SERVER['REQUEST_METHOD'], 'ignore_errors' => true,
    'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n", 'content' => $body, 'timeout' => 3]]);
$reply = file_get_contents(getenv('GW_PROXY_UPSTREAM') . $_SERVER['REQUEST_URI'], false, $context);
$marker = getenv('GW_PROXY_MARKER');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (json_decode($reply, true)['accepted'] ?? false) && !is_file($marker)) {
    file_put_contents($marker, base64_encode($body));
    http_response_code(503); $reply = '{}';
}
header('Content-Type: application/json'); header('Content-Length: ' . strlen($reply)); echo $reply;
