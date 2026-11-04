<?php

declare(strict_types=1);

/*
 * GREENWOOD_REGISTRY_AUTH_V1 bootstrap.
 *
 * Runtime configuration:
 *
 * GREENWOOD_CHAIN_ID
 * GREENWOOD_RPC_ENDPOINT
 * GREENWOOD_APP_REGISTRY_CONTRACT
 * GREENWOOD_REGISTRY_ISSUER_KEY
 * GREENWOOD_REGISTRY_AUTH_DB
 *
 * The issuer private key MUST be provisioned externally.
 * This service never generates or replaces network issuer identity.
 */

$chainId = getenv('GREENWOOD_CHAIN_ID') ?: 'greenwood-physical-v1';
$rpcEndpoint = getenv('GREENWOOD_RPC_ENDPOINT') ?: '';
$appRegistryContract = getenv('GREENWOOD_APP_REGISTRY_CONTRACT') ?: '';
$issuerKeyPath = getenv('GREENWOOD_REGISTRY_ISSUER_KEY')
    ?: '/run/secrets/greenwood-registry-issuer.key';
$dbPath = getenv('GREENWOOD_REGISTRY_AUTH_DB')
    ?: '/var/lib/greenwood/registry-auth/auth.sqlite';

if ($rpcEndpoint === '') {
    throw new RuntimeException('GREENWOOD_RPC_ENDPOINT is required');
}

if (!preg_match('/^[0-9a-f]{64}$/D', $appRegistryContract)) {
    throw new RuntimeException(
        'GREENWOOD_APP_REGISTRY_CONTRACT must be a 64-character lowercase hex contract ID'
    );
}

if (is_link($issuerKeyPath) || !is_file($issuerKeyPath) || !is_readable($issuerKeyPath)
    || (fileperms($issuerKeyPath) & 0077) !== 0
    || !in_array(fileowner($issuerKeyPath), [0, posix_geteuid()], true)) {
    throw new RuntimeException(
        'Provisioned registry issuer key is unavailable'
    );
}

return [
    'chain_id' => $chainId,
    'rpc_endpoint' => $rpcEndpoint,
    'application_registry_contract' => $appRegistryContract,
    'issuer_key_path' => $issuerKeyPath,
    'database_path' => $dbPath,
];
