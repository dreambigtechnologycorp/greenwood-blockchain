<?php

declare(strict_types=1);

namespace Greenwood\Tests\Unit\Provider;

use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;
use Greenwood\Provider\ProviderApplicationRuntimeInspector;
use PHPUnit\Framework\TestCase;

final class ProviderApplicationRuntimeInspectorTest extends TestCase
{
    private function record(): array
    {
        return [
            'container_id' =>
                'app-1-web',
            'view_key' =>
                'gw-system-view-app-1-web',
            'lease_id' =>
                'gw-system-lease-app-1-web',
            'image_ref' =>
                'registry.local/apps/1/web@sha256:'
                . str_repeat('a', 64),
        ];
    }

    private function missing(): never
    {
        throw new ContainerdException(
            'not found',
            ContainerdRpcException::RPC_NOT_FOUND
        );
    }

    public function testDefinitelyAbsent(): void
    {
        $inspector =
            new ProviderApplicationRuntimeInspector(
                fn () => $this->missing(),
                fn () => $this->missing(),
                fn () => $this->missing()
            );

        $result =
            $inspector->inspect(
                $this->record()
            );

        self::assertSame(
            'ABSENT',
            $result['runtime_state']
        );

        self::assertFalse(
            $result['container_present']
        );

        self::assertFalse(
            $result['task_present']
        );

        self::assertFalse(
            $result['snapshot_present']
        );
    }

    public function testRunningRuntime(): void
    {
        $record = $this->record();

        $inspector =
            new ProviderApplicationRuntimeInspector(
                fn () => [
                    'image_ref' =>
                        $record['image_ref'],
                    'snapshot_key' =>
                        $record['view_key'],
                ],
                fn () => [
                    'state' =>
                        'RUNNING',
                    'pid' => 1234,
                ],
                fn () => [
                    'parent_snapshot' =>
                        'sha256:'
                        . str_repeat(
                            'b',
                            64
                        ),
                ]
            );

        $result =
            $inspector->inspect(
                $record
            );

        self::assertSame(
            'RUNNING',
            $result['runtime_state']
        );

        self::assertSame(
            1234,
            $result['pid']
        );

        self::assertTrue(
            $result['container_present']
        );

        self::assertTrue(
            $result['task_present']
        );

        self::assertTrue(
            $result['snapshot_present']
        );
    }

    public function testRunningTaskStateIsExposed(): void
    {
        $record = $this->record();

        $inspector =
            new ProviderApplicationRuntimeInspector(
                fn () => [
                    'image_ref' =>
                        $record['image_ref'],
                    'snapshot_key' =>
                        $record['view_key'],
                ],
                fn () => [
                    'state' => 'RUNNING',
                    'pid' => 1234,
                ],
                fn () => [
                    'parent_snapshot' =>
                        'sha256:'
                        . str_repeat('b', 64),
                ],
                fn () => [
                    'id' =>
                        $record['lease_id'],
                ]
            );

        $result =
            $inspector->inspect(
                $record
            );

        self::assertSame(
            'RUNNING',
            $result['task_state']
        );
    }

    public function testStoppedTaskIsObserved(): void
    {
        $record = $this->record();

        $inspector =
            new ProviderApplicationRuntimeInspector(
                fn () => [
                    'image_ref' =>
                        $record['image_ref'],
                    'snapshot_key' =>
                        $record['view_key'],
                ],
                fn () => [
                    'state' =>
                        'STOPPED',
                    'pid' => 1234,
                ],
                fn () => [
                    'parent_snapshot' =>
                        'sha256:'
                        . str_repeat(
                            'b',
                            64
                        ),
                ]
            );

        $result =
            $inspector->inspect(
                $record
            );

        self::assertSame(
            'STOPPED',
            $result['runtime_state']
        );

        self::assertSame(
            'STOPPED',
            $result['task_state']
        );
    }

    public function testPartialOwnershipIsResidue(): void
    {
        $record = $this->record();

        $inspector =
            new ProviderApplicationRuntimeInspector(
                fn () => [
                    'image_ref' =>
                        $record['image_ref'],
                    'snapshot_key' =>
                        $record['view_key'],
                ],
                fn () => $this->missing(),
                fn () => [
                    'parent_snapshot' =>
                        'sha256:'
                        . str_repeat(
                            'b',
                            64
                        ),
                ]
            );

        $result =
            $inspector->inspect(
                $record
            );

        self::assertSame(
            'RESIDUE',
            $result['runtime_state']
        );

        self::assertTrue(
            $result['container_present']
        );

        self::assertFalse(
            $result['task_present']
        );

        self::assertTrue(
            $result['snapshot_present']
        );
    }

    public function testPartialContainerOwnershipMismatchIsInconsistent(): void
    {
        $record = $this->record();

        $inspector =
            new ProviderApplicationRuntimeInspector(
                fn () => [
                    'image_ref' =>
                        'registry.local/wrong@sha256:'
                        . str_repeat('c', 64),
                    'snapshot_key' =>
                        $record['view_key'],
                ],
                fn () => $this->missing(),
                fn () => $this->missing(),
                fn () => null
            );

        $result =
            $inspector->inspect(
                $record
            );

        self::assertSame(
            'INCONSISTENT',
            $result['runtime_state']
        );

        self::assertTrue(
            $result['container_present']
        );

        self::assertFalse(
            $result['task_present']
        );
    }

    public function testOwnershipMismatchIsInconsistent(): void
    {
        $record = $this->record();

        $inspector =
            new ProviderApplicationRuntimeInspector(
                fn () => [
                    'image_ref' =>
                        'registry.local/wrong@sha256:'
                        . str_repeat(
                            'c',
                            64
                        ),
                    'snapshot_key' =>
                        $record['view_key'],
                ],
                fn () => [
                    'state' =>
                        'RUNNING',
                    'pid' => 1234,
                ],
                fn () => [
                    'parent_snapshot' =>
                        'sha256:'
                        . str_repeat(
                            'b',
                            64
                        ),
                ]
            );

        self::assertSame(
            'INCONSISTENT',
            $inspector
                ->inspect(
                    $record
                )['runtime_state']
        );
    }

    public function testLeaseOnlyResidueIsNotAbsent(): void
    {
        $inspector =
            new ProviderApplicationRuntimeInspector(
                fn () => $this->missing(),
                fn () => $this->missing(),
                fn () => $this->missing(),
                fn () => [
                    'id' =>
                        'gw-system-lease-app-1-web',
                ]
            );

        $result =
            $inspector->inspect(
                $this->record()
            );

        self::assertSame(
            'RESIDUE',
            $result['runtime_state']
        );

        self::assertTrue(
            $result['lease_present']
        );
    }

    public function testMissingLeaseMakesRuntimeResidue(): void
    {
        $record = $this->record();

        $inspector =
            new ProviderApplicationRuntimeInspector(
                fn () => [
                    'image_ref' =>
                        $record['image_ref'],
                    'snapshot_key' =>
                        $record['view_key'],
                ],
                fn () => [
                    'state' => 'RUNNING',
                    'pid' => 1234,
                ],
                fn () => [
                    'parent_snapshot' =>
                        'sha256:'
                        . str_repeat('b', 64),
                ],
                fn () => null
            );

        $result =
            $inspector->inspect(
                $record
            );

        self::assertSame(
            'RESIDUE',
            $result['runtime_state']
        );

        self::assertFalse(
            $result['lease_present']
        );
    }

    public function testLeaseLookupFailureIsNotAbsence(): void
    {
        $record = $this->record();

        $inspector =
            new ProviderApplicationRuntimeInspector(
                fn () => [
                    'image_ref' =>
                        $record['image_ref'],
                    'snapshot_key' =>
                        $record['view_key'],
                ],
                fn () => [
                    'state' => 'RUNNING',
                    'pid' => 1234,
                ],
                fn () => [
                    'parent_snapshot' =>
                        'sha256:'
                        . str_repeat('b', 64),
                ],
                function (): never {
                    throw new ContainerdException(
                        'lease list unavailable',
                        14
                    );
                }
            );

        $this->expectException(
            ContainerdException::class
        );

        $this->expectExceptionCode(14);

        $inspector->inspect(
            $record
        );
    }

    public function testRpcFailureIsNotAbsence(): void
    {
        $inspector =
            new ProviderApplicationRuntimeInspector(
                function (): array {
                    throw new ContainerdException(
                        'containerd unavailable',
                        14
                    );
                },
                fn () => $this->missing(),
                fn () => $this->missing()
            );

        $this->expectException(
            ContainerdException::class
        );

        $this->expectExceptionCode(14);

        $inspector->inspect(
            $this->record()
        );
    }
}
