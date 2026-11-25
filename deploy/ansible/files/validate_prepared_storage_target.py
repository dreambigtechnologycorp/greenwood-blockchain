#!/usr/bin/env python3
"""Read-only check for resuming prepared, never-exported Node 4 backing files."""
import argparse
import json
import os
from pathlib import Path
import stat

import prepare_state_storage as storage
from storage_plan import BACKING, ISCSI, LUN_BYTES, PlanError, require, validate


SSD = Path('/mnt/greenwood')
CONFIGFS_TARGET = Path('/sys/kernel/config/target')


def directory_children(path):
    """Inspect names/types only; never read configfs authentication or state."""
    result = []
    for child in path.iterdir():
        value = child.lstat()
        require(not stat.S_ISLNK(value.st_mode), 'UNEXPECTED_TARGET_METADATA_LINK')
        if stat.S_ISDIR(value.st_mode):
            result.append(child)
    return result


def require_empty_target_configuration():
    try:
        value = CONFIGFS_TARGET.lstat()
    except FileNotFoundError:
        return
    require(stat.S_ISDIR(value.st_mode), 'UNEXPECTED_TARGET_METADATA_TYPE')
    for directory in directory_children(CONFIGFS_TARGET):
        if directory.name == 'core':
            # Reject even an incomplete HBA/backstore from a prior attempt.
            require(all(child.name == 'alua' for child in directory_children(directory)),
                    'EXISTING_BACKSTORES_REQUIRE_REVIEW')
        else:
            # Each fabric's other directories are configured target WWNs.
            # Discovery authentication contents are deliberately not inspected.
            require(all(child.name == 'discovery_auth' for child in directory_children(directory)),
                    'EXISTING_TARGETS_REQUIRE_REVIEW')


def receipt(path, expected, device):
    value = storage.protected(path)
    require(value.st_nlink == 1 and value.st_size <= 4096 and value.st_dev == device,
            'PREPARED_RECEIPT_METADATA_MISMATCH')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'r') as stream:
        opened = os.fstat(stream.fileno())
        require((opened.st_dev, opened.st_ino, opened.st_size, opened.st_nlink)
                == (value.st_dev, value.st_ino, value.st_size, 1), 'PREPARED_RECEIPT_CHANGED')
        text = stream.read(4097)
    require(len(text) <= 4096 and json.loads(text) == expected, 'PREPARED_RECEIPT_MISMATCH')


def check_prepared_target(plan, approved):
    storage.check_target(plan, new=False)
    require(not storage.run(['ss', '-H', '-ltn', 'sport', '=', ':3260']).strip(),
            'ISCSI_PORT_ALREADY_IN_USE')
    require_empty_target_configuration()
    check_backing_files(plan, approved)


def check_backing_files(plan, approved):
    """Validate the original receipts and image identities without target APIs."""
    device = storage.protected(SSD, directory=True).st_dev
    backing = Path(BACKING)
    value = storage.protected(backing, directory=True)
    require(value.st_dev == device, 'PREPARED_BACKING_FILESYSTEM_MISMATCH')
    expected = {'creation.json', 'prepared.json'} | {node + '.img' for node in ISCSI}
    require({path.name for path in backing.iterdir()} == expected,
            'PREPARED_DIRECTORY_CONTENTS_MISMATCH')
    receipt(backing / 'creation.json', {'plan_sha256': approved, 'state': 'creating'}, device)
    receipt(backing / 'prepared.json', {'plan_sha256': approved, 'state': 'prepared'}, device)
    for node in ISCSI:
        path = backing / (node + '.img')
        value = storage.protected(path)
        require(value.st_dev == device, 'PREPARED_BACKING_FILESYSTEM_MISMATCH')
        require(value.st_nlink == 1 and value.st_size == LUN_BYTES, 'BACKING_FILE_MISMATCH')
        require(value.st_blocks * 512 >= LUN_BYTES, 'BACKING_ALLOCATION_NOT_RESERVED')
        require(storage.run(['blkid', '-p', '-s', 'UUID', '-o', 'value', str(path)]).strip()
                == plan['clients'][node]['filesystem_uuid'], 'BACKING_UUID_MISMATCH')
    disk = os.statvfs(SSD)
    require(disk.f_bavail * disk.f_frsize >= plan['target']['reserve_free_bytes'],
            'INSUFFICIENT_SHARED_SSD_RESERVE')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--plan-json'); parser.add_argument('--plan-file')
    parser.add_argument('--approved-sha256', required=True)
    parser.add_argument('--node', required=True)
    parser.add_argument('--action', choices=('check',), required=True)
    args = parser.parse_args()
    try:
        require(os.geteuid() == 0, 'ROOT_REQUIRED')
        require(args.node == 'node-4', 'TARGET_REQUIRED')
        require(bool(args.plan_json) != bool(args.plan_file), 'ONE_PUBLIC_PLAN_REQUIRED')
        if args.plan_file:
            storage.protected(args.plan_file)
        plan = validate(json.loads(args.plan_json or Path(args.plan_file).read_text()), args.approved_sha256)
        storage.protected('/etc/greenwood-storage', directory=True, absent=True)
        for name in ('storage_plan.py', 'prepare_state_storage.py', 'prepare_durable_state.py',
                     'migrate_sqlite_rollback.py', 'plan.json'):
            storage.protected('/etc/greenwood-storage/' + name, absent=True)
        check_prepared_target(plan, args.approved_sha256)
    except Exception as error:
        message = str(error) if isinstance(error, PlanError) else type(error).__name__
        parser.exit(1, 'STORAGE STOP: ' + message + '; preserve existing media and review locally.\n')
    print(json.dumps({'status': 'PASS', 'node': args.node, 'action': args.action,
                      'prepared_target_resume': True, 'plan_sha256': args.approved_sha256}))


if __name__ == '__main__':
    main()
