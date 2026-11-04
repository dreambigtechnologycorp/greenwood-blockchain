<?php
declare(strict_types=1);
// No Apache writes. Verification still advances protected policy rollback ledgers.
if (getenv('GREENWOOD_EDGE_READ_ONLY_CHECK') !== '1') throw new RuntimeException('EDGE_CANDIDATE_READ_ONLY_REQUIRED');
require dirname(__DIR__).'/autoload.php';
$settings = \Greenwood\DeploymentController\Assignment\PhysicalAssignmentServices::config(
    getenv('GREENWOOD_EDGE_ADMISSION_CONFIG') ?: '');
$observe = require __DIR__.'/edge-route-observation.php';
echo json_encode(['review_only'=>true,'publication_performed'=>false]+$observe($settings), JSON_THROW_ON_ERROR), "\n";
