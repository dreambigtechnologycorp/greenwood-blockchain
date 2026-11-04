<?php
declare(strict_types=1);

namespace Greenwood\Tests\Compute\Runtime;

use Greenwood\Compute\Runtime\ExecutionResult;
use Greenwood\Compute\Runtime\ExecutionStatus;
use Greenwood\Compute\Runtime\ResourceUsage;
use PHPUnit\Framework\TestCase;

class ExecutionResultTest extends TestCase
{
    private \DateTimeImmutable $startedAt;
    private \DateTimeImmutable $finishedAt;

    protected function setUp(): void
    {
        $this->startedAt = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $this->finishedAt = new \DateTimeImmutable('2026-01-01T00:01:00Z');
    }

    public function testSuccessResult(): void
    {
        $result = ExecutionResult::success(
            executionId: 'exec-123',
            resolvedImageDigest: 'sha256:' . str_repeat('a', 64),
            exitCode: 0,
            stdout: 'Hello, World!',
            stderr: '',
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            duration: 60.0
        );

        $this->assertSame('exec-123', $result->getExecutionId());
        $this->assertTrue($result->getStatus()->isCompleted());
        $this->assertSame(0, $result->getExitCode());
        $this->assertSame('Hello, World!', $result->getStdout());
        $this->assertSame('', $result->getStderr());
        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailed());
        $this->assertFalse($result->isTimedOut());
        $this->assertFalse($result->isTerminated());
    }

    public function testFailedResult(): void
    {
        $result = ExecutionResult::failed(
            executionId: 'exec-123',
            resolvedImageDigest: 'sha256:' . str_repeat('b', 64),
            exitCode: 1,
            stdout: '',
            stderr: 'Error occurred',
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            duration: 10.0,
            errorMessage: 'Command failed'
        );

        $this->assertTrue($result->getStatus()->isFailed());
        $this->assertSame(1, $result->getExitCode());
        $this->assertSame('Error occurred', $result->getStderr());
        $this->assertSame('Command failed', $result->getErrorMessage());
        // TIMED_OUT and TERMINATED are errors but not FAILED
        // FAILED status means isFailed() returns true
        $this->assertTrue($result->isFailed());
    }

    public function testTimedOutResult(): void
    {
        $result = ExecutionResult::timedOut(
            executionId: 'exec-123',
            resolvedImageDigest: 'sha256:' . str_repeat('c', 64),
            stdout: 'partial output',
            stderr: '',
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            duration: 30.0,
            errorMessage: 'Execution timed out after 30s'
        );

        $this->assertTrue($result->getStatus()->isTimedOut());
        $this->assertSame(137, $result->getExitCode()); // 128 + 9 (SIGKILL)
        $this->assertTrue($result->isTimedOut());
        // TIMED_OUT and TERMINATED are errors but not FAILED
        $this->assertFalse($result->isFailed());
    }

    public function testTerminatedResult(): void
    {
        $result = ExecutionResult::terminated(
            executionId: 'exec-123',
            resolvedImageDigest: 'sha256:' . str_repeat('d', 64),
            stdout: '',
            stderr: 'Terminated by user',
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            duration: 5.0,
            errorMessage: 'Manual termination'
        );

        $this->assertTrue($result->getStatus()->isTerminated());
        $this->assertSame(137, $result->getExitCode());
        $this->assertTrue($result->isTerminated());
        // TIMED_OUT and TERMINATED are errors but not FAILED
        $this->assertFalse($result->isFailed());
    }

    public function testRuntimeErrorResult(): void
    {
        $result = ExecutionResult::runtimeError(
            executionId: 'exec-123',
            resolvedImageDigest: 'sha256:' . str_repeat('e', 64),
            errorMessage: 'Container runtime error'
        );

        $this->assertTrue($result->getStatus()->isError());
        $this->assertSame(255, $result->getExitCode());
        $this->assertTrue($result->isError());
    }

    public function testHashesGeneratedAutomatically(): void
    {
        $stdout = 'Hello';
        $stderr = 'World';
        
        $result = ExecutionResult::success(
            executionId: 'exec-123',
            resolvedImageDigest: 'sha256:' . str_repeat('f', 64),
            exitCode: 0,
            stdout: $stdout,
            stderr: $stderr,
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            duration: 1.0
        );

        $expectedStdoutHash = hash('sha256', $stdout);
        $expectedStderrHash = hash('sha256', $stderr);

        $this->assertSame($expectedStdoutHash, $result->getStdoutHash());
        $this->assertSame($expectedStderrHash, $result->getStderrHash());
    }

    public function testCustomHashes(): void
    {
        $customStdoutHash = hash('sha256', 'custom-stdout');
        $customStderrHash = hash('sha256', 'custom-stderr');
        
        $result = new ExecutionResult(
            executionId: 'exec-123',
            resolvedImageDigest: 'sha256:' . str_repeat('f', 64),
            status: ExecutionStatus::completed(),
            exitCode: 0,
            stdout: 'test',
            stderr: 'test',
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            duration: 1.0,
            stdoutHash: $customStdoutHash,
            stderrHash: $customStderrHash
        );

        $this->assertSame($customStdoutHash, $result->getStdoutHash());
        $this->assertSame($customStderrHash, $result->getStderrHash());
    }

    public function testResourceUsage(): void
    {
        $resourceUsage = ResourceUsage::withMemory(128 * 1024 * 1024, 256 * 1024 * 1024);
        
        $result = ExecutionResult::success(
            executionId: 'exec-123',
            resolvedImageDigest: 'sha256:' . str_repeat('f', 64),
            exitCode: 0,
            stdout: '',
            stderr: '',
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            duration: 1.0,
            resourceUsage: $resourceUsage
        );

        $this->assertSame($resourceUsage, $result->getResourceUsage());
        $this->assertSame(128.0, $result->getResourceUsage()?->getMemoryMegabytes());
    }

    public function testEmptyExecutionId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Execution ID cannot be empty');
        ExecutionResult::success(
            executionId: '',
            resolvedImageDigest: 'sha256:' . str_repeat('a', 64),
            exitCode: 0,
            stdout: '',
            stderr: '',
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            duration: 1.0
        );
    }

    public function testEmptyResolvedImageDigest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Resolved image digest cannot be empty');
        ExecutionResult::success(
            executionId: 'exec-123',
            resolvedImageDigest: '',
            exitCode: 0,
            stdout: '',
            stderr: '',
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            duration: 1.0
        );
    }

    public function testNegativeDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duration cannot be negative');
        ExecutionResult::success(
            executionId: 'exec-123',
            resolvedImageDigest: 'sha256:' . str_repeat('a', 64),
            exitCode: 0,
            stdout: '',
            stderr: '',
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            duration: -1.0
        );
    }

    public function testToArray(): void
    {
        $result = ExecutionResult::success(
            executionId: 'exec-123',
            resolvedImageDigest: 'sha256:' . str_repeat('a', 64),
            exitCode: 0,
            stdout: 'test',
            stderr: '',
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            duration: 60.0
        );

        $array = $result->toArray();

        $this->assertIsArray($array);
        $this->assertSame('exec-123', $array['executionId']);
        $this->assertSame('COMPLETED', $array['status']);
        $this->assertSame(0, $array['exitCode']);
        $this->assertSame('test', $array['stdout']);
        $this->assertArrayHasKey('startedAt', $array);
        $this->assertArrayHasKey('finishedAt', $array);
    }

    public function testDurationCalculation(): void
    {
        $started = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $finished = new \DateTimeImmutable('2026-01-01T00:00:30.500Z');
        
        $result = ExecutionResult::success(
            executionId: 'exec-123',
            resolvedImageDigest: 'sha256:' . str_repeat('a', 64),
            exitCode: 0,
            stdout: '',
            stderr: '',
            startedAt: $started,
            finishedAt: $finished,
            duration: 30.5
        );

        $this->assertSame(30.5, $result->getDuration());
    }
}
