<?php
declare(strict_types=1);
namespace Greenwood\Rpc;

use Greenwood\Node\Chain;

/** Read-only views of one finalized State; transport bytes are base64/hex only. */
final class ProviderQueries
{
    public static function handle(Chain $chain, string $path): ?array
    {
        $base = ['chain_id' => $chain->genesis->getChainId(), 'finalized_height' => $chain->tip->getHeight(),
            'finalized_hash' => bin2hex($chain->tip->getHash()), 'state_root' => bin2hex($chain->state->getStateRoot())];
        if (preg_match('~^/accounts/([0-9a-f]{64})/nonce$~D', $path, $m)) {
            $id = hex2bin($m[1]);
            return $base + ['nonce' => $chain->state->hasAccount($id) ? $chain->state->getAccount($id)->getNonce() : 0];
        }
        if (preg_match('~^/compute/requests/([0-9a-f]{64})$~D', $path, $m)) {
            $id = hex2bin($m[1]); $records = [];
            foreach ($chain->state->getComputeState()->logicalRecords() as [$namespace, $key, $value]) {
                if ($key === $id) $records[$namespace] = base64_encode($value);
            }
            return $base + ['request_id' => $m[1], 'records' => $records];
        }
        if (preg_match('~^/compute/requests/([0-9a-f]{64})/history$~D', $path, $m)) {
            // Pass 4E section 37: current/prior generations visible to providers and requesters.
            return $base + ['request_id' => $m[1], 'assignments' => $chain->assignmentHistory(hex2bin($m[1]))];
        }
        if (preg_match('~^/compute/providers/([0-9a-f]{64})/assignments/(-|[0-9a-f]{64})$~D', $path, $m)) {
            $provider = hex2bin($m[1]); $after = $m[2] === '-' ? null : hex2bin($m[2]);
            $compute = $chain->state->getComputeState(); $ids = [];
            foreach ($compute->logicalRecords() as [$namespace, $id, $value]) {
                if ($namespace !== 'compute_assignments' || ($after !== null && strcmp($id, $after) <= 0)
                    || $compute->terminal($id) || $compute->assignment($id)->providerId !== $provider) continue;
                $ids[] = bin2hex($id);
                if (count($ids) === 65) break;
            }
            $more = count($ids) > 64;
            if ($more) array_pop($ids);
            return $base + ['request_ids' => $ids, 'next' => $more ? end($ids) : null];
        }
        return null;
    }
}
