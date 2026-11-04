<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';

use Greenwood\DeploymentController\Assignment\{AssignmentReceiver,AssignmentEndpoint,PhysicalAssignmentServices};

umask(0077);
$path = getenv('GREENWOOD_ASSIGNMENT_CONFIG');
if (!$path) throw new RuntimeException('ASSIGNMENT_CONFIGURATION_REQUIRED');
$c = PhysicalAssignmentServices::config($path);
if (!in_array($c['node_id'] ?? null, ['node-1', 'node-4'], true)) throw new RuntimeException('INVALID_RECEIVER_NODE');
$s = PhysicalAssignmentServices::build($c);
$receiver = new AssignmentReceiver($c['node_id'], $c['assignments_directory'], $c['receiver_ledger'], $s['authorize'], $s['policy']->approved(...));
$endpoint = new AssignmentEndpoint($s['channel'], $receiver);
$listener = $s['channel']->listen($c['listen']);
while (true) {
    try { $endpoint->serveOne($listener); }
    catch (Throwable $error) {
        // No request bodies, credentials or exception messages enter service logs.
        $codes = [
            'Revoked control-plane certificate' => 'REVOKED_PEER',
            'Unregistered control-plane certificate' => 'UNREGISTERED_PEER',
            'POLICY_SIGNATURE_REJECTED' => 'POLICY_SIGNATURE_REJECTED',
            'POLICY_ROLLBACK_OR_EQUIVOCATION' => 'POLICY_ROLLBACK_OR_EQUIVOCATION',
            'STALE_OR_INVALID_SIGNED_POLICY' => 'STALE_POLICY',
        ];
        if ($error->getMessage() !== 'Control-plane accept timed out') {
            fwrite(STDERR, ($codes[$error->getMessage()] ?? 'ASSIGNMENT_CONNECTION_CLOSED') . "\n");
        }
    }
}
