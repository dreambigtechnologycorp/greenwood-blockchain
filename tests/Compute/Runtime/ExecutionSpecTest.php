<?php
declare(strict_types=1);

namespace Greenwood\Tests\Compute\Runtime;

use Greenwood\Compute\Runtime\ExecutionSpec;
use Greenwood\Compute\Runtime\Mount;
use PHPUnit\Framework\TestCase;

class ExecutionSpecTest extends TestCase
{
    public function testValidSpec(): void
    {
        $spec = new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: 'alpine:3.19',
            command: ['echo', 'hello'],
            environment: ['FOO' => 'bar'],
            workingDirectory: '/app',
            cpuLimit: 0.5,
            memoryLimitBytes: 128 * 1024 * 1024,
            timeoutSeconds: 30,
            readOnlyRootFilesystem: true,
            networkPolicy: ExecutionSpec::NETWORK_POLICY_NONE
        );

        $this->assertSame('exec-123', $spec->getExecutionId());
        $this->assertSame('alpine:3.19', $spec->getImageReference());
        $this->assertSame(['echo', 'hello'], $spec->getCommand());
        $this->assertSame(['FOO' => 'bar'], $spec->getEnvironment());
        $this->assertSame('/app', $spec->getWorkingDirectory());
        $this->assertSame(0.5, $spec->getCpuLimit());
        $this->assertSame(128 * 1024 * 1024, $spec->getMemoryLimitBytes());
        $this->assertSame(30, $spec->getTimeoutSeconds());
        $this->assertTrue($spec->isReadOnlyRootFilesystem());
        $this->assertSame(ExecutionSpec::NETWORK_POLICY_NONE, $spec->getNetworkPolicy());
    }

    public function testDefaultValues(): void
    {
        $spec = ExecutionSpec::createDefault('exec-123', 'alpine:3.19', 'echo');

        $this->assertSame('exec-123', $spec->getExecutionId());
        $this->assertSame('alpine:3.19', $spec->getImageReference());
        $this->assertSame(['echo'], $spec->getCommand());
        $this->assertNull($spec->getWorkingDirectory());
        // Default values are set, not null
        $this->assertNotNull($spec->getCpuLimit());
        $this->assertNotNull($spec->getMemoryLimitBytes());
        $this->assertNotNull($spec->getTimeoutSeconds());
        $this->assertTrue($spec->isReadOnlyRootFilesystem());
        $this->assertSame(ExecutionSpec::NETWORK_POLICY_NONE, $spec->getNetworkPolicy());
    }

    public function testEmptyExecutionId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Execution ID cannot be empty');
        new ExecutionSpec(
            executionId: '',
            imageReference: 'alpine:3.19',
            command: ['echo']
        );
    }

    public function testEmptyImageReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image reference cannot be empty');
        new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: '',
            command: ['echo']
        );
    }

    public function testEmptyCommand(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Command cannot be empty');
        new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: 'alpine:3.19',
            command: []
        );
    }

    public function testInvalidTimeoutZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be positive');
        new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: 'alpine:3.19',
            command: ['echo'],
            timeoutSeconds: 0
        );
    }

    public function testInvalidTimeoutNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be positive');
        new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: 'alpine:3.19',
            command: ['echo'],
            timeoutSeconds: -1
        );
    }

    public function testTimeoutExceedsMaximum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout exceeds maximum of 24 hours');
        new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: 'alpine:3.19',
            command: ['echo'],
            timeoutSeconds: 86401
        );
    }

    public function testInvalidMemoryZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Memory limit must be positive');
        new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: 'alpine:3.19',
            command: ['echo'],
            memoryLimitBytes: 0
        );
    }

    public function testMemoryBelowMinimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Memory limit must be at least 1MB');
        new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: 'alpine:3.19',
            command: ['echo'],
            memoryLimitBytes: 1024 * 1024 - 1
        );
    }

    public function testMemoryExceedsMaximum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Memory limit exceeds maximum of 16GB');
        new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: 'alpine:3.19',
            command: ['echo'],
            memoryLimitBytes: 16 * 1024 * 1024 * 1024 + 1
        );
    }

    public function testInvalidCpuZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CPU limit must be positive');
        new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: 'alpine:3.19',
            command: ['echo'],
            cpuLimit: 0
        );
    }

    public function testCpuExceedsMaximum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CPU limit exceeds maximum of 64 cores');
        new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: 'alpine:3.19',
            command: ['echo'],
            cpuLimit: 65
        );
    }

    public function testInvalidNetworkPolicy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid network policy');
        new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: 'alpine:3.19',
            command: ['echo'],
            networkPolicy: 'INVALID'
        );
    }

    public function testValidNetworkPolicies(): void
    {
        $this->assertNotNull(new ExecutionSpec(
            executionId: 'exec-1',
            imageReference: 'alpine:3.19',
            command: ['echo'],
            networkPolicy: ExecutionSpec::NETWORK_POLICY_NONE
        ));

        $this->assertNotNull(new ExecutionSpec(
            executionId: 'exec-2',
            imageReference: 'alpine:3.19',
            command: ['echo'],
            networkPolicy: ExecutionSpec::NETWORK_POLICY_HOST
        ));

        $this->assertNotNull(new ExecutionSpec(
            executionId: 'exec-3',
            imageReference: 'alpine:3.19',
            command: ['echo'],
            networkPolicy: ExecutionSpec::NETWORK_POLICY_CUSTOM
        ));
    }

    public function testEmptyEnvironmentKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Environment key cannot be empty');
        new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: 'alpine:3.19',
            command: ['echo'],
            environment: ['' => 'value']
        );
    }

    public function testWhitespaceEnvironmentKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Environment key cannot be empty or whitespace only');
        new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: 'alpine:3.19',
            command: ['echo'],
            environment: ['   ' => 'value']
        );
    }

    public function testNonStringCommandArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('All command arguments must be strings');
        new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: 'alpine:3.19',
            command: ['echo', 123]
        );
    }

    public function testMounts(): void
    {
        $mount = Mount::bindReadOnly('/host/path', '/container/path');
        $spec = new ExecutionSpec(
            executionId: 'exec-123',
            imageReference: 'alpine:3.19',
            command: ['echo'],
            mounts: [$mount]
        );

        $this->assertEquals([$mount], $spec->getMounts());
    }

    public function testToArray(): void
    {
        $spec = ExecutionSpec::createDefault('exec-123', 'alpine:3.19', 'echo');
        $array = $spec->toArray();

        $this->assertIsArray($array);
        $this->assertSame('exec-123', $array['executionId']);
        $this->assertSame('alpine:3.19', $array['imageReference']);
        $this->assertSame(['echo'], $array['command']);
        $this->assertTrue($array['readOnlyRootFilesystem']);
        $this->assertSame(ExecutionSpec::NETWORK_POLICY_NONE, $array['networkPolicy']);
    }
}
