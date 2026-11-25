#!/usr/bin/env python3
"""Read-only source metadata and narrowly scoped mounted Node 1 resume checks.

Never reads source contents, credential files, iSCSI authentication records, or
configfs authentication. Only public identity/receipt and kernel metadata are
read. Existing storage helpers remain unchanged.
"""
import argparse
import json
import os
from pathlib import Path
import re

import prepare_durable_state as copy
import prepare_state_storage as storage
from storage_plan import CLIENTS, MOUNT, PlanError, copy_manifest, require, sources, validate


MACHINE_ID = Path('/etc/machine-id')


def optional_directory(path):
    """Only the final directory may be absent; every parent must be protected."""
    path = Path(path)
    storage.protected(path.parent, directory=True)
    return storage.protected(path, directory=True, absent=True)


def check_source_metadata(plan, node):
    """Inspect fixed names only, without opening identity or state contents."""
    require(node in CLIENTS and node in plan['clients'], 'STATE_CLIENT_REQUIRED')
    identity = storage.protected(MACHINE_ID)
    require(identity.st_nlink == 1, 'LINKED_MACHINE_ID path=/etc/machine-id')
    storage.protected('/var/lib', directory=True)
    present = {}
    for source in sources(node):
        present[source] = optional_directory(source) is not None

    mount = Path(MOUNT)
    if optional_directory(mount) is not None:
        optional_directory(mount / 'lost+found')
        marker = storage.protected(mount / copy.MARKER, absent=True)
        if marker is not None:
            require(marker.st_nlink == 1 and marker.st_size <= 65536,
                    'INVALID_COMPLETION_MANIFEST_METADATA path=' + str(mount / copy.MARKER))
        for source in sources(node):
            optional_directory(mount / Path(source).name)
    return {'status': 'PASS', 'node': node, 'sources': present}


def check_iscsi_identity(plan, node):
    """Read the public initiator file and default session summary, never auth."""
    client = plan['clients'][node]
    initiator = storage.ISCSI_DIRECTORY / 'initiatorname.iscsi'
    metadata = storage.protected(initiator)
    require(metadata.st_nlink == 1 and metadata.st_size <= 65536,
            'INVALID_INITIATOR_IDENTITY_METADATA')
    names = re.findall(r'^InitiatorName=(\S+)\s*$', initiator.read_text(), re.M)
    require(names == [client['initiator_iqn']], 'INITIATOR_IQN_MISMATCH')
    summary = storage.run(['iscsiadm', '-m', 'session'], allowed=(0, 21))
    require(len(summary) <= 16384, 'INVALID_ISCSI_SESSION_METADATA')
    lines = [line.strip() for line in summary.splitlines() if line.strip()]
    portal = plan['target']['address'] + ':3260,1'
    expression = (r'tcp:\s+\[[1-9][0-9]*\]\s+' + re.escape(portal) + r'\s+'
                  + re.escape(client['target_iqn']) + r'(?:\s+\(non-flash\))?')
    require(len(lines) == 1 and re.fullmatch(expression, lines[0]) is not None,
            'EXACT_APPROVED_ISCSI_SESSION_REQUIRED')


def check_mount_layout(node, mounted):
    """Reject copied-source bindings, nested mounts, and state-volume aliases."""
    mount = Path(MOUNT)
    selected = [Path(source) for source in sources(node)]
    raw = storage.run(['findmnt', '--kernel', '--list', '--json', '--output', 'TARGET,MAJ:MIN,ID'])
    require(len(raw) <= 1048576, 'INVALID_CLIENT_MOUNT_LIST')
    value = json.loads(raw)
    require(isinstance(value, dict) and set(value) == {'filesystems'}
            and isinstance(value['filesystems'], list), 'INVALID_CLIENT_MOUNT_LIST')
    visible = 0
    for record in value['filesystems']:
        require(isinstance(record, dict) and set(record) == {'target', 'maj:min', 'id'}
                and isinstance(record['target'], str) and record['target'].startswith('/')
                and os.path.normpath(record['target']) == record['target']
                and isinstance(record['maj:min'], str)
                and re.fullmatch(r'[0-9]+:[0-9]+', record['maj:min'])
                and type(record['id']) is int and record['id'] > 0, 'INVALID_CLIENT_MOUNT_LIST')
        target = Path(record['target'])
        require(not any(target == source or source in target.parents for source in selected),
                'CANONICAL_SOURCE_MOUNT_REQUIRES_REVIEW')
        require(mount not in target.parents, 'NESTED_STATE_MOUNT_REQUIRES_REVIEW')
        if record['maj:min'] == mounted['maj:min']:
            require(target == mount and record['id'] == mounted['id'],
                    'STATE_VOLUME_ALIAS_REQUIRES_REVIEW')
            visible += 1
        elif target == mount:
            raise PlanError('STATE_MOUNT_STACK_REQUIRES_REVIEW')
    require(visible == 1, 'EXACT_VISIBLE_STATE_MOUNT_REQUIRED')
    volume = storage.protected(mount, directory=True)
    require(f'{os.major(volume.st_dev)}:{os.minor(volume.st_dev)}' == mounted['maj:min'],
            'STATE_MOUNT_DEVICE_CHANGED')
    for source in selected:
        metadata = optional_directory(source)
        require(metadata is None or metadata.st_dev != volume.st_dev,
                'CANONICAL_SOURCE_ON_STATE_VOLUME')


def check_mounted_client(plan, node):
    """Accept only Node 1's mounted, still-empty, never-copied incident state."""
    require(node == 'node-1', 'ONLY_NODE_1_MOUNTED_RESUME_SUPPORTED')
    check_source_metadata(plan, node)
    client = plan['clients'][node]
    storage.machine(client['machine_id'])
    storage.check_network()
    copy.require_stopped()
    check_iscsi_identity(plan, node)
    mounted = storage.filesystem(MOUNT)
    require(mounted['fstype'] == 'ext4' and mounted['uuid'] == client['filesystem_uuid']
            and 'rw' in mounted['options'].split(',') and 'ro' not in mounted['options'].split(','),
            'MOUNTED_CLIENT_VOLUME_MISMATCH')
    device = storage.block_for_uuid(client['filesystem_uuid'], client['device_bytes'])
    block = Path(device).stat()
    require(mounted['maj:min'] == f'{os.major(block.st_rdev)}:{os.minor(block.st_rdev)}',
            'MOUNTED_CLIENT_BLOCK_DEVICE_MISMATCH')
    check_mount_layout(node, mounted)
    result = copy.copy_state(copy_manifest(plan, node), apply=False)
    require(result['status'] == 'READY' and result['copy_state'] == 'not_started',
            'MOUNTED_CLIENT_COPY_ALREADY_STARTED')
    return {'status': 'PASS', 'node': node, 'mounted_client_resume': True,
            'copy_state': 'not_started'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--plan-json', required=True)
    parser.add_argument('--approved-sha256', required=True)
    parser.add_argument('--node', required=True)
    parser.add_argument('--action', choices=('source-metadata', 'mounted-client'), required=True)
    args = parser.parse_args()
    try:
        require(os.geteuid() == 0, 'ROOT_REQUIRED')
        require(len(args.plan_json) <= 65536, 'INVALID_PUBLIC_PLAN')
        plan = validate(json.loads(args.plan_json), args.approved_sha256)
        result = (check_source_metadata(plan, args.node) if args.action == 'source-metadata'
                  else check_mounted_client(plan, args.node))
    except Exception as error:
        # Guard diagnostics contain only fixed public paths and numeric metadata.
        # Never forward arbitrary exception text or subprocess output.
        message = str(error) if isinstance(error, (PlanError, copy.CopyError)) else type(error).__name__
        parser.exit(1, 'STORAGE STOP: ' + message + '; no files or mounts were changed.\n')
    result.update(action=args.action, plan_sha256=args.approved_sha256)
    print(json.dumps(result, separators=(',', ':')))


if __name__ == '__main__':
    main()
