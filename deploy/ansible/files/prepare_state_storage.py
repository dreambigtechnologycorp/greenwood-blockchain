#!/usr/bin/env python3
"""Operator-only bounded storage preparation. Never formats existing block media."""
import argparse
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys

from storage_plan import BACKING, CLIENTS, ISCSI, LUN_BYTES, MOUNT, PlanError, digest, require, validate

ISCSI_DIRECTORY = Path('/etc/iscsi')
ALTERNATE_ISCSI_DIRECTORY = Path('/var/lib/iscsi')


def run(args, *, allowed=(0,)):
    result = subprocess.run(args, capture_output=True, text=True, timeout=120)
    require(result.returncode in allowed, 'COMMAND_FAILED: ' + Path(args[0]).name)
    return result.stdout


def protected(path, *, absent=False, directory=False):
    path = Path(path)
    require(path.is_absolute() and str(path) == os.path.normpath(str(path)), 'UNSAFE_PATH')
    for part in reversed((path, *path.parents)):
        try:
            value = part.lstat()
        except FileNotFoundError:
            require(absent, 'REQUIRED_PATH_MISSING path=' + str(part))
            return
        except OSError as error:
            raise PlanError(f'METADATA_UNAVAILABLE path={part} errno={error.errno}') from None
        kind = ('symlink' if stat.S_ISLNK(value.st_mode) else
                'directory' if stat.S_ISDIR(value.st_mode) else
                'regular-file' if stat.S_ISREG(value.st_mode) else 'other')
        metadata = (f'path={part} uid={value.st_uid} gid={value.st_gid} '
                    f'mode={stat.S_IMODE(value.st_mode):04o} type={kind} links={value.st_nlink}')
        require(not stat.S_ISLNK(value.st_mode) and value.st_uid == 0 and not value.st_mode & 0o022,
                'UNPROTECTED_PATH ' + metadata)
        require(stat.S_ISDIR(value.st_mode) if part != path or directory else stat.S_ISREG(value.st_mode),
                'UNEXPECTED_PATH_TYPE ' + metadata)
    return value


def machine(expected):
    require(Path('/etc/machine-id').read_text().strip() == expected, 'MACHINE_ID_MISMATCH')


def descriptor_mount_id(fd):
    # This is kernel mount metadata for our own directory descriptor, never
    # state, credentials or another process's arguments/environment.
    entries = re.findall(r'^mnt_id:\s*([0-9]+)\s*$', Path('/proc/self/fdinfo/' + str(fd)).read_text(), re.M)
    require(len(entries) == 1 and int(entries[0]) > 0, 'MOUNT_DESCRIPTOR_ID_UNAVAILABLE')
    return int(entries[0])


def filesystem(path):
    path = str(path)
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        opened = os.fstat(fd)
        mount_id = descriptor_mount_id(fd)
        raw = run(['findmnt', '--kernel', '--list', '--json', '--mountpoint', path,
                   '--output', 'TARGET,SOURCE,FSTYPE,UUID,OPTIONS,ID,MAJ:MIN,FSROOT'])
        require(len(raw) <= 131072, 'INVALID_MOUNT_METADATA')
        value = json.loads(raw)
        mounts = value.get('filesystems') if isinstance(value, dict) else None
        require(isinstance(mounts, list) and mounts and all(isinstance(item, dict) and
                type(item.get('id')) is int and item['id'] > 0 for item in mounts), 'INVALID_MOUNT_METADATA')
        # util-linux 2.39 reports both systemd autofs and the mounted filesystem.
        # Select the actually opened mount by its kernel ID, never list order.
        matches = [item for item in mounts if item['id'] == mount_id]
        require(len(matches) == 1, 'VISIBLE_MOUNT_NOT_UNIQUE')
        selected = matches[0]
        require(selected.get('target') == path and selected.get('fsroot') == '/', 'EXACT_FILESYSTEM_ROOT_REQUIRED')
        require(selected.get('maj:min') == f'{os.major(opened.st_dev)}:{os.minor(opened.st_dev)}',
                'VISIBLE_MOUNT_DEVICE_MISMATCH')
        require(all(isinstance(selected.get(key), str) and selected[key]
                    for key in ('source', 'fstype', 'options')), 'INVALID_MOUNT_METADATA')
        # A mount change during the query must not turn hidden metadata into
        # authority for the path used by subsequent storage checks.
        current_fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            current = os.fstat(current_fd)
            require(descriptor_mount_id(current_fd) == mount_id and
                    (current.st_dev, current.st_ino) == (opened.st_dev, opened.st_ino),
                    'MOUNT_CHANGED_DURING_INSPECTION')
        finally:
            os.close(current_fd)
        return selected
    finally:
        os.close(fd)


def chap(path, *, read=False):
    value = protected(path)
    require(stat.S_IMODE(value.st_mode) == 0o600 and value.st_nlink == 1 and value.st_size <= 4096,
            'PROTECTED_CHAP_FILE_REQUIRED')
    if not read:
        return None
    obj = json.loads(Path(path).read_text())
    require(isinstance(obj, dict) and set(obj) == {'username', 'password'}, 'INVALID_CHAP_FILE')
    require(isinstance(obj['username'], str) and re.fullmatch(r'[A-Za-z0-9_-]{1,64}', obj['username']), 'INVALID_CHAP_USERNAME')
    require(isinstance(obj['password'], str) and re.fullmatch(r'[A-Za-z0-9_-]{16,64}', obj['password']), 'INVALID_CHAP_PASSWORD')
    return obj


def offline_services(*, include_containerd=False):
    active = run(['systemctl', 'list-units', '--state=active,activating,reloading', '--no-legend', '--no-pager', 'greenwood*'])
    lines = [line.split()[0] for line in active.splitlines() if line.strip()]
    # This already-applied networking oneshot has no process or mutable state.
    # Apply marks it stopped after proving the container runtime is empty.
    require(set(lines) <= {'greenwood-application-forwarding.service'}, 'GREENWOOD_SERVICES_MUST_BE_STOPPED')
    if lines:
        state = run(['systemctl', 'show', lines[0], '-p', 'SubState', '-p', 'MainPID'])
        require('SubState=exited' in state.splitlines() and 'MainPID=0' in state.splitlines(), 'FORWARDING_PROCESS_MUST_BE_STOPPED')
    state = run(['systemctl', 'show', 'containerd', '--property=ActiveState', '--property=MainPID'])
    inactive = ('ActiveState=inactive' in state.splitlines() or 'ActiveState=failed' in state.splitlines()) and 'MainPID=0' in state.splitlines()
    if include_containerd:
        require('ActiveState=inactive' in state.splitlines() and 'MainPID=0' in state.splitlines(), 'CONTAINERD_MUST_BE_STOPPED')
    if inactive:
        for comm in Path('/proc').glob('[0-9]*/comm'):
            try:
                require(not comm.read_text().strip().startswith('containerd-shim'), 'CONTAINERD_SHIM_PRESENT')
            except FileNotFoundError:
                pass
    else:
        require('ActiveState=active' in state.splitlines(), 'UNCERTAIN_CONTAINERD_STATE')
        for namespace in run(['ctr', 'namespaces', 'list', '-q']).splitlines():
            require(re.fullmatch(r'[A-Za-z0-9_.-]+', namespace), 'UNEXPECTED_CONTAINERD_NAMESPACE')
            for resource in ('tasks', 'containers'):
                require(not run(['ctr', '-n', namespace, resource, 'list', '-q']).strip(), 'CONTAINERD_WORKLOADS_PRESENT')


def check_network():
    for service in ('systemd-networkd.service', 'ssh.service'):
        run(['systemctl', 'is-active', '--quiet', service])
    require(run(['systemctl', 'show', 'systemd-networkd.service', '-p', 'RefuseManualStop', '--value']).strip() == 'yes', 'NETWORK_RETENTION_GUARD_REQUIRED')
    require(not run(['dpkg', '--audit']).strip(), 'PACKAGE_AUDIT_NOT_CLEAN')


def unused_iscsi_records():
    # Ubuntu 24.04's packaged DBROOT is /etc/iscsi. Dormant records can log in
    # automatically on reboot even when there are no current sessions.
    for base in (ISCSI_DIRECTORY, ALTERNATE_ISCSI_DIRECTORY):
        directory = base / 'nodes'
        protected(directory, directory=True, absent=True)
        require(not directory.exists() or not any(directory.iterdir()), 'EXISTING_ISCSI_NODE_RECORDS_REQUIRE_REVIEW')


def block_for_uuid(expected, size):
    devices = run(['blkid', '-t', 'UUID=' + expected, '-o', 'device']).splitlines()
    require(len(devices) == 1, 'FILESYSTEM_UUID_NOT_UNIQUE')
    device = Path(devices[0])
    require(str(device).startswith('/dev/') and stat.S_ISBLK(device.stat().st_mode), 'BLOCK_DEVICE_REQUIRED')
    require(int(run(['blockdev', '--getsize64', str(device)]).strip()) == size, 'BLOCK_DEVICE_CAPACITY_MISMATCH')
    require(run(['blkid', '-s', 'TYPE', '-o', 'value', str(device)]).strip() == 'ext4', 'EXISTING_EXT4_REQUIRED')
    return str(device)


def empty_media(device):
    require(not run(['findmnt', '--noheadings', '--source', device], allowed=(0, 1)).strip(), 'MEDIA_ALREADY_MOUNTED')
    listing = run(['debugfs', '-R', 'ls -p /', device])
    names = []
    for line in listing.splitlines():
        fields = line.split('/')
        if len(fields) >= 7 and fields[1].isdigit():
            names.append(fields[5])
    require(names and set(names) <= {'.', '..', 'lost+found'}, 'EXISTING_MEDIA_CONTENT_REQUIRES_REVIEW')
    if 'lost+found' in names:
        recovered = run(['debugfs', '-R', 'ls -p /lost+found', device])
        recovered_names = [line.split('/')[5] for line in recovered.splitlines()
                           if len(line.split('/')) >= 7 and line.split('/')[1].isdigit()]
        require(set(recovered_names) <= {'.', '..', ''}, 'RECOVERED_MEDIA_CONTENT_REQUIRES_REVIEW')
    superblock = run(['dumpe2fs', '-h', device])
    require(re.search(r'^Filesystem state:\s+clean\s*$', superblock, re.M), 'FILESYSTEM_REQUIRES_OFFLINE_REVIEW')


def check_target(plan, *, new=True):
    target = plan['target']; machine(target['machine_id'])
    protected('/mnt/greenwood', directory=True)
    require(not run(['dpkg', '--audit']).strip(), 'PACKAGE_AUDIT_NOT_CLEAN')
    for service in ('ssh.service', 'systemd-networkd.service', 'nfs-server.service'):
        run(['systemctl', 'is-active', '--quiet', service])
    require(run(['systemctl', 'show', 'systemd-networkd.service', '-p', 'RefuseManualStop', '--value']).strip() == 'yes', 'TARGET_NETWORK_RETENTION_GUARD_REQUIRED')
    fs = filesystem('/mnt/greenwood')
    require(fs['target'] == '/mnt/greenwood' and fs['fstype'] == 'ext4' and fs['uuid'] == target['filesystem_uuid']
            and 'rw' in fs['options'].split(','), 'SHARED_SSD_IDENTITY_MISMATCH')
    for line in run(['exportfs', '-v']).splitlines():
        if line.startswith('/'):
            exported = line.split()[0].rstrip('/') or '/'
            require(not (BACKING == exported or BACKING.startswith(exported.rstrip('/') + '/')
                         or exported.startswith(BACKING + '/')),
                    'BACKING_DIRECTORY_EXPOSED_BY_NFS')
    protected(BACKING, directory=True, absent=True)
    if new:
        require(not Path(BACKING).exists(), 'BACKING_DIRECTORY_ALREADY_EXISTS_REVIEW_REQUIRED')
        require(not run(['ss', '-H', '-ltn', 'sport', '=', ':3260']).strip(), 'ISCSI_PORT_ALREADY_IN_USE')
        root = Path('/sys/kernel/config/target')
        require(not root.exists() or not any(root.glob('iscsi/iqn.*')), 'EXISTING_TARGETS_REQUIRE_REVIEW')
        require(not root.exists() or not any(root.glob('core/*/*/udev_path')), 'EXISTING_BACKSTORES_REQUIRE_REVIEW')
        disk = os.statvfs('/mnt/greenwood')
        require(disk.f_bavail * disk.f_frsize >= 3 * LUN_BYTES + target['reserve_free_bytes'], 'INSUFFICIENT_SHARED_SSD_RESERVE')
    for node in ISCSI:
        chap(plan['clients'][node]['chap_file'])


def check_client(plan, node, *, connected=False):
    client = plan['clients'][node]; machine(client['machine_id']); check_network(); offline_services()
    protected(MOUNT, directory=True, absent=True)
    require(not Path(MOUNT).exists(), 'STATE_VOLUME_ALREADY_STAGED_REVIEW_REQUIRED')
    if node in ISCSI:
        initiator_file = ISCSI_DIRECTORY / 'initiatorname.iscsi'
        protected(initiator_file)
        names = re.findall(r'^InitiatorName=(\S+)\s*$', initiator_file.read_text(), re.M)
        require(names in ([client['expected_existing_iqn']], [client['initiator_iqn']]), 'INITIATOR_IQN_MISMATCH')
        chap(client['chap_file'])
        if not connected:
            sessions = run(['iscsiadm', '-m', 'session'], allowed=(0, 21))
            require(not sessions.strip(), 'EXISTING_ISCSI_SESSION_REQUIRES_REVIEW')
            unused_iscsi_records()
            return
    device = block_for_uuid(client['filesystem_uuid'], client['device_bytes'])
    empty_media(device)


def write_private_json(path, value):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as stream:
        json.dump(value, stream, sort_keys=True, separators=(',', ':')); stream.write('\n'); stream.flush(); os.fsync(stream.fileno())


def create_backing(plan, approved):
    check_target(plan)
    os.mkdir(BACKING, 0o700)
    parent = os.open(str(Path(BACKING).parent), os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(parent)
    finally:
        os.close(parent)
    write_private_json(BACKING + '/creation.json', {'plan_sha256': approved, 'state': 'creating'})
    for node in ISCSI:
        client = plan['clients'][node]
        file = BACKING + '/' + node + '.img'
        fd = os.open(file, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            os.posix_fallocate(fd, 0, LUN_BYTES); os.fsync(fd)
            require(os.fstat(fd).st_size == LUN_BYTES and os.fstat(fd).st_nlink == 1, 'NEW_FILE_IDENTITY_CHANGED')
            identity = (os.fstat(fd).st_dev, os.fstat(fd).st_ino)
        finally:
            os.close(fd)
        # Force is confined to an O_EXCL file this invocation just allocated.
        # No existing block device or existing file is ever passed to mkfs.
        run(['mkfs.ext4', '-F', '-E', 'nodiscard', '-U', client['filesystem_uuid'], '-L', 'gw-' + node + '-state', file])
        fd = os.open(file, os.O_RDONLY | os.O_NOFOLLOW)
        try:
            value = os.fstat(fd)
            require((value.st_dev, value.st_ino) == identity and value.st_size == LUN_BYTES
                    and value.st_nlink == 1, 'FORMATTED_FILE_IDENTITY_CHANGED')
            require(value.st_blocks * 512 >= LUN_BYTES, 'BACKING_ALLOCATION_NOT_RESERVED')
            os.fsync(fd)
        finally:
            os.close(fd)
        require(run(['blkid', '-p', '-s', 'UUID', '-o', 'value', file]).strip() == client['filesystem_uuid'], 'NEW_FILESYSTEM_UUID_MISMATCH')
    write_private_json(BACKING + '/prepared.json', {'plan_sha256': approved, 'state': 'prepared'})
    directory = os.open(BACKING, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)


def configure_target(plan, approved):
    check_target(plan, new=False)
    protected(BACKING + '/prepared.json')
    require(json.loads(Path(BACKING + '/prepared.json').read_text()) == {'plan_sha256': approved, 'state': 'prepared'}, 'UNREVIEWED_TARGET_FILES')
    # Import only in the operator apply path: RTSRoot may initialize configfs.
    from rtslib_fb import RTSRoot, FabricModule, FileIOStorageObject, Target, TPG, LUN, NodeACL, MappedLUN, NetworkPortal
    root = RTSRoot()
    require(not list(root.targets) and not list(root.storage_objects), 'TARGET_CONFIGURATION_NOT_EMPTY')
    objects = []
    for node in ISCSI:
        client = plan['clients'][node]; file = BACKING + '/' + node + '.img'
        value = protected(file)
        require(value.st_size == LUN_BYTES and value.st_nlink == 1, 'BACKING_FILE_MISMATCH')
        require(value.st_blocks * 512 >= LUN_BYTES, 'BACKING_ALLOCATION_NOT_RESERVED')
        require(run(['blkid', '-p', '-s', 'UUID', '-o', 'value', file]).strip() == client['filesystem_uuid'], 'BACKING_UUID_MISMATCH')
        auth = chap(client['chap_file'], read=True)
        # Persist the SCSI unit serial across target reboots using the already
        # approved filesystem UUID; rtslib otherwise generates a new serial.
        storage = FileIOStorageObject('greenwood-' + node, dev=file, size=LUN_BYTES,
                                      wwn=client['filesystem_uuid'], write_back=False)
        storage.set_attribute('emulate_write_cache', 0)
        target = Target(FabricModule('iscsi'), client['target_iqn'], mode='create')
        tpg = TPG(target, 1, mode='create'); tpg.enable = False
        tpg.set_attribute('generate_node_acls', 0); tpg.set_attribute('cache_dynamic_acls', 0)
        tpg.set_attribute('demo_mode_write_protect', 1); tpg.set_attribute('authentication', 1)
        lun = LUN(tpg, 0, storage)
        acl = NodeACL(tpg, client['initiator_iqn'], mode='create')
        acl.chap_userid = auth['username']; acl.chap_password = auth['password']
        MappedLUN(acl, 0, lun, write_protect=False)
        NetworkPortal(tpg, plan['target']['address'], 3260, mode='create')
        objects.append(tpg)
    # No wildcard ACL, discovery CHAP or shared LUN; one node per target/LUN.
    for tpg in objects:
        tpg.enable = True


def connect_client(plan, node):
    client = plan['clients'][node]
    require(node in ISCSI, 'ISCSI_CLIENT_REQUIRED')
    machine(client['machine_id'])
    require(not run(['iscsiadm', '-m', 'session'], allowed=(0, 21)).strip(), 'EXISTING_ISCSI_SESSION_REQUIRES_REVIEW')
    unused_iscsi_records()
    initiator_file = ISCSI_DIRECTORY / 'initiatorname.iscsi'; protected(initiator_file)
    old = initiator_file.read_text()
    names = re.findall(r'^InitiatorName=(\S+)\s*$', old, re.M)
    require(names in ([client['expected_existing_iqn']], [client['initiator_iqn']]), 'INITIATOR_IQN_MISMATCH')
    if names != [client['initiator_iqn']]:
        backup = Path('/etc/greenwood-storage/previous-initiatorname.iscsi')
        require(not backup.exists(), 'INITIATOR_BACKUP_ALREADY_EXISTS_REVIEW_REQUIRED')
        fd = os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'w') as stream:
            stream.write(old); stream.flush(); os.fsync(stream.fileno())
        replacement = re.sub(r'^InitiatorName=\S+\s*$', 'InitiatorName=' + client['initiator_iqn'], old, flags=re.M)
        temp = ISCSI_DIRECTORY / '.greenwood-initiator.new'
        fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'w') as stream:
            stream.write(replacement + '\n'); stream.flush(); os.fsync(stream.fileno())
        os.replace(temp, initiator_file)
        # No sessions exist. Restart only the iSCSI daemon to load its unique
        # public InitiatorName; never restart networkd, NFS, SSH or cloudflared.
        run(['systemctl', 'restart', 'iscsid.service'])
    auth = chap(client['chap_file'], read=True)
    # TPGT 1 is the exact portal group created above. Without it iscsiadm
    # creates a legacy flat record, not the protected default-interface record.
    portal = plan['target']['address'] + ':3260,1'
    existing = run(['iscsiadm', '-m', 'node', '-T', client['target_iqn'], '-p', portal], allowed=(0, 21))
    require(not existing.strip(), 'EXISTING_ISCSI_NODE_RECORD_REQUIRES_REVIEW')
    run(['iscsiadm', '-m', 'node', '-T', client['target_iqn'], '-p', portal, '-I', 'default', '--op', 'new'])
    records = list((ISCSI_DIRECTORY / 'nodes' / client['target_iqn']).glob('*/*'))
    expected = ISCSI_DIRECTORY / 'nodes' / client['target_iqn'] / (plan['target']['address'] + ',3260,1') / 'default'
    require(records == [expected], 'AMBIGUOUS_ISCSI_NODE_RECORD')
    record = records[0]; protected(record)
    text = record.read_text()
    updates = {'node.session.auth.authmethod': 'CHAP', 'node.session.auth.username': auth['username'],
               'node.session.auth.password': auth['password'], 'node.startup': 'automatic',
               'node.conn[0].startup': 'automatic'}
    for key, value in updates.items():
        expression = r'^' + re.escape(key) + r'\s*=.*$'
        require(len(re.findall(expression, text, re.M)) <= 1, 'AMBIGUOUS_ISCSI_SETTING')
        text = re.sub(expression, key + ' = ' + value, text, flags=re.M) if re.search(expression, text, re.M) else text + '\n' + key + ' = ' + value + '\n'
    fd = os.open(record, os.O_WRONLY | os.O_TRUNC | os.O_NOFOLLOW)
    with os.fdopen(fd, 'w') as stream:
        os.fchmod(stream.fileno(), 0o600); stream.write(text); stream.flush(); os.fsync(stream.fileno())
    run(['iscsiadm', '-m', 'node', '-T', client['target_iqn'], '-p', portal, '-I', 'default', '--login'])
    run(['udevadm', 'settle', '--timeout=30'])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--plan-json'); parser.add_argument('--plan-file')
    parser.add_argument('--approved-sha256', required=True)
    parser.add_argument('--node', required=True)
    parser.add_argument('--action', choices=('check', 'connected-check', 'create-target-files', 'start-target', 'connect-client'), required=True)
    parser.add_argument('--approve-storage-changes', action='store_true')
    args = parser.parse_args()
    try:
        require(os.geteuid() == 0, 'ROOT_REQUIRED')
        require(bool(args.plan_json) != bool(args.plan_file), 'ONE_PUBLIC_PLAN_REQUIRED')
        if args.plan_file:
            protected(args.plan_file)
        plan = validate(json.loads(args.plan_json or Path(args.plan_file).read_text()), args.approved_sha256)
        protected('/etc/greenwood-storage', directory=True, absent=True)
        for name in ('storage_plan.py', 'prepare_state_storage.py', 'prepare_durable_state.py', 'migrate_sqlite_rollback.py', 'plan.json'):
            protected('/etc/greenwood-storage/' + name, absent=True)
        require(args.node in (*CLIENTS, 'node-4'), 'UNEXPECTED_NODE')
        if args.action not in ('check', 'connected-check'):
            require(args.approve_storage_changes, 'OPERATOR_STORAGE_APPROVAL_REQUIRED')
            require(all(plan['clients'][node]['empty_media_approved'] is True for node in ('node-5', 'node-6', 'node-7')), 'EXISTING_SD_CONTENT_APPROVAL_REQUIRED')
        if args.action == 'check':
            check_target(plan) if args.node == 'node-4' else check_client(plan, args.node)
        elif args.action == 'connected-check':
            require(args.node in CLIENTS, 'CLIENT_REQUIRED'); check_client(plan, args.node, connected=True)
        elif args.action == 'create-target-files':
            require(args.node == 'node-4', 'TARGET_REQUIRED'); create_backing(plan, args.approved_sha256)
        elif args.action == 'start-target':
            require(args.node == 'node-4', 'TARGET_REQUIRED'); configure_target(plan, args.approved_sha256)
        else:
            connect_client(plan, args.node)
    except Exception as error:
        # No subprocess output, object repr or credential values cross this boundary.
        message = str(error) if isinstance(error, PlanError) else type(error).__name__
        parser.exit(1, 'STORAGE STOP: ' + message + '; preserve existing media and review locally.\n')
    print(json.dumps({'status': 'PASS', 'node': args.node, 'action': args.action, 'plan_sha256': args.approved_sha256}))


if __name__ == '__main__':
    main()
