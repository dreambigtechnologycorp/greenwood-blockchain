<?php
declare(strict_types=1);

namespace Greenwood\Tests\Compute\Containerd;

use Google\Protobuf\StringValue;
use Greenwood\Compute\Containerd\Ttrpc\TtrpcClient;
use Greenwood\Compute\Containerd\Ttrpc\TtrpcException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TtrpcClientTest extends TestCase
{
    #[DataProvider('cases')]
    public function testTransport(string $scenario, ?string $failure): void
    {
        $path = sys_get_temp_dir() . '/gw-ttrpc-' . bin2hex(random_bytes(6)) . '.sock';
        $process = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/ttrpc_server.php', $path, $scenario],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            stream_set_timeout($pipes[1], 5);
            self::assertSame("ready\n", fgets($pipes[1]), 'Synthetic server must start');
            $client = new TtrpcClient($path);
            $value = $scenario === 'large' ? str_repeat('x', 2_000_000) : 'greenwood';
            $payload = (new StringValue())->setValue($value);
            try {
                for ($i = 0; $i < ($scenario === 'repeat' ? 2 : 1); ++$i) {
                    $result = $client->unary('fixture.Service', 'Echo', $payload, StringValue::class,
                        ['containerd-namespace-ttrpc' => 'greenwood'], $scenario === 'timeout' ? 0.05 : 5);
                    self::assertSame($value, $result->getValue());
                }
                self::assertNull($failure, 'Expected transport rejection');
            } catch (TtrpcException $e) {
                self::assertNotNull($failure, $e->getMessage());
                self::assertStringContainsString($failure, $e->getMessage());
                if ($scenario === 'status') { self::assertSame(7, $e->getCode()); }
            }
            self::assertSame('', stream_get_contents($pipes[2]));
        } finally {
            foreach ($pipes as $pipe) { fclose($pipe); }
            $exit = proc_close($process);
            if (file_exists($path)) { unlink($path); }
        }
        self::assertSame(0, $exit, 'Synthetic server failed');
    }

    public static function cases(): iterable
    {
        foreach (['fragmented', 'large', 'repeat'] as $case) { yield $case => [$case, null]; }
        foreach (['wrong-stream', 'wrong-type', 'wrong-flags'] as $case) {
            yield $case => [$case, 'Invalid TTRPC response'];
        }
        yield 'oversized' => ['oversized', 'frame limit'];
        yield 'short-header' => ['short-header', 'EOF'];
        yield 'short-body' => ['short-body', 'EOF'];
        yield 'timeout' => ['timeout', 'deadline exceeded'];
        yield 'status' => ['status', 'fixture denied'];
        yield 'no-status' => ['no-status', 'Missing TTRPC response status'];
        yield 'bad-protobuf' => ['bad-protobuf', 'protobuf response'];
        yield 'bad-payload' => ['bad-payload', 'protobuf response'];
    }

    public function testOversizedRequestFailsBeforeConnecting(): void
    {
        $this->expectException(TtrpcException::class);
        $this->expectExceptionMessage('Request exceeds');
        (new TtrpcClient('/nonexistent.sock'))->unary('fixture.Service', 'Echo',
            (new StringValue())->setValue(str_repeat('x', TtrpcClient::MAX_FRAME_LENGTH)), StringValue::class);
    }
}
