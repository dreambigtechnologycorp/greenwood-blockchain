<?php
declare(strict_types=1);
namespace Greenwood\Tests\Unit\Provider;

use Greenwood\Provider\ProviderContent;
use PHPUnit\Framework\TestCase;

final class ProviderContentTest extends TestCase
{
    public function testZeroRetentionAndHashSnapshotsDoNotConsumeLiveContexts(): void
    {
        foreach ([0, 3] as $limit) {
            $io = new \Greenwood\Compute\Containerd\ExecutionIo(sys_get_temp_dir(), $limit);
            $io->create(); $io->openReaders();
            $out = fopen($io->getStdoutPath(), 'w');
            try {
                fwrite($out, 'abc'); $io->drainAvailable();
                self::assertSame(hash('sha256', 'abc', true), $io->getFullStdoutHash());
                self::assertSame($io->getOutputDigest(), $io->getOutputDigest());
                self::assertSame($limit === 0, $io->isStdoutTruncated());
                fwrite($out, "\0tail"); $io->drainAvailable();
                self::assertSame(substr("abc\0tail", 0, $limit), $io->readStdout());
                self::assertSame(hash('sha256', "abc\0tail", true), $io->getFullStdoutHash());
                self::assertSame(8, $io->getStdoutBytes());
                self::assertTrue($io->isStdoutTruncated());
                self::assertFalse($io->isStderrTruncated());
            } finally { fclose($out); $io->cleanup(); }
        }
    }

    public function testFrozenContentAndExecutionIdentityVectors(): void
    {
        $v = json_decode(file_get_contents(__DIR__ . '/../../Fixtures/provider-content-v1.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($v['workload_digest'], bin2hex(ProviderContent::workloadDigest($v['oci_digest'])));
        foreach ($v['inputs'] as $input) self::assertSame($input['digest'], bin2hex(ProviderContent::inputDigest(hex2bin($input['hex']))));
        foreach ($v['outputs'] as $output) {
            $stdout = hex2bin($output['stdout_hex']); $stderr = hex2bin($output['stderr_hex']);
            self::assertSame($output['stdout_hash'], hash('sha256', $stdout));
            self::assertSame($output['stderr_hash'], hash('sha256', $stderr));
            self::assertSame($output['digest'], bin2hex(ProviderContent::outputDigest(strlen($stdout), hash('sha256', $stdout, true), strlen($stderr), hash('sha256', $stderr, true))));
            self::assertSame($output['retained_stdout_hex'], bin2hex(substr($stdout, 0, $output['retention_limit'])));
            self::assertSame($output['stdout_truncated'], strlen($stdout) > $output['retention_limit']);
            self::assertSame($output['stderr_truncated'], strlen($stderr) > $output['retention_limit']);
        }
        self::assertNotSame($v['outputs'][3]['digest'], $v['outputs'][4]['digest']);
        self::assertSame($v['outputs'][5]['retained_stdout_hex'], $v['outputs'][6]['retained_stdout_hex']);
        self::assertNotSame($v['outputs'][5]['digest'], $v['outputs'][6]['digest']);
        self::assertSame($v['execution_id'], bin2hex(ProviderContent::executionId($v['runtime_execution_id'])));
    }
    public function testLengthsAre64BitAndOverflowFailsExplicitly(): void
    {
        $h = hash('sha256', '', true);
        self::assertSame(32, strlen(ProviderContent::outputDigest(0x100000000, $h, 0, $h)));
        self::assertNotSame(ProviderContent::outputDigest(0, $h, 0, $h), ProviderContent::outputDigest(0x100000000, $h, 0, $h));
        self::assertSame(PHP_INT_MAX, \Greenwood\Compute\Runtime\OutputCommitment::addBytes(PHP_INT_MAX - 1, 1));
        $this->expectException(\OverflowException::class);
        \Greenwood\Compute\Runtime\OutputCommitment::addBytes(PHP_INT_MAX, 1);
    }

    public function testRealFifoDrainMatchesVectorsWithBoundedRetention(): void
    {
        $vectors = json_decode(file_get_contents(__DIR__ . '/../../Fixtures/provider-content-v1.json'), true, 16, JSON_THROW_ON_ERROR);
        foreach ($vectors['outputs'] as $v) {
            $io = new \Greenwood\Compute\Containerd\ExecutionIo(sys_get_temp_dir(), $v['retention_limit']);
            $io->create(); $io->openReaders();
            $out = fopen($io->getStdoutPath(), 'w'); $err = fopen($io->getStderrPath(), 'w');
            try {
                $stdout = hex2bin($v['stdout_hex']); $stderr = hex2bin($v['stderr_hex']);
                // Interleaved writes and small drains exercise chunk-boundary independence.
                for ($offset = 0; $offset < max(strlen($stdout), strlen($stderr)); $offset += 3) {
                    fwrite($out, substr($stdout, $offset, 3)); fwrite($err, substr($stderr, $offset, 3));
                    $io->drainAvailable();
                }
                self::assertSame($v['retained_stdout_hex'], bin2hex($io->readStdout()));
                self::assertSame($v['retained_stderr_hex'], bin2hex($io->readStderr()));
                self::assertSame($v['stdout_length'], $io->getStdoutBytes());
                self::assertSame($v['stderr_length'], $io->getStderrBytes());
                self::assertSame($v['stdout_hash'], bin2hex($io->getFullStdoutHash()));
                self::assertSame($v['stderr_hash'], bin2hex($io->getFullStderrHash()));
                self::assertSame($v['digest'], bin2hex($io->getOutputDigest()));
                self::assertSame($v['stdout_truncated'], $io->isStdoutTruncated());
                self::assertSame($v['stderr_truncated'], $io->isStderrTruncated());
            } finally { fclose($out); fclose($err); $io->cleanup(); }
            self::assertFalse(is_file($io->getStdoutPath()));
            self::assertFalse(is_file($io->getStderrPath()));
        }
    }
}
