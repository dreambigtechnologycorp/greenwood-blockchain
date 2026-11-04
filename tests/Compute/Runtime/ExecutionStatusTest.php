<?php
declare(strict_types=1);

namespace Greenwood\Tests\Compute\Runtime;

use Greenwood\Compute\Runtime\ExecutionStatus;
use PHPUnit\Framework\TestCase;

class ExecutionStatusTest extends TestCase
{
    public function testValidStatuses(): void
    {
        $this->assertSame('COMPLETED', ExecutionStatus::completed()->getValue());
        $this->assertSame('FAILED', ExecutionStatus::failed()->getValue());
        $this->assertSame('TIMED_OUT', ExecutionStatus::timedOut()->getValue());
        $this->assertSame('TERMINATED', ExecutionStatus::terminated()->getValue());
        $this->assertSame('RUNTIME_ERROR', ExecutionStatus::runtimeError()->getValue());
    }

    public function testIsCompleted(): void
    {
        $this->assertTrue(ExecutionStatus::completed()->isCompleted());
        $this->assertTrue(ExecutionStatus::completed()->isSuccess());
        $this->assertFalse(ExecutionStatus::completed()->isFailed());
        $this->assertFalse(ExecutionStatus::completed()->isTimedOut());
        $this->assertFalse(ExecutionStatus::completed()->isTerminated());
        $this->assertFalse(ExecutionStatus::completed()->isError());
    }

    public function testIsFailed(): void
    {
        $this->assertFalse(ExecutionStatus::failed()->isCompleted());
        $this->assertFalse(ExecutionStatus::failed()->isSuccess());
        $this->assertTrue(ExecutionStatus::failed()->isFailed());
        $this->assertFalse(ExecutionStatus::failed()->isTimedOut());
        $this->assertFalse(ExecutionStatus::failed()->isTerminated());
        $this->assertFalse(ExecutionStatus::failed()->isError());
    }

    public function testIsTimedOut(): void
    {
        $this->assertFalse(ExecutionStatus::timedOut()->isCompleted());
        $this->assertFalse(ExecutionStatus::timedOut()->isSuccess());
        $this->assertFalse(ExecutionStatus::timedOut()->isFailed());
        $this->assertTrue(ExecutionStatus::timedOut()->isTimedOut());
        $this->assertFalse(ExecutionStatus::timedOut()->isTerminated());
        $this->assertFalse(ExecutionStatus::timedOut()->isError());
    }

    public function testIsTerminated(): void
    {
        $this->assertFalse(ExecutionStatus::terminated()->isCompleted());
        $this->assertFalse(ExecutionStatus::terminated()->isSuccess());
        $this->assertFalse(ExecutionStatus::terminated()->isFailed());
        $this->assertFalse(ExecutionStatus::terminated()->isTimedOut());
        $this->assertTrue(ExecutionStatus::terminated()->isTerminated());
        $this->assertFalse(ExecutionStatus::terminated()->isError());
    }

    public function testIsRuntimeError(): void
    {
        $this->assertFalse(ExecutionStatus::runtimeError()->isCompleted());
        $this->assertFalse(ExecutionStatus::runtimeError()->isSuccess());
        $this->assertFalse(ExecutionStatus::runtimeError()->isFailed());
        $this->assertFalse(ExecutionStatus::runtimeError()->isTimedOut());
        $this->assertFalse(ExecutionStatus::runtimeError()->isTerminated());
        $this->assertTrue(ExecutionStatus::runtimeError()->isError());
    }

    public function testFromString(): void
    {
        $this->assertSame('COMPLETED', ExecutionStatus::fromString('COMPLETED')->getValue());
        $this->assertSame('FAILED', ExecutionStatus::fromString('FAILED')->getValue());
    }

    public function testToString(): void
    {
        $this->assertSame('COMPLETED', (string) ExecutionStatus::completed());
    }

    public function testInvalidStatusThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid execution status');
        ExecutionStatus::fromString('INVALID_STATUS');
    }
}
