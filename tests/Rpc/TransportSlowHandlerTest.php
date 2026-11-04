<?php

declare(strict_types=1);

namespace Greenwood\Tests\Rpc;

use Greenwood\Consensus\Network\Transport;
use PHPUnit\Framework\TestCase;

final class TransportSlowHandlerTest extends TestCase
{
    public function testSlowServerHandlerStillDeliversResponse(): void
    {
        $base =
            '/tmp/gw-transport-'
            . bin2hex(random_bytes(6));

        mkdir($base, 0700, true);

        $socketPath = $base . '/operator.sock';
        $readyPath = $base . '/ready';

        $pid = pcntl_fork();

        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            try {
                $transport =
                    new Transport(
                        'unix://' . $socketPath,
                        static function (): array {
                            usleep(2_200_000);

                            return [
                                'ok' => true,
                            ];
                        }
                    );

                touch($readyPath);

                while (true) {
                    $transport->poll();
                    usleep(1_000);
                }
            } catch (\Throwable $error) {
                fwrite(
                    STDERR,
                    get_class($error)
                    . ': '
                    . $error->getMessage()
                    . PHP_EOL
                );

                exit(1);
            }
        }

        try {
            $deadline =
                microtime(true) + 5;

            while (
                !is_file($readyPath)
                && microtime(true) < $deadline
            ) {
                usleep(10_000);
            }

            self::assertFileExists($readyPath);

            $socket =
                stream_socket_client(
                    'unix://' . $socketPath,
                    $errno,
                    $error,
                    3
                );

            self::assertIsResource(
                $socket,
                $error
            );

            stream_set_timeout(
                $socket,
                5
            );

            fwrite(
                $socket,
                "GET /slow HTTP/1.1\r\n"
                . "Host: localhost\r\n"
                . "Connection: close\r\n"
                . "Content-Length: 0\r\n"
                . "\r\n"
            );

            $response =
                stream_get_contents(
                    $socket
                );

            fclose($socket);

            self::assertStringContainsString(
                'HTTP/1.1 200 ',
                $response
            );

            self::assertStringContainsString(
                '{"ok":true}',
                $response
            );
        } finally {
            posix_kill(
                $pid,
                SIGTERM
            );

            pcntl_waitpid(
                $pid,
                $status
            );

            @unlink($socketPath);
            @unlink($readyPath);
            @rmdir($base);
        }
    }
}
