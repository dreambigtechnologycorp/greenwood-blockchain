#!/usr/bin/env python3
"""Check directory ownership and durable local mount metadata; never read state."""
import argparse
import errno
import json
import os
from pathlib import Path
import stat
import subprocess


ALLOWED_FILESYSTEMS = frozenset(('ext4', 'xfs', 'btrfs', 'zfs'))


class StorageError(Exception):
    pass


def require(condition, code):
    if not condition:
        raise StorageError(code)


def canonical_path(value):
    require(isinstance(value, str) and 0 < len(value) <= 4096 and
            value.startswith('/') and not value.startswith('//') and
            all(ord(char) >= 32 and ord(char) != 127 for char in value) and
            os.path.normpath(value) == value, 'INVALID_DIRECTORY_PATH')
    return Path(value)


def metadata_signature(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid)


def existing_ancestor(path, *, lstat=os.lstat, expected_uid=0):
    """Walk from root without resolving symlinks, even for an absent leaf."""
    checked = {}
    nearest = None
    for current in reversed((path, *path.parents)):
        try:
            value = lstat(current)
        except FileNotFoundError:
            require(nearest is not None, 'NO_EXISTING_ANCESTOR')
            break
        require(stat.S_ISDIR(value.st_mode) and value.st_uid == expected_uid and
                not value.st_mode & 0o022, 'UNPROTECTED_DIRECTORY')
        checked[current] = metadata_signature(value)
        nearest = current
    require(nearest is not None, 'NO_EXISTING_ANCESTOR')
    return nearest, checked


def safe_text(value, maximum):
    return (isinstance(value, str) and 0 < len(value) <= maximum and
            all(32 <= ord(char) < 127 for char in value))


def mount_for(path, *, run=subprocess.run):
    result = run(['/usr/bin/findmnt', '--json', '--target', str(path),
                  '--output', 'TARGET,SOURCE,FSTYPE,OPTIONS'],
                 capture_output=True, text=True, timeout=5, check=False)
    require(result.returncode == 0 and len(result.stdout) <= 32768, 'MOUNT_PROBE_FAILED')
    try:
        data = json.loads(result.stdout)
    except (json.JSONDecodeError, TypeError):
        raise StorageError('INVALID_MOUNT_METADATA') from None
    require(isinstance(data, dict) and set(data) == {'filesystems'} and
            isinstance(data['filesystems'], list) and len(data['filesystems']) == 1,
            'AMBIGUOUS_MOUNT_METADATA')
    mount = data['filesystems'][0]
    require(isinstance(mount, dict) and set(mount) == {'target', 'source', 'fstype', 'options'},
            'INVALID_MOUNT_METADATA')
    require(safe_text(mount['source'], 4096) and safe_text(mount['options'], 16384) and
            isinstance(mount['fstype'], str), 'INVALID_MOUNT_METADATA')
    target = canonical_path(mount['target'])
    require(target == path or target in path.parents, 'MOUNT_TARGET_MISMATCH')
    require(mount['fstype'] in ALLOWED_FILESYSTEMS, 'DURABLE_LOCAL_FILESYSTEM_REQUIRED')
    options = mount['options'].split(',')
    require(all(option and not any(char.isspace() for char in option) for option in options) and
            len(options) == len(set(options)), 'INVALID_MOUNT_OPTIONS')
    require('rw' in options and 'ro' not in options, 'WRITABLE_DURABLE_STORAGE_REQUIRED')
    return mount


def validate(paths, *, lstat=os.lstat, run=subprocess.run, expected_uid=0):
    require(isinstance(paths, list) and 0 < len(paths) <= 128, 'INVALID_DIRECTORY_LIST')
    requested = [canonical_path(path) for path in paths]
    require(len(requested) == len(set(requested)), 'DUPLICATE_DIRECTORY_PATH')
    output = []
    for path in requested:
        ancestor, checked = existing_ancestor(path, lstat=lstat, expected_uid=expected_uid)
        mount = mount_for(ancestor, run=run)
        for current, original in checked.items():
            require(metadata_signature(lstat(current)) == original, 'DIRECTORY_CHANGED_DURING_CHECK')
        # If a missing descendant appeared during the mount query, its mount and
        # ownership have not been checked; fail instead of accepting stale facts.
        again, _ = existing_ancestor(path, lstat=lstat, expected_uid=expected_uid)
        require(again == ancestor, 'DIRECTORY_CHANGED_DURING_CHECK')
        output.append({'path': str(path), 'existing_ancestor': str(ancestor), 'mount': mount})
    return {'status': 'PASS', 'paths': output}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--paths-json', required=True)
    args = parser.parse_args()
    try:
        require(os.geteuid() == 0, 'ROOT_REQUIRED')
        require(len(args.paths_json) <= 65536, 'INVALID_DIRECTORY_LIST')
        result = validate(json.loads(args.paths_json))
    except (StorageError, OSError, ValueError, subprocess.SubprocessError) as error:
        if isinstance(error, StorageError):
            code = str(error)
        elif isinstance(error, OSError):
            code = 'OS_' + errno.errorcode.get(error.errno, 'UNKNOWN')
        elif isinstance(error, subprocess.SubprocessError):
            code = 'MOUNT_PROBE_FAILED'
        else:
            code = 'INVALID_INPUT_OR_METADATA'
        parser.exit(1, 'STOP: ' + code + '; no files or mounts were changed.\n')
    print(json.dumps(result, separators=(',', ':')))


if __name__ == '__main__':
    main()
