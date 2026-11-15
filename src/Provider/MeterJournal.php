<?php
declare(strict_types=1);
namespace Greenwood\Provider;
/** Existing provider diagnostics journal boundary, local or private system-service IPC. */
interface MeterJournal
{
    public function bind(array $scope): void;
    public function segmentState(string $commitment,string $runtime): ?string;
    public function openSegments(string $runtime): array;
    public function closeBoundary(string $commitment,array $sample): void;
    public function begin(string $commitment,array $sample): void;
    public function checkpoint(string $commitment,array $sample): array;
    public function finish(string $commitment,array $sample): void;
    public function bundle(string $commitment): array;
}
