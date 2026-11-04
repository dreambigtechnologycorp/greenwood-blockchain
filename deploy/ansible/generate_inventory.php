#!/usr/bin/env php
<?php

/**
 * Generate Ansible inventory from Terraform topology output.
 *
 * Usage:
 *   terraform output -json | php generate_inventory.php > inventory.ini
 *
 * Reads JSON from STDIN, validates the node_topology output, and generates an
 * Ansible inventory in INI format.
 *
 * Authoritative production topology (enforced):
 *   node-1 = Validator A + Provider A + build/controller + application provider
 *   node-2 = Validator B + Provider B
 *   node-3 = Validator C + Provider C
 *   node-4 = gateway + application edge (never a validator/provider)
 *
 * node-4 MUST NOT appear in [validators] or [providers]. There is no Validator D.
 */

define('EXIT_SUCCESS', 0);
define('EXIT_INVALID_JSON', 1);
define('EXIT_MISSING_TOPOLOGY', 2);
define('EXIT_VALIDATION_ERROR', 3);

function error($message, $code = EXIT_VALIDATION_ERROR)
{
    fwrite(STDERR, "ERROR: $message\n");
    exit($code);
}

function warning($message)
{
    fwrite(STDERR, "WARNING: $message\n");
}

function validate_hostname($hostname)
{
    return preg_match('/^node-[1-9][0-9]*$/', $hostname) === 1;
}

/**
 * Normalize the role set of a node to a sorted list of Greenwood roles.
 * Supports both the new 'roles' list and the legacy single 'role' string.
 */
function node_roles($node)
{
    if (isset($node['roles']) && is_array($node['roles'])) {
        $roles = array_values(array_filter($node['roles'], 'is_string'));
    } elseif (isset($node['role']) && is_string($node['role']) && $node['role'] !== '') {
        $roles = [$node['role']];
    } else {
        $roles = [];
    }
    $allowed = ['validator', 'provider'];
    $out = array_values(array_unique(array_map('strtolower', $roles)));
    foreach ($out as $r) {
        if (!in_array($r, $allowed, true)) {
            error("Node role '$r' is not a Greenwood protocol role (allowed: validator, provider)");
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

function validate_node($hostname, $node)
{
    $errors = [];
    if (filter_var($node['address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        $errors[] = "Node '$hostname' requires an IPv4 management address";
    }
    if (!preg_match('/^[a-z_][a-z0-9_-]*$/D', $node['ssh_user'] ?? '')) {
        $errors[] = "Node '$hostname' has an invalid SSH account";
    }
    if (($node['architecture'] ?? '') !== 'aarch64') {
        $errors[] = "Node '$hostname' must use aarch64";
    }
    foreach (['address', 'ssh_user', 'architecture'] as $field) {
        if (!isset($node[$field]) || $node[$field] === '') {
            $errors[] = "Node '$hostname' missing required field: $field";
        }
    }

    $roles = node_roles($node);
    $gateway = !empty($node['gateway']);

    if (in_array('validator', $roles, true)) {
        if (!isset($node['validator_label']) || $node['validator_label'] === '') {
            $errors[] = "Node '$hostname' has a validator role but missing validator_label";
        }
    } elseif (isset($node['validator_label']) && $node['validator_label'] !== '') {
        $errors[] = "Node '$hostname' has a validator_label but no validator role";
    }

    if (in_array('provider', $roles, true)) {
        if (!isset($node['provider_label']) || $node['provider_label'] === '') {
            $errors[] = "Node '$hostname' has a provider role but missing provider_label";
        }
    } elseif (isset($node['provider_label']) && $node['provider_label'] !== '') {
        $errors[] = "Node '$hostname' has a provider_label but no provider role";
    }

    if ($gateway && $roles !== []) {
        $errors[] = "Node '$hostname' is a gateway but also carries Greenwood roles: " . implode(',', $roles);
    }

    return $errors;
}

function get_node_number($hostname)
{
    if (preg_match('/^node-(\d+)$/', $hostname, $matches)) {
        return (int) $matches[1];
    }
    return 0;
}

function read_stdin_json()
{
    $input = file_get_contents('php://stdin');
    if ($input === false) {
        error("Failed to read from STDIN");
    }
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error("Invalid JSON input: " . json_last_error_msg(), EXIT_INVALID_JSON);
    }
    return $data;
}

function main()
{
    $data = read_stdin_json();
    if (!isset($data['node_topology'])) {
        error("Missing 'node_topology' in Terraform output", EXIT_MISSING_TOPOLOGY);
    }
    if (!isset($data['node_topology']['value'])) {
        error("Missing 'value' in node_topology output", EXIT_MISSING_TOPOLOGY);
    }
    $nodes = $data['node_topology']['value'];
    if (!is_array($nodes) || count($nodes) !== 4) {
        error("'node_topology' value is empty or not an object", EXIT_MISSING_TOPOLOGY);
    }

    $validation_errors = [];
    $validators = [];
    $providers = [];
    $controllers = [];
    $gateways = [];

    foreach ($nodes as $hostname => $node) {
        if (!validate_hostname($hostname)) {
            $validation_errors[] = "Invalid hostname: $hostname (must match node-<positive integer>)";
            continue;
        }
        foreach (validate_node($hostname, $node) as $err) {
            $validation_errors[] = $err;
        }
    }

    foreach ($nodes as $hostname => $node) {
        if (!validate_hostname($hostname)) {
            continue;
        }
        $roles = node_roles($node);
        if (in_array('validator', $roles, true)) {
            if (empty($node['validator_label'])) {
                $validation_errors[] = "Node '$hostname' validator missing label";
                continue;
            }
            $validators[$node['validator_label']] = $hostname;
        }
        if (in_array('provider', $roles, true)) {
            if (empty($node['provider_label'])) {
                $validation_errors[] = "Node '$hostname' provider missing label";
                continue;
            }
            $providers[$node['provider_label']] = $hostname;
        }
        if (!empty($node['controller'])) {
            $controllers[] = $hostname;
        }
        if (!empty($node['gateway'])) {
            $gateways[] = $hostname;
        }
    }

    ksort($validators, SORT_STRING);
    ksort($providers, SORT_STRING);
    sort($controllers, SORT_STRING);
    sort($gateways, SORT_STRING);

    if ($validators !== ['A' => 'node-1', 'B' => 'node-2', 'C' => 'node-3']) {
        $validation_errors[] = 'Authoritative validator set must be exactly A/B/C, got ' . implode(',', array_keys($validators));
    }
    if (array_keys($providers) !== ['A', 'B', 'C'] || ($providers['A'] ?? '') !== 'node-1' || ($providers['B'] ?? '') !== 'node-2' || ($providers['C'] ?? '') !== 'node-3') {
        $validation_errors[] = 'Authoritative provider set must be A/B/C on nodes 1-3, got ' . json_encode($providers);
    }
    if ($controllers !== ['node-1']) {
        $validation_errors[] = 'Authoritative controller set must be exactly node-1, got ' . implode(',', $controllers);
    }
    if ($gateways !== ['node-4']) {
        $validation_errors[] = 'Authoritative gateway set must be exactly node-4, got ' . implode(',', $gateways);
    }
    if (isset($validators['D'])) {
        $validation_errors[] = 'Validator D is not part of the canonical network; remove node-4 from the validator role';
    }
    $node4 = $nodes['node-4'] ?? null;
    if ($node4 !== null && node_roles($node4) !== []) {
        $validation_errors[] = 'node-4 must have NO Greenwood protocol roles';
    }

    if ($validation_errors) {
        foreach ($validation_errors as $error) {
            fwrite(STDERR, "ERROR: $error\n");
        }
        exit(EXIT_VALIDATION_ERROR);
    }

    $lines = [];
    $lines[] = '# Greenwood Production Ansible Inventory';
    $lines[] = '# Generated from Terraform topology (terraform output -json | php generate_inventory.php)';
    $lines[] = '#';
    $lines[] = '# canonical validator set: A, B, C';
    $lines[] = '# canonical provider set:  A,B,C (nodes 1-3)';
    $lines[] = '# node-4 = gateway/router only (no Greenwood protocol role)';
    $lines[] = '';

    uksort($nodes, function ($a, $b) {
        return get_node_number($a) - get_node_number($b);
    });

    $host = function (string $hostname, array $node) {
        $parts = [$hostname];
        $parts[] = "ansible_host={$node['address']}";
        $parts[] = "ansible_user={$node['ssh_user']}";
        $rolesList = node_roles($node);
        if ($rolesList !== []) {
            $parts[] = 'greenwood_roles=' . implode(',', $rolesList);
        }
        if (!empty($node['validator_label'])) {
            $parts[] = "validator_label={$node['validator_label']}";
        }
        if (!empty($node['provider_label'])) {
            $parts[] = "provider_label={$node['provider_label']}";
        }
        if (!empty($node['gateway_address'])) {
            $parts[] = "gateway_address={$node['gateway_address']}";
        } else {
            $parts[] = 'gateway_address=none';
        }
        if (isset($node['architecture'])) {
            $parts[] = "architecture={$node['architecture']}";
        }
        // Agent forwarding is NOT baked per-host: it is an invocation-level
        // concern. The canonical deploy command sets ANSIBLE_SSH_ARGS with
        // 'ForwardAgent=yes' only when the controller needs private git access.
        return implode(' ', $parts);
    };

    $lines[] = '[controller]';
    foreach ($controllers as $hostname) {
        $lines[] = $host($hostname, $nodes[$hostname]);
    }
    $lines[] = '';
    $lines[] = '[controller:vars]';
    $lines[] = 'ansible_python_interpreter=/usr/bin/python3';
    $lines[] = '';

    $lines[] = '[validators]';
    foreach (['A', 'B', 'C'] as $label) {
        $lines[] = $host($validators[$label], $nodes[$validators[$label]]);
    }
    $lines[] = '';
    $lines[] = '[validators:vars]';
    $lines[] = 'ansible_python_interpreter=/usr/bin/python3';
    $lines[] = '';

    $lines[] = '[providers]';
    foreach (['A', 'B', 'C'] as $label) {
        $lines[] = $host($providers[$label], $nodes[$providers[$label]]);
    }
    $lines[] = '';
    $lines[] = '[providers:vars]';
    $lines[] = 'ansible_python_interpreter=/usr/bin/python3';
    $lines[] = '';

    $lines[] = '[gateway]';
    foreach ($gateways as $hostname) {
        $lines[] = $host($hostname, $nodes[$hostname]);
    }
    $lines[] = '';
    $lines[] = '[gateway:vars]';
    $lines[] = 'ansible_python_interpreter=/usr/bin/python3';
    $lines[] = '';

    $lines[] = '[control_plane]';
    $lines[] = 'node-1';
    $lines[] = 'node-4';
    $lines[] = '';
    $lines[] = '[application_providers]';
    $lines[] = 'node-1';
    $lines[] = '';
    $lines[] = '[application_edges]';
    $lines[] = 'node-4';
    $lines[] = '';
    echo implode("\n", $lines);

    fwrite(STDERR, sprintf("Generated inventory for %d nodes\n", count($nodes)));
    fwrite(STDERR, sprintf("Validators (A/B/C): %d\n", count($validators)));
    fwrite(STDERR, sprintf("Providers (A/B/C): %d\n", count($providers)));
    fwrite(STDERR, sprintf("Controllers (node-1): %d\n", count($controllers)));
    fwrite(STDERR, sprintf("Gateways (node-4): %d\n", count($gateways)));

    return EXIT_SUCCESS;
}

exit(main());
