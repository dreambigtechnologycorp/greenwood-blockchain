#!/usr/bin/env python3
"""Allow only an absent or initialized-but-unused provider store; never reset it.

Run after the operator has stopped the provider and application recovery service
and timer. No private identity is opened. SQLite and WAL files are copied into a
private temporary directory before inspection; originals are never opened by SQL.
"""
import argparse
from contextlib import closing, contextmanager
import errno
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import stat
import struct
import subprocess
import tempfile


class StateError(Exception):
    pass


def require(value, reason):
    if not value:
        raise StateError(reason)


def failure_code(error):
    """Return bounded diagnostic codes, never exception text or state contents."""
    if isinstance(error, StateError):
        return str(error)
    if isinstance(error, OSError):
        return 'OS_' + errno.errorcode.get(error.errno, 'UNKNOWN')
    if isinstance(error, sqlite3.Error):
        name = getattr(error, 'sqlite_errorname', '')
        return name if re.fullmatch(r'SQLITE_[A-Z0-9_]+', name) else 'SQLITE_ERROR'
    if isinstance(error, json.JSONDecodeError):
        return 'INVALID_JSON'
    if isinstance(error, UnicodeError):
        return 'INVALID_TEXT_ENCODING'
    if isinstance(error, subprocess.SubprocessError):
        return 'FILESYSTEM_PROBE_FAILED'
    return 'INVALID_VALUE'


@contextmanager
def inspection_step(label):
    try:
        yield
    except (OSError, ValueError, sqlite3.Error, subprocess.SubprocessError) as error:
        raise StateError(label + ':' + failure_code(error)) from None


def runtime_scratch_root():
    """WAL inspection must not inherit an NFS-backed /tmp or TMPDIR."""
    root = protected(Path('/run'), directory=True)
    result = subprocess.run(
        ['/usr/bin/findmnt', '--noheadings', '--output', 'FSTYPE', '--target', str(root)],
        capture_output=True, text=True, timeout=5, check=False,
    )
    require(result.returncode == 0 and result.stdout.strip() == 'tmpfs',
            'LOCAL_TMPFS_SCRATCH_REQUIRED')
    return root


def protected(path, *, directory=False, uid=0):
    path = Path(path)
    require(path.is_absolute() and str(path) == os.path.normpath(str(path)), 'NONCANONICAL_PATH')
    for current in (path, *path.parents):
        meta = current.lstat()
        expected = stat.S_ISDIR if directory or current != path else stat.S_ISREG
        require(expected(meta.st_mode) and meta.st_uid == uid and not meta.st_mode & 0o022,
                'UNPROTECTED_PATH')
        if current == path and not directory:
            require(meta.st_nlink == 1, 'LINKED_STATE_FILE')
    return path


def signature(path):
    value = path.lstat()
    return (value.st_dev, value.st_ino, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def read_file(path, guard, limit=64 * 1024 * 1024):
    guard(path)
    before = signature(path)
    require(before[2] <= limit, 'STATE_FILE_TOO_LARGE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as source:
        require((os.fstat(source.fileno()).st_dev, os.fstat(source.fileno()).st_ino) == before[:2],
                'STATE_CHANGED_DURING_CHECK')
        value = source.read(limit + 1)
    require(len(value) <= limit and signature(path) == before, 'STATE_CHANGED_DURING_CHECK')
    return value


def php_json(value):
    return json.dumps(value, ensure_ascii=True, separators=(',', ':')).replace('/', '\\/').encode()


SCHEMAS = {
    'provider.sqlite': {
        'metadata': {'key', 'value'},
        'provider_jobs': set('position request_id generation provider_id state received_at updated_at execution_id receipt transaction_bytes worker_pid last_error protocol_execution_id transaction_hash receipt_id worker_started_ns worker_launches'.split()),
    },
    'applications/applications.sqlite': {
        'provider_applications': set('application_id service service_id image_ref image_digest state container_id view_key parent_snapshot lease_id pid created_at updated_at'.split()),
    },
    'application-network/bindings.sqlite': {
        'binding_policy': {'id', 'fingerprint'},
        'platform_network_policy': {'id', 'fingerprint'},
        'application_bindings': {'application_id', 'service', 'slot', 'application_port'},
    },
    'application-network/network.sqlite': {
        'networks': {'application_id', 'service', 'record'},
        'network_events': {'id', 'application_id', 'service', 'recorded_at', 'record'},
    },
}
EMPTY_TABLES = {'provider_jobs', 'provider_applications', 'application_bindings', 'networks', 'network_events'}


def inspect_database(root, relative, expected_chain, expected_provider, fingerprints, guard, scratch_root):
    source = root / relative
    if not os.path.lexists(source):
        require(not any(os.path.lexists(str(source) + suffix) for suffix in ('-wal', '-shm', '-journal')),
                'ORPHAN_DATABASE_SIDECAR')
        return
    require(not os.path.lexists(str(source) + '-journal'), 'UNRECOVERED_DATABASE_JOURNAL')
    sources = [source] + [Path(str(source) + suffix) for suffix in ('-wal', '-shm')
                         if os.path.lexists(str(source) + suffix)]
    before = {p: signature(guard(p)) for p in sources}
    with tempfile.TemporaryDirectory(prefix='greenwood-provider-inspect-', dir=scratch_root) as directory:
        copied = Path(directory) / 'state.sqlite'
        for original in sources:
            target = Path(str(copied) + str(original)[len(str(source)):])
            fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, 'wb') as output:
                output.write(read_file(original, guard))
        require(all(signature(p) == sig for p, sig in before.items()), 'STATE_CHANGED_DURING_CHECK')
        with closing(sqlite3.connect(copied.as_uri() + '?mode=ro', uri=True, timeout=2)) as db:
            db.text_factory = bytes
            db.execute('PRAGMA query_only=ON')
            db.execute('PRAGMA trusted_schema=OFF')
            require(db.execute('PRAGMA quick_check').fetchall() == [(b'ok',)], 'CORRUPT_DATABASE')
            objects = db.execute('SELECT type,name FROM sqlite_master').fetchall()
            require(not any(kind in (b'view', b'trigger') for kind, _ in objects), 'UNEXPECTED_DATABASE_SCHEMA')
            tables = {name.decode('ascii') for kind, name in objects if kind == b'table'}
            allowed = set(SCHEMAS[relative]) | ({'sqlite_sequence'} if relative == 'provider.sqlite' else set())
            require(set(SCHEMAS[relative]) <= tables <= allowed, 'UNEXPECTED_DATABASE_SCHEMA')
            for table, columns in SCHEMAS[relative].items():
                actual = {row[1].decode('ascii') for row in db.execute(f'PRAGMA table_info("{table}")')}
                require(actual == columns, 'UNEXPECTED_DATABASE_SCHEMA')
                if table in EMPTY_TABLES:
                    require(db.execute(f'SELECT COUNT(*) FROM "{table}"').fetchone()[0] == 0,
                            'PROVIDER_HAS_WORKLOAD_HISTORY')
            if 'sqlite_sequence' in tables:
                require(db.execute('SELECT COUNT(*) FROM sqlite_sequence').fetchone()[0] == 0,
                        'PROVIDER_HAS_WORKLOAD_HISTORY')
            if relative == 'provider.sqlite':
                rows = db.execute('SELECT key,value FROM metadata').fetchall()
                values = dict(rows)
                require(len(values) == len(rows) and set(values) <= {
                    b'chain_id', b'provider_id', b'drain', b'finalized_height'}, 'UNEXPECTED_PROVIDER_METADATA')
                require(values.get(b'chain_id') == expected_chain.encode() and
                        values.get(b'provider_id') == expected_provider, 'PROVIDER_IDENTITY_MISMATCH')
                require(values.get(b'drain', b'0') in (b'0', b'1') and
                        values.get(b'finalized_height', b'0') == b'0', 'PROVIDER_HAS_FINALIZED_HISTORY')
            if relative == 'application-network/bindings.sqlite':
                require(fingerprints is not None, 'NETWORK_POLICY_REQUIRED')
                for table, digest in fingerprints.items():
                    require(db.execute(f'SELECT id,fingerprint FROM "{table}"').fetchall() == [(1, digest.encode())],
                            'NETWORK_POLICY_MISMATCH')
    require(all(signature(p) == sig for p, sig in before.items()), 'STATE_CHANGED_DURING_CHECK')


def validate(data_dir, chain_id, public_key, network_policy=None, *, guard=protected, scratch_root=None):
    require(re.fullmatch(r'[A-Za-z0-9_-]{1,128}', chain_id), 'INVALID_CHAIN_ID')
    require(re.fullmatch(r'[a-f0-9]{64}', public_key), 'INVALID_PUBLIC_KEY')
    root = Path(data_dir)
    if not os.path.lexists(root):
        guard(root.parent, directory=True)
        return
    guard(root, directory=True)
    allowed = {'archive', 'output', 'workers', 'applications', 'application-network',
               'provider.sqlite', 'provider.sqlite-wal', 'provider.sqlite-shm', 'supervisor.lock', 'status.json'}
    require(set(p.name for p in root.iterdir()) <= allowed, 'UNEXPECTED_PROVIDER_STATE')
    for name in ('archive', 'output', 'workers'):
        directory = root / name
        if os.path.lexists(directory):
            guard(directory, directory=True)
            require(not any(directory.iterdir()), 'PROVIDER_HAS_WORKLOAD_FILES')
    for name, database_names in (('applications', {'applications.sqlite'}),
                                 ('application-network', {'bindings.sqlite', 'network.sqlite'})):
        directory = root / name
        if os.path.lexists(directory):
            guard(directory, directory=True)
            permitted = {filename + suffix for filename in database_names for suffix in ('', '-wal', '-shm')}
            require(set(p.name for p in directory.iterdir()) <= permitted, 'UNEXPECTED_PROVIDER_STATE')
    require(not os.path.lexists(root / 'application-network/network.sqlite') or
            os.path.lexists(root / 'application-network/bindings.sqlite'), 'MISSING_BINDING_DATABASE')
    domain = b'GREENWOOD_PROVIDER_V1'
    provider_id = hashlib.blake2b(struct.pack('>H', len(domain)) + domain + bytes.fromhex(public_key),
                                 digest_size=32).digest()
    fingerprints = None
    if network_policy is not None:
        with inspection_step('NETWORK_POLICY'):
            policy = json.loads(read_file(Path(network_policy), guard, 65536))
        fields = ('providerAddress', 'edgeAddress', 'subnet', 'firstPort', 'lastPort',
                  'forwardTable', 'forwardChain', 'protectedServices')
        require(isinstance(policy, dict) and set(policy) == set(fields), 'UNEXPECTED_NETWORK_POLICY')
        fingerprints = {
            'binding_policy': hashlib.sha256(php_json([policy[k] for k in ('subnet', 'firstPort', 'lastPort')])).hexdigest(),
            'platform_network_policy': hashlib.sha256(php_json({k: policy[k] for k in fields})).hexdigest(),
        }
    lock = root / 'supervisor.lock'
    require(not (root / 'provider.sqlite').exists() or os.path.lexists(lock), 'MISSING_SUPERVISOR_LOCK')
    fd = None
    try:
        if os.path.lexists(lock):
            with inspection_step('SUPERVISOR_LOCK'):
                guard(lock)
                before_lock = signature(lock)
                fd = os.open(lock, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
                opened = os.fstat(fd)
                require((opened.st_dev, opened.st_ino) == before_lock[:2] and
                        signature(lock) == before_lock, 'STATE_CHANGED_DURING_CHECK')
                try:
                    # The provider always holds LOCK_EX. A shared inspection
                    # lock excludes it without requesting a write lock on an
                    # O_RDONLY descriptor, which NFS rejects with EBADF.
                    fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
                except BlockingIOError:
                    raise StateError('PROVIDER_SUPERVISOR_STILL_RUNNING') from None
        if scratch_root is None:
            with inspection_step('INSPECTION_SCRATCH'):
                scratch_root = runtime_scratch_root()
        for relative in SCHEMAS:
            with inspection_step('DATABASE_' + relative):
                inspect_database(root, relative, chain_id, provider_id, fingerprints, guard, scratch_root)
        status = root / 'status.json'
        if os.path.lexists(status):
            with inspection_step('PROVIDER_STATUS'):
                value = json.loads(read_file(status, guard, 1048576))
            require(isinstance(value, dict) and value.get('jobs') == [] and
                    type(value.get('height')) is int and value['height'] == 0, 'PROVIDER_HAS_STATUS_HISTORY')
        if fd is not None:
            require(signature(lock) == before_lock, 'STATE_CHANGED_DURING_CHECK')
    finally:
        if fd is not None:
            os.close(fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--data-dir', required=True)
    parser.add_argument('--chain-id', required=True)
    parser.add_argument('--public-key', required=True)
    parser.add_argument('--network-policy')
    args = parser.parse_args()
    try:
        require(os.geteuid() == 0, 'ROOT_REQUIRED')
        validate(args.data_dir, args.chain_id, args.public_key, args.network_policy)
    except (StateError, OSError, ValueError, sqlite3.Error, subprocess.SubprocessError) as error:
        reason = failure_code(error)
        parser.exit(1, f'STOP: {reason}; preserve existing state for operator review.\n')
    print('PASS: provider store absent or initialized without workload or finalized history; preserved unchanged')


if __name__ == '__main__':
    main()
