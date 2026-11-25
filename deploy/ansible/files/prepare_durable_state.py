#!/usr/bin/env python3
"""Operator-only, stopped-service copy to an already mounted approved ext4 volume.

Never formats, mounts, starts services, removes originals, or changes SQLite
journal mode. Source contents stay on the node and are never printed.
"""
import argparse
from contextlib import contextmanager
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess


SOURCES = frozenset('/var/lib/' + name for name in (
    'greenwood', 'greenwood-provider', 'greenwood-control-plane', 'containerd'))
MOUNT = '/var/lib/greenwood-state'
MARKER = '.greenwood-state-copy.json'
FIELDS = {'version', 'node', 'expected_machine_id', 'filesystem_uuid', 'mount_path', 'sources', 'plan_sha256'}


class CopyError(Exception):
    pass


def require(condition, code):
    if not condition:
        raise CopyError(code)


def encoded(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=True).encode()


def validate_plan(plan):
    require(isinstance(plan, dict) and set(plan) == FIELDS and type(plan['version']) is int and
            plan['version'] == 1, 'INVALID_PLAN')
    require(isinstance(plan['node'], str) and re.fullmatch(r'node-[1-7]', plan['node']), 'INVALID_NODE')
    require(isinstance(plan['expected_machine_id'], str) and
            re.fullmatch(r'[a-f0-9]{32}', plan['expected_machine_id']), 'INVALID_MACHINE_ID')
    require(isinstance(plan['filesystem_uuid'], str) and
            re.fullmatch(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', plan['filesystem_uuid']), 'INVALID_UUID')
    require(plan['mount_path'] == MOUNT and isinstance(plan['sources'], list) and
            0 < len(plan['sources']) <= len(SOURCES) and
            all(isinstance(path, str) and path in SOURCES for path in plan['sources']) and
            len(plan['sources']) == len(set(plan['sources'])), 'INVALID_COPY_PATHS')
    digest = hashlib.sha256(encoded({key: value for key, value in plan.items() if key != 'plan_sha256'})).hexdigest()
    require(plan['plan_sha256'] == digest, 'PLAN_HASH_MISMATCH')
    return plan


def protected(path, *, directory=True):
    path = Path(path)
    for current in (path, *path.parents):
        value = current.lstat()
        kind = stat.S_ISDIR if directory or current != path else stat.S_ISREG
        require(kind(value.st_mode) and value.st_uid == 0 and not value.st_mode & 0o022,
                'UNPROTECTED_PATH')
        if current == path and not directory:
            require(value.st_nlink == 1, 'LINKED_MANIFEST')
    return path


def command(argv, *, run=subprocess.run, timeout=30):
    result = run(argv, capture_output=True, text=True, timeout=timeout, check=False)
    require(result.returncode == 0, 'COMMAND_FAILED_' + Path(argv[0]).name.upper())
    return result.stdout


def verify_volume(plan, *, run=subprocess.run, guard=protected, lstat=os.lstat):
    mount = guard(Path(plan['mount_path']))
    result = json.loads(command(['/usr/bin/findmnt', '--json', '--mountpoint', str(mount),
                                 '--output', 'TARGET,SOURCE,FSTYPE,OPTIONS,UUID,MAJ:MIN'], run=run))
    require(isinstance(result, dict) and set(result) == {'filesystems'} and
            isinstance(result['filesystems'], list) and len(result['filesystems']) == 1, 'EXACT_MOUNT_REQUIRED')
    record = result['filesystems'][0]
    require(isinstance(record, dict) and set(record) == {'target', 'source', 'fstype', 'options', 'uuid', 'maj:min'},
            'INVALID_MOUNT_METADATA')
    require(record['target'] == str(mount) and record['fstype'] == 'ext4' and
            record['uuid'] == plan['filesystem_uuid'], 'MOUNT_IDENTITY_MISMATCH')
    require(isinstance(record['options'], str) and 'rw' in record['options'].split(',') and
            'ro' not in record['options'].split(','), 'WRITABLE_VOLUME_REQUIRED')
    device = record['source']
    require(isinstance(device, str) and re.fullmatch(r'/dev/[A-Za-z0-9_./:+-]+', device) and
            os.path.normpath(device) == device, 'INVALID_BLOCK_DEVICE')
    # findmnt may report a /dev/mapper symlink. Resolve only this approved public
    # device reference, then bind its kernel device number and on-disk UUID.
    device = Path(device).resolve(strict=True)
    require(str(device).startswith('/dev/'), 'INVALID_BLOCK_DEVICE')
    meta = lstat(device)
    require(stat.S_ISBLK(meta.st_mode) and record['maj:min'] == f'{os.major(meta.st_rdev)}:{os.minor(meta.st_rdev)}',
            'BLOCK_DEVICE_MISMATCH')
    actual_uuid = command(['/usr/sbin/blkid', '-s', 'UUID', '-o', 'value', '--', str(device)], run=run).strip()
    require(actual_uuid == plan['filesystem_uuid'], 'BLOCK_UUID_MISMATCH')
    return record


def require_stopped(*, run=subprocess.run, proc=Path('/proc')):
    units = command(['/usr/bin/systemctl', 'list-units', '--all', '--plain', '--no-legend', '--no-pager',
                     'greenwood*', 'containerd*'], run=run)
    names = {'containerd.service', 'greenwoodd.service', 'greenwood-provider.service',
             'greenwood-application-recovery.service', 'greenwood-application-recovery.timer'}
    for line in units.splitlines():
        if not line.strip():
            continue
        name = line.split()[0]
        require(re.fullmatch(r'(?:greenwood|containerd)[A-Za-z0-9_.@:-]*\.(?:service|timer|socket|mount)', name),
                'UNEXPECTED_UNIT_LIST')
        if not name.endswith('.mount'):
            names.add(name)
    for name in sorted(names):
        output = command(['/usr/bin/systemctl', 'show', name, '--property=ActiveState', '--property=MainPID'], run=run)
        values = dict(line.split('=', 1) for line in output.splitlines() if '=' in line)
        require(values.get('ActiveState') in ('inactive', 'failed') and
                (values.get('MainPID') == '0' or name.endswith(('.timer', '.socket'))), 'SERVICE_NOT_STOPPED')
    # Read executable-link metadata only, never process arguments or environment.
    for entry in proc.iterdir():
        if entry.name.isdigit():
            try:
                executable = os.readlink(entry / 'exe')
            except (FileNotFoundError, ProcessLookupError):
                continue
            require(not Path(executable).name.startswith(('containerd-shim', 'runc')), 'RUNTIME_PROCESS_STILL_RUNNING')


def root_state(source, *, guard=protected):
    if os.path.lexists(source):
        guard(source)
        return True
    guard(source.parent)
    return False


def reject_nested_mounts(sources, *, run=subprocess.run):
    # Stopped workloads can leave mounted snapshot trees or host bind mounts.
    # Never traverse them and accidentally copy material outside approved roots.
    data = json.loads(command(['/usr/bin/findmnt', '--json', '--list', '--output', 'TARGET'], run=run))
    require(isinstance(data, dict) and set(data) == {'filesystems'} and
            isinstance(data['filesystems'], list), 'INVALID_MOUNT_LIST')
    for record in data['filesystems']:
        require(isinstance(record, dict) and set(record) == {'target'} and
                isinstance(record['target'], str) and record['target'].startswith('/'), 'INVALID_MOUNT_LIST')
        target = Path(record['target'])
        require(not any(Path(source) in target.parents for source in sources), 'NESTED_SOURCE_MOUNT_REQUIRES_REVIEW')


def tree_digest(root):
    """Opaque content/metadata proof; symlinks are hashed, never followed."""
    if not os.path.lexists(root):
        return 'absent'
    digest = hashlib.sha256()
    hardlinks = {}

    def visit(path, relative):
        before = path.lstat()
        mode = stat.S_IFMT(before.st_mode)
        record = [relative, mode, stat.S_IMODE(before.st_mode), before.st_uid, before.st_gid,
                  before.st_mtime_ns]
        if stat.S_ISREG(mode):
            identity = (before.st_dev, before.st_ino)
            record.extend([before.st_size, hardlinks.setdefault(identity, relative)])
            fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
            with os.fdopen(fd, 'rb') as stream:
                opened = os.fstat(stream.fileno())
                require((opened.st_dev, opened.st_ino) == identity, 'SOURCE_CHANGED_DURING_COPY')
                content = hashlib.sha256()
                for chunk in iter(lambda: stream.read(1048576), b''):
                    content.update(chunk)
            record.append(content.hexdigest())
        elif stat.S_ISLNK(mode):
            record.append(os.readlink(path))
        elif not stat.S_ISDIR(mode):
            raise CopyError('UNSUPPORTED_STATE_FILE_TYPE')
        attributes = []
        for name in sorted(os.listxattr(path, follow_symlinks=False)):
            attributes.append((name, hashlib.sha256(os.getxattr(path, name, follow_symlinks=False)).hexdigest()))
        record.append(attributes)
        digest.update(encoded(record) + b'\n')
        if stat.S_ISDIR(mode):
            for child in sorted(path.iterdir(), key=lambda item: item.name):
                visit(child, relative + '/' + child.name)
        after = path.lstat()
        require((before.st_dev, before.st_ino, before.st_mode, before.st_size, before.st_mtime_ns, before.st_ctime_ns) ==
                (after.st_dev, after.st_ino, after.st_mode, after.st_size, after.st_mtime_ns, after.st_ctime_ns),
                'SOURCE_CHANGED_DURING_COPY')
    visit(Path(root), '.')
    return digest.hexdigest()


def read_marker(mount, plan, *, guard=protected):
    path = mount / MARKER
    if not os.path.lexists(path):
        return None
    guard(path, directory=False)
    require(path.stat().st_size <= 65536, 'INVALID_COMPLETION_MANIFEST')
    marker = json.loads(path.read_bytes())
    require(isinstance(marker, dict) and set(marker) == {'plan', 'source_digests', 'state'} and
            marker['plan'] == plan and marker['state'] in ('copying', 'complete') and
            isinstance(marker['source_digests'], dict) and set(marker['source_digests']) == set(plan['sources']) and
            all(value == 'absent' or isinstance(value, str) and re.fullmatch(r'[a-f0-9]{64}', value)
                for value in marker['source_digests'].values()), 'COPY_MANIFEST_MISMATCH')
    return marker


def check_destination(mount, plan, marker, *, guard=protected):
    permitted = {'lost+found'}
    if marker is not None:
        permitted |= {MARKER} | {Path(source).name for source in plan['sources']}
    require(set(path.name for path in mount.iterdir()) <= permitted, 'DESTINATION_NOT_EMPTY_OR_OWNED')
    lost = mount / 'lost+found'
    if os.path.lexists(lost):
        guard(lost)
        require(not any(lost.iterdir()), 'RECOVERED_FILES_REQUIRE_REVIEW')
    for source in plan['sources']:
        destination = mount / Path(source).name
        if os.path.lexists(destination):
            guard(destination)
            require(not os.path.exists(source) or not os.path.samefile(source, destination), 'SOURCE_ALREADY_BOUND')


def write_marker(fd, value):
    name = MARKER + '.writing'
    out = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=fd)
    with os.fdopen(out, 'wb') as stream:
        stream.write(encoded(value) + b'\n')
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(name, MARKER, src_dir_fd=fd, dst_dir_fd=fd)
    os.fsync(fd)


def sync_copied_trees(names, volume_fd):
    """Persist every accepted file and directory before the completion receipt.

    rsync --fsync covers newly transferred files. This also covers skipped files
    from a matching interrupted copy and directory entries, without syncing any
    retained original or following symlinks outside the anchored destination.
    """
    device = os.fstat(volume_fd).st_dev
    for name in names:
        require(Path(name).name == name and name not in ('', '.', '..'), 'INVALID_DESTINATION_NAME')
        for directory, _, files in os.walk(name, topdown=False, followlinks=False):
            for filename in files:
                path = Path(directory) / filename
                before = path.lstat()
                if stat.S_ISLNK(before.st_mode):
                    continue
                require(stat.S_ISREG(before.st_mode) and before.st_dev == device, 'UNEXPECTED_DESTINATION_FILE')
                fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
                try:
                    current = os.fstat(fd)
                    require((current.st_dev, current.st_ino) == (before.st_dev, before.st_ino),
                            'DESTINATION_CHANGED_DURING_SYNC')
                    os.fsync(fd)
                finally:
                    os.close(fd)
            fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                require(os.fstat(fd).st_dev == device, 'DESTINATION_OUTSIDE_VOLUME')
                os.fsync(fd)
            finally:
                os.close(fd)
    os.fsync(volume_fd)


@contextmanager
def anchored_volume(mount):
    prior = os.open('.', os.O_RDONLY | os.O_DIRECTORY)
    fd = os.open(mount, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise CopyError('ANOTHER_STATE_COPY_IS_RUNNING') from None
        # Keeping cwd on the verified mounted inode prevents normal unmount;
        # a lazy unmount cannot redirect relative destinations into the NFS root.
        os.fchdir(fd)
        yield fd
    finally:
        os.fchdir(prior)
        os.close(fd)
        os.close(prior)


def copy_state(plan, *, apply=False, guard=protected, verify=verify_volume,
               stopped=require_stopped, run=subprocess.run, machine_id_path=Path('/etc/machine-id')):
    validate_plan(plan)
    guard(machine_id_path, directory=False)
    require(machine_id_path.read_text().strip() == plan['expected_machine_id'], 'MACHINE_ID_MISMATCH')
    stopped()
    verify(plan)
    mount = Path(plan['mount_path'])
    marker = read_marker(mount, plan, guard=guard)
    check_destination(mount, plan, marker, guard=guard)
    exists = {source: root_state(Path(source), guard=guard) for source in plan['sources']}
    reject_nested_mounts(plan['sources'], run=run)
    if not apply:
        return {'status': 'READY', 'node': plan['node'], 'plan_sha256': plan['plan_sha256'],
                'copy_state': marker['state'] if marker else 'not_started', 'sources': exists}
    originals = {source: tree_digest(Path(source)) for source in plan['sources']}
    if marker is not None:
        require(marker['source_digests'] == originals, 'RETAINED_SOURCE_CHANGED')
    else:
        marker = {'plan': plan, 'source_digests': originals, 'state': 'copying'}
    with anchored_volume(mount) as fd:
        mount_identity = os.fstat(fd)
        verify(plan)
        require(os.stat(mount).st_dev == mount_identity.st_dev and os.stat(mount).st_ino == mount_identity.st_ino,
                'MOUNT_CHANGED_DURING_COPY')
        current = read_marker(mount, plan, guard=guard)
        check_destination(mount, plan, current, guard=guard)
        if current is not None:
            require(current['source_digests'] == originals, 'RETAINED_SOURCE_CHANGED')
            marker = current
        if marker['state'] == 'copying':
            write_marker(fd, marker)
            for source, present in exists.items():
                if not present:
                    name = Path(source).name
                    if not os.path.lexists(name):
                        os.mkdir(name, mode=0o700, dir_fd=fd)
            selected = [source for source, present in exists.items() if present]
            if selected:
                command(['/usr/bin/rsync', '-aHAX', '--numeric-ids', '--checksum', '--modify-window=-1', '--fsync', '--protect-args', '--',
                         *selected, '.'], run=run, timeout=86400)
        stopped()
        verify(plan)
        reject_nested_mounts(plan['sources'], run=run)
        require({source: tree_digest(Path(source)) for source in plan['sources']} == originals,
                'SOURCE_CHANGED_DURING_COPY')
        # Verify each selected tree including deletion candidates, but never
        # perform deletion. Unknown destination entries cause a hard stop.
        for source, present in exists.items():
            destination = Path(source).name
            if present:
                differences = command(['/usr/bin/rsync', '-aHAX', '--numeric-ids', '--checksum', '--modify-window=-1', '--dry-run',
                                       '--itemize-changes', '--delete', '--protect-args', '--', source + '/',
                                       destination + '/'], run=run, timeout=86400)
                require(differences == '', 'COPY_VERIFICATION_FAILED')
            else:
                require(not any(Path(destination).iterdir()), 'UNEXPECTED_NEW_STATE')
        sync_copied_trees([Path(source).name for source in plan['sources']], fd)
        marker['state'] = 'complete'
        write_marker(fd, marker)
    return {'status': 'COPIED_AND_VERIFIED', 'node': plan['node'], 'plan_sha256': plan['plan_sha256'],
            'manifest_sha256': hashlib.sha256(encoded(marker) + b'\n').hexdigest(), 'originals_retained': True}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--plan-json', required=True)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--apply', action='store_true')
    parser.add_argument('--approve-state-copy', action='store_true')
    args = parser.parse_args()
    try:
        require(os.geteuid() == 0, 'ROOT_REQUIRED')
        require(not args.apply or args.approve_state_copy, 'STATE_COPY_APPROVAL_REQUIRED')
        require(len(args.plan_json) <= 65536, 'INVALID_PLAN')
        result = copy_state(json.loads(args.plan_json), apply=args.apply)
    except (CopyError, OSError, ValueError, subprocess.SubprocessError):
        # Exceptions from rsync, JSON and system probes must not disclose state
        # filenames, service arguments, database rows or command output.
        import sys
        error = sys.exc_info()[1]
        code = str(error) if isinstance(error, CopyError) else 'STATE_COPY_INSPECTION_FAILED'
        parser.exit(1, 'STOP: ' + code + '; originals and partial copies are retained.\n')
    print(json.dumps(result, separators=(',', ':')))


if __name__ == '__main__':
    main()
