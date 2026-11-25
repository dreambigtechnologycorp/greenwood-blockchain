#!/usr/bin/env python3
"""Operator-only rollback-journal conversion after verified state-volume copy.

Original NFS trees remain untouched. Existing database/WAL bytes are additionally
retained on the new volume before any SQLite operation that changes journal mode.
Never reads /etc credentials, starts services, or prints database values.
"""
import argparse
from contextlib import closing
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import stat
import subprocess

import prepare_durable_state as state

BACKUPS = '.greenwood-sqlite-rollback'


class MigrationError(Exception):
    pass


def require(value, code):
    if not value:
        raise MigrationError(code)


def isolate_mount_namespace():
    """Pin a private copy of the mount tree before SQLite resolves filenames.

    SQLite may canonicalize /proc/self/fd aliases, so a directory fd alone does
    not protect its absolute paths from a host lazy-unmount. This process makes
    no mount/unmount changes after isolation, and host propagation is disabled.
    No fallback is permitted when namespace creation is unavailable or denied.
    """
    require(callable(getattr(os, 'unshare', None)) and hasattr(os, 'CLONE_NEWNS'),
            'PRIVATE_MOUNT_NAMESPACE_UNAVAILABLE')
    before = os.readlink('/proc/self/ns/mnt')
    try:
        os.unshare(os.CLONE_NEWNS)
    except OSError:
        raise MigrationError('PRIVATE_MOUNT_NAMESPACE_DENIED') from None
    require(os.readlink('/proc/self/ns/mnt') != before, 'PRIVATE_MOUNT_NAMESPACE_NOT_CREATED')
    result = subprocess.run(['/usr/bin/mount', '--make-rprivate', '/'],
                            capture_output=True, text=True, timeout=10, check=False)
    require(result.returncode == 0, 'PRIVATE_MOUNT_PROPAGATION_FAILED')
    lines = Path('/proc/self/mountinfo').read_text().splitlines()
    require(bool(lines), 'INVALID_PRIVATE_MOUNT_METADATA')
    for line in lines:
        fields = line.split()
        require(len(fields) >= 10 and '-' in fields[6:], 'INVALID_PRIVATE_MOUNT_METADATA')
        optional = fields[6:fields.index('-', 6)]
        require(not any(value.startswith(('shared:', 'master:', 'propagate_from:')) for value in optional),
                'SHARED_MOUNT_PROPAGATION_REMAINS')


def fsync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def durable_mkdir(path):
    path.mkdir(mode=0o700)
    fsync_directory(path)
    fsync_directory(path.parent)


def signature(path):
    s = path.lstat()
    return s.st_dev, s.st_ino, s.st_size, s.st_mtime_ns, s.st_ctime_ns


def protected_file(path, guard):
    guard(path, directory=False)
    require(path.stat().st_nlink == 1, 'LINKED_DATABASE_FILE')


def hash_file(path):
    digest = hashlib.sha256()
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        for data in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(data)
    return digest.hexdigest()


def write_record(path, value):
    temporary = path.with_name(path.name + '.writing')
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as stream:
        stream.write(state.encoded(value) + b'\n')
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(temporary, path)
    fd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def logical_digest(db):
    # PDO may store opaque identity bytes as SQLite TEXT. Fingerprint raw bytes
    # and SQLite value types without trying to decode or print those values.
    previous = db.text_factory
    db.text_factory = bytes
    digest = hashlib.sha256()
    def record(values):
        for value in values:
            if value is None:
                data = b'n'
            elif isinstance(value, bytes):
                data = b'b' + value
            elif isinstance(value, int):
                data = b'i' + str(value).encode()
            elif isinstance(value, float):
                data = b'f' + value.hex().encode()
            else:
                raise MigrationError('UNSUPPORTED_SQLITE_VALUE')
            digest.update(len(data).to_bytes(8, 'big') + data)
        digest.update(b'\xff')
    def identifier(value):
        text = value.decode('utf-8')
        require('\0' not in text, 'INVALID_SQLITE_IDENTIFIER')
        return '"' + text.replace('"', '""') + '"'
    try:
        require(db.execute('PRAGMA integrity_check').fetchall() == [(b'ok',)], 'DATABASE_INTEGRITY_FAILED')
        schema = db.execute('SELECT type,name,tbl_name,sql FROM sqlite_master ORDER BY type,name').fetchall()
        for item in schema:
            record(item)
        for kind, name, _, _ in schema:
            if kind != b'table':
                continue
            table = identifier(name)
            columns = [identifier(row[1]) for row in db.execute('PRAGMA table_xinfo(' + table + ')')
                       if row[6] == 0]
            require(bool(columns), 'INVALID_SQLITE_TABLE')
            record((b'table', name))
            fields = ','.join(columns + ['typeof(' + column + ')' for column in columns])
            order = ','.join(column + ' COLLATE BINARY' for column in columns)
            for row in db.execute('SELECT ' + fields + ' FROM ' + table + ' ORDER BY ' + order):
                record(row)
        return digest.hexdigest()
    finally:
        db.text_factory = previous


def inspect(path):
    with closing(sqlite3.connect(path.as_uri() + '?mode=ro', uri=True, timeout=5)) as db:
        db.execute('PRAGMA trusted_schema=OFF')
        db.execute('PRAGMA query_only=ON')
        mode = db.execute('PRAGMA journal_mode').fetchone()[0]
        require(mode in ('wal', 'delete'), 'UNREVIEWED_JOURNAL_MODE')
        return mode, logical_digest(db)


def backup_database(path, directory, binding, guard):
    sources = [path] + [Path(str(path) + suffix) for suffix in ('-wal', '-shm')
                       if os.path.lexists(str(path) + suffix)]
    require(not os.path.lexists(str(path) + '-journal'), 'ROLLBACK_JOURNAL_REQUIRES_REVIEW')
    before = {}
    for original in sources:
        protected_file(original, guard)
        before[original] = signature(original)
    durable_mkdir(directory)
    files = {}
    for original in sources:
        suffix = str(original)[len(str(path)):]
        name = 'database.sqlite' + suffix
        source_fd = os.open(original, os.O_RDONLY | os.O_NOFOLLOW)
        output_fd = os.open(directory / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(source_fd, 'rb') as source, os.fdopen(output_fd, 'wb') as output:
            opened = os.fstat(source.fileno())
            require((opened.st_dev, opened.st_ino) == before[original][:2], 'DATABASE_CHANGED_DURING_BACKUP')
            digest = hashlib.sha256()
            for chunk in iter(lambda: source.read(1024 * 1024), b''):
                output.write(chunk)
                digest.update(chunk)
            output.flush()
            os.fsync(output.fileno())
            files[name] = digest.hexdigest()
    require(all(signature(p) == s for p, s in before.items()), 'DATABASE_CHANGED_DURING_BACKUP')
    # Read a second inspection copy so SQLite cannot change the retained backup
    # sidecars while obtaining the committed-state fingerprint.
    import tempfile
    import shutil
    with tempfile.TemporaryDirectory(prefix='.inspect-', dir=directory.parent) as scratch:
        for name in files:
            shutil.copyfile(directory / name, Path(scratch) / name)
        mode, digest = inspect(Path(scratch) / 'database.sqlite')
    record = {'version': 1, 'binding': binding, 'files': files,
              'journal_before': mode, 'logical_sha256': digest, 'state': 'prepared'}
    write_record(directory / 'record.json', record)
    return record


def migrate_database(path, backup_root, binding, *, apply=False, guard=state.protected):
    path = Path(path)
    protected_file(path, guard)
    require(not os.path.lexists(str(path) + '-journal'), 'ROLLBACK_JOURNAL_REQUIRES_REVIEW')
    folder = backup_root / hashlib.sha256(binding.encode()).hexdigest()
    record_path = folder / 'record.json'
    if not apply:
        # Check only the SQLite header; even read-only SQLite can update WAL
        # shared-memory bookkeeping. Actual validation follows retained backup.
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb') as stream:
            header = stream.read(100)
        require(len(header) == 100 and header[:16] == b'SQLite format 3\0' and
                header[18:20] in (b'\x01\x01', b'\x02\x02'), 'INVALID_SQLITE_HEADER')
        return 'wal' if header[18] == 2 else 'delete'
    if os.path.lexists(folder):
        guard(folder)
        protected_file(record_path, guard)
        record = json.loads(record_path.read_bytes())
        require(isinstance(record, dict) and set(record) == {
            'version', 'binding', 'files', 'journal_before', 'logical_sha256', 'state'}, 'INVALID_MIGRATION_RECORD')
        require(record['version'] == 1 and record['binding'] == binding and
                record['state'] in ('prepared', 'complete'), 'MIGRATION_BINDING_MISMATCH')
        require(isinstance(record['files'], dict) and 'database.sqlite' in record['files'] and
                set(record['files']) <= {'database.sqlite', 'database.sqlite-wal', 'database.sqlite-shm'},
                'INVALID_BACKUP_RECORD')
        for name, digest in record['files'].items():
            protected_file(folder / name, guard)
            require(hash_file(folder / name) == digest, 'RETAINED_BACKUP_CHANGED')
    else:
        record = backup_database(path, folder, binding, guard)
    with closing(sqlite3.connect(path.as_uri() + '?mode=rw', uri=True, timeout=5)) as db:
        db.execute('PRAGMA trusted_schema=OFF')
        db.execute('PRAGMA busy_timeout=5000')
        require(db.execute('PRAGMA locking_mode=EXCLUSIVE').fetchone()[0] == 'exclusive', 'EXCLUSIVE_SQLITE_LOCK_REQUIRED')
        require(logical_digest(db) == record['logical_sha256'], 'DATABASE_CHANGED_AFTER_BACKUP')
        current = db.execute('PRAGMA journal_mode').fetchone()[0]
        require(current in ('wal', 'delete'), 'UNREVIEWED_JOURNAL_MODE')
        if current == 'wal':
            checkpoint = db.execute('PRAGMA wal_checkpoint(TRUNCATE)').fetchone()
            require(checkpoint is not None and checkpoint[0] == 0, 'WAL_CHECKPOINT_BUSY')
        require(db.execute('PRAGMA journal_mode=DELETE').fetchone()[0] == 'delete', 'ROLLBACK_MODE_REJECTED')
        db.execute('PRAGMA synchronous=EXTRA')
        require(db.execute('PRAGMA synchronous').fetchone()[0] == 3, 'DURABLE_SYNC_REJECTED')
        require(logical_digest(db) == record['logical_sha256'], 'COMMITTED_STATE_CHANGED')
    fd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)
    record['state'] = 'complete'
    write_record(record_path, record)
    return 'delete'


def run(plan, *, apply=False):
    state.validate_plan(plan)
    require(Path('/etc/machine-id').read_text().strip() == plan['expected_machine_id'], 'MACHINE_ID_MISMATCH')
    state.require_stopped()
    state.verify_volume(plan)
    mount = Path(plan['mount_path'])
    marker = state.read_marker(mount, plan)
    require(marker is not None and marker['state'] == 'complete', 'VERIFIED_STATE_COPY_REQUIRED')
    databases = []
    for source in plan['sources']:
        destination = mount / Path(source).name
        state.protected(Path(source))
        state.protected(destination)
        require(os.path.samefile(source, destination), 'VERIFIED_CANONICAL_BIND_REQUIRED')
        if source == '/var/lib/containerd':
            continue  # containerd metadata.db is not SQLite.
        for directory, subdirs, files in os.walk(destination, followlinks=False):
            subdirs[:] = sorted(name for name in subdirs if not Path(directory, name).is_symlink())
            for name in sorted(files):
                if name.endswith('.sqlite'):
                    database = Path(directory) / name
                    protected_file(database, state.protected)
                    require(database.stat().st_dev == mount.stat().st_dev, 'DATABASE_OUTSIDE_STATE_VOLUME')
                    databases.append(database)
    require(len(databases) <= 256, 'DATABASE_SCOPE_REQUIRES_REVIEW')
    backup_root = mount / BACKUPS
    with state.anchored_volume(mount):
        if apply and not backup_root.exists():
            durable_mkdir(backup_root)
        if backup_root.exists():
            state.protected(backup_root)
        modes = []
        for path in databases:
            state.require_stopped()
            state.verify_volume(plan)
            binding = plan['plan_sha256'] + ':' + str(path.relative_to(mount))
            modes.append(migrate_database(path, backup_root, binding, apply=apply))
    return {'status': 'CONVERTED_AND_VERIFIED' if apply else 'CHECKED_HEADERS_ONLY',
            'databases': len(databases), 'wal_remaining': modes.count('wal'),
            'plan_sha256': plan['plan_sha256'], 'originals_retained': True}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--plan-json', required=True)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--apply', action='store_true')
    parser.add_argument('--approve-sqlite-conversion', action='store_true')
    args = parser.parse_args()
    try:
        require(os.geteuid() == 0, 'ROOT_REQUIRED')
        require(not args.apply or args.approve_sqlite_conversion, 'SQLITE_CONVERSION_APPROVAL_REQUIRED')
        require(len(args.plan_json) <= 65536, 'INVALID_PLAN')
        plan = state.validate_plan(json.loads(args.plan_json))
        isolate_mount_namespace()
        result = run(plan, apply=args.apply)
    except (MigrationError, state.CopyError, OSError, ValueError, sqlite3.Error, subprocess.SubprocessError) as error:
        code = str(error) if isinstance(error, (MigrationError, state.CopyError)) else 'SQLITE_MIGRATION_FAILED'
        parser.exit(1, 'STOP: ' + code + '; retained originals/backups must not be deleted.\n')
    print(json.dumps(result, separators=(',', ':')))


if __name__ == '__main__':
    main()
