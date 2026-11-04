<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Assignment;

/** Validate an operator-reviewed export before adding its exact generation. */
final class AllocationApproval
{
    public static function validate(array $export, string $expected, \Closure $authorize): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expected)) throw new \RuntimeException('EXPLICIT_GENERATION_REQUIRED');
        $document = AssignmentDocument::validate($export['document'] ?? []);
        $generation = AssignmentDocument::generation($document);
        if (!hash_equals($expected, $generation) || ($export['generation'] ?? null) !== $generation
            || ($export['assignment_id'] ?? null) !== AssignmentDocument::assignmentId($document)
            || ($export['operation_id'] ?? null) !== $document['operation_id']) {
            throw new \RuntimeException('APPROVAL_EXPORT_MISMATCH');
        }
        if ($document['version'] !== 1) throw new \RuntimeException('INITIAL_ASSIGNMENT_ONLY');
        if ($authorize($document) !== true) throw new \RuntimeException('FINALIZED_APPROVAL_DENIED');
        return $document;
    }
}
