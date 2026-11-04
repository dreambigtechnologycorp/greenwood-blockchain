<?php

declare(strict_types=1);

namespace Greenwood\Provider;

final class ProviderApplicationReconciler
{
    public function __construct(
        private readonly ProviderApplicationRegistry $registry,
        private readonly ProviderApplicationRuntimeInspector $inspector,
        private readonly ?ProviderApplicationRuntimeCleaner $cleaner = null
    ) {
    }

    public function reconcile(
        int $applicationId,
        string $service
    ): array {
        $record =
            $this->registry->get(
                $applicationId,
                $service
            );

        $observation =
            $this->inspector->inspect(
                $record
            );

        $runtimeState =
            $observation['runtime_state'];

        $recordState =
            $record['state'];

        if (
            $recordState === 'STARTING'
            && $runtimeState === 'RUNNING'
        ) {
            $record =
                $this->registry->markRunning(
                    $applicationId,
                    $service,
                    $record['container_id'],
                    $record['view_key'],
                    $observation['parent_snapshot'],
                    $record['lease_id'],
                    $observation['pid']
                );

            return $this->result(
                $record,
                $observation,
                'RECOVERED_RUNNING'
            );
        }

        if (
            in_array(
                $recordState,
                ['STARTING', 'RUNNING'],
                true
            )
            && $runtimeState === 'ABSENT'
        ) {
            $record =
                $this->registry->markFailed(
                    $applicationId,
                    $service
                );

            return $this->result(
                $record,
                $observation,
                'CONFIRMED_ABSENT'
            );
        }

        if (
            $recordState === 'STOPPING'
            && $runtimeState === 'ABSENT'
        ) {
            $record =
                $this->registry->markStopped(
                    $applicationId,
                    $service
                );

            return $this->result(
                $record,
                $observation,
                'CONFIRMED_STOPPED'
            );
        }

        if (
            $recordState === 'RUNNING'
            && $runtimeState === 'RUNNING'
        ) {
            return $this->result(
                $record,
                $observation,
                'UNCHANGED'
            );
        }

        if (
            $this->cleaner !== null
            && $recordState === 'STOPPING'
            && in_array(
                $runtimeState,
                [
                    'RUNNING',
                    'STOPPED',
                    'RESIDUE',
                ],
                true
            )
        ) {
            $cleanup =
                $this->cleaner->cleanup(
                    $record
                );

            $record =
                $this->registry->markStopped(
                    $applicationId,
                    $service
                );

            return $this->result(
                $record,
                $cleanup['after'],
                'CLEANED_STOPPED'
            );
        }

        if (
            $this->cleaner !== null
            && in_array(
                $recordState,
                ['STARTING', 'RUNNING'],
                true
            )
            && in_array(
                $runtimeState,
                ['STOPPED', 'RESIDUE'],
                true
            )
        ) {
            $cleanup =
                $this->cleaner->cleanup(
                    $record
                );

            $record =
                $this->registry->markFailed(
                    $applicationId,
                    $service
                );

            return $this->result(
                $record,
                $cleanup['after'],
                'CLEANED_FAILED'
            );
        }

        /*
         * STOPPED, RESIDUE and INCONSISTENT all retain durable
         * ownership. They require an explicit cleanup/recovery path
         * before another start may replace this application's IDs.
         */
        return $this->result(
            $record,
            $observation,
            'RECOVERY_REQUIRED'
        );
    }

    public function reconcileActive(): array
    {
        $results = [];

        foreach (
            $this->registry->active()
            as $record
        ) {
            $results[] =
                $this->reconcile(
                    (int) $record[
                        'application_id'
                    ],
                    $record['service']
                );
        }

        return $results;
    }

    private function result(
        array $record,
        array $observation,
        string $outcome
    ): array {
        return [
            'outcome' => $outcome,
            'record' => $record,
            'runtime' => $observation,
        ];
    }
}
