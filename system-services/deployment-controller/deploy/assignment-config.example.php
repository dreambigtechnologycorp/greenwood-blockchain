<?php
declare(strict_types=1);
// Review template only. Invalid placeholders deliberately prevent startup.
return [
    'node_id' => 'OPERATOR_SELECTED_NODE',
    'listen' => 'OPERATOR_SELECTED_PRIVATE_TCP_ENDPOINT',
    'rpc_endpoint' => 'OPERATOR_VERIFIED_RPC_ORIGIN',
    'approved_ca_sha256' => 'OPERATOR_ATTESTED_CA_FINGERPRINT',
    'revocation_public_key' => 'PKI_OPERATOR_ED25519_PUBLIC_KEY_HEX',
    'allocation_public_key' => 'INFRASTRUCTURE_OPERATOR_ED25519_PUBLIC_KEY_HEX',
    'revocation_max_age' => 300,
    'allocation_max_age' => 300,
    'policy_ledger' => '/var/lib/greenwood-control-plane/receiver/policies.sqlite',
    'receiver_ledger' => '/var/lib/greenwood-control-plane/receiver/receiver.sqlite',
    'assignments_directory' => '/var/lib/greenwood-control-plane/receiver/assignments',
    'approved_allocations_file' => __DIR__ . '/allocations.json',
    'tls' => [
        'ca' => __DIR__ . '/ca.pem',
        'certificate' => __DIR__ . '/service.pem',
        'private_key' => __DIR__ . '/service.key',
        'revocations' => __DIR__ . '/revocations.json',
        'peer_identities' => [],
        'peer_uris' => [],
    ],
    // Edge-admission configuration additionally requires admissions_directory,
    // distribution_ledger and both assignment_receivers entries containing
    // endpoint, tls_name and identity. It uses its own status-only certificate.
];
