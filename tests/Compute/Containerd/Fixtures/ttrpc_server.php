<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

[$script, $path, $scenario] = $argv;
$server = stream_socket_server('unix://' . $path, $errno, $error);
if ($server === false) { throw new RuntimeException($error); }
echo "ready\n";
flush();
$ids = [];
for ($call = 0; $call < ($scenario === 'repeat' ? 2 : 1); ++$call) {
    $socket = stream_socket_accept($server, 5);
    if ($socket === false) { throw new RuntimeException('No client'); }
    $read = static function (int $length) use ($socket): string {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($socket, min(4096, $length - strlen($bytes)));
            if ($chunk === false || $chunk === '') { throw new RuntimeException('EOF'); }
            $bytes .= $chunk;
        }
        return $bytes;
    };
    $header = unpack('Nlength/Nid/Ctype/Cflags', $read(10));
    if ($header['type'] !== 1 || $header['flags'] !== 0 || $header['id'] !== 1 + 2 * $call) {
        throw new RuntimeException('Wrong request header');
    }
    $request = new Ttrpc\Request();
    $request->mergeFromString($read($header['length']));
    if ($request->getService() !== 'fixture.Service' || $request->getMethod() !== 'Echo'
        || $request->getTimeoutNano() <= 0
        || count($request->getMetadata()) !== 1
        || $request->getMetadata()[0]->getKey() !== 'containerd-namespace-ttrpc'
        || $request->getMetadata()[0]->getValue() !== 'greenwood') {
        throw new RuntimeException('Wrong generated Request contents');
    }
    $status = new Status();
    if ($scenario === 'status') {
        $full = (new Google\Rpc\Status())->setCode(7)->setMessage('fixture denied');
        $status->mergeFromString($full->serializeToString());
    }
    $response = (new Ttrpc\Response())->setStatus($status)->setPayload($request->getPayload());
    if ($scenario === 'no-status') { $response->clearStatus(); }
    if ($scenario === 'bad-payload') { $response->setPayload("\x0a\x05x"); }
    $bytes = $scenario === 'bad-protobuf' ? "\x0a\x05x" : $response->serializeToString();
    $frame = pack('NNCC', $scenario === 'oversized' ? (4 << 20) + 1 : strlen($bytes),
        $scenario === 'wrong-stream' ? 99 : $header['id'],
        $scenario === 'wrong-type' ? 3 : 2,
        $scenario === 'wrong-flags' ? 1 : 0) . $bytes;
    if ($scenario === 'timeout') { usleep(300000); }
    elseif ($scenario === 'short-header') { fwrite($socket, substr($frame, 0, 5)); }
    elseif ($scenario === 'short-body') { fwrite($socket, substr($frame, 0, -1)); }
    else {
        $size = $scenario === 'fragmented' ? 1 : 4096;
        for ($offset = 0; $offset < strlen($frame);) {
            $written = @fwrite($socket, substr($frame, $offset, $size));
            if (!$written) { break; }
            $offset += $written;
            if ($scenario === 'fragmented') { usleep(100); }
        }
    }
    fclose($socket);
}
fclose($server);
unlink($path);
