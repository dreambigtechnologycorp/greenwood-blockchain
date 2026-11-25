#!/usr/bin/env python3
"""Public bounded seven-node durable-state plan; contains no credential values."""
import hashlib
import json
import re

CLIENTS = ('node-1', 'node-2', 'node-3', 'node-5', 'node-6', 'node-7')
ISCSI = ('node-1', 'node-2', 'node-3')
LUN_BYTES = 64 * 1024 ** 3
MOUNT = '/var/lib/greenwood-state'
BACKING = '/mnt/greenwood/block-volumes'


class PlanError(ValueError):
    pass


def require(value, message):
    if not value:
        raise PlanError(message)


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=True).encode()).hexdigest()


def uuid(value):
    return isinstance(value, str) and re.fullmatch(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', value)


def machine(value):
    return isinstance(value, str) and re.fullmatch(r'[a-f0-9]{32}', value)


def sources(node):
    result = ['/var/lib/greenwood-control-plane', '/var/lib/containerd']
    if node in ISCSI:
        result.append('/var/lib/greenwood')
    if node != 'node-5':
        result.append('/var/lib/greenwood-provider')
    return sorted(result)


def validate(plan, approved_sha):
    require(isinstance(plan, dict) and set(plan) == {'version', 'chain_id', 'target', 'clients'}, 'exact public plan fields required')
    require(type(plan['version']) is int and plan['version'] == 1 and plan['chain_id'] == 'greenwood-prod-v1', 'unsupported storage scope')
    require(isinstance(approved_sha, str) and re.fullmatch(r'[a-f0-9]{64}', approved_sha) and digest(plan) == approved_sha, 'public storage plan hash is not approved')
    target = plan['target']
    require(isinstance(target, dict) and set(target) == {'node', 'machine_id', 'address', 'filesystem_uuid', 'backing_directory', 'reserve_free_bytes'}, 'exact target bindings required')
    require(target['node'] == 'node-4' and target['address'] == '192.168.72.103' and target['backing_directory'] == BACKING, 'target outside approved scope')
    require(machine(target['machine_id']) and uuid(target['filesystem_uuid']), 'target machine and existing SSD UUID required')
    require(type(target['reserve_free_bytes']) is int and target['reserve_free_bytes'] >= 128 * 1024 ** 3, 'preserve at least 128 GiB free on shared SSD')
    clients = plan['clients']
    require(isinstance(clients, dict) and set(clients) == set(CLIENTS), 'exact six state clients required')
    ids, uuids, initiators, targets = [target['machine_id']], [target['filesystem_uuid']], [], []
    for node, client in clients.items():
        shared = {'machine_id', 'kind', 'filesystem_uuid', 'device_bytes'}
        expected = shared | ({'expected_existing_iqn', 'initiator_iqn', 'target_iqn', 'chap_file'} if node in ISCSI else {'empty_media_approved'})
        require(isinstance(client, dict) and set(client) == expected, 'exact client media bindings required: ' + node)
        require(machine(client['machine_id']) and uuid(client['filesystem_uuid']), 'client machine and filesystem UUID required: ' + node)
        require(type(client['device_bytes']) is int and client['device_bytes'] > 0, 'exact media capacity required: ' + node)
        ids.append(client['machine_id']); uuids.append(client['filesystem_uuid'])
        if node in ISCSI:
            require(client['kind'] == 'iscsi' and client['device_bytes'] == LUN_BYTES, 'only dedicated 64 GiB LUNs permitted')
            require(client['expected_existing_iqn'] == 'iqn.2004-10.com.ubuntu:01:d066b4bf9ef', 'confirmed previous initiator IQN required')
            require(client['initiator_iqn'] == 'iqn.2026-11.com.greenwoodblockchain:greenwood-prod-v1.' + node + '.initiator', 'explicit unique per-node initiator IQN required')
            require(client['target_iqn'] == 'iqn.2026-11.com.greenwoodblockchain:greenwood-prod-v1.' + node + '.state', 'explicit per-node target IQN required')
            require(client['chap_file'] == '/root/.secrets/greenwood-storage/' + node + '.json', 'dedicated operator-provisioned CHAP file required')
            initiators.append(client['initiator_iqn']); targets.append(client['target_iqn'])
        else:
            require(client['kind'] == 'sd' and type(client['empty_media_approved']) is bool, 'explicit existing SD content-review decision required: ' + node)
            require(client['device_bytes'] >= 25 * 1024 ** 3, 'insufficient existing SD capacity')
    for values in (ids, uuids, initiators, targets):
        require(len(values) == len(set(values)), 'media and machine identities must be unique')
    return plan


def copy_manifest(plan, node):
    client = plan['clients'][node]
    body = {'version': 1, 'node': node, 'expected_machine_id': client['machine_id'],
            'filesystem_uuid': client['filesystem_uuid'], 'mount_path': MOUNT, 'sources': sources(node)}
    return dict(body, plan_sha256=digest(body))
