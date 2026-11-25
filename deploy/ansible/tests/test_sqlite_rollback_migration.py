"""Synthetic SQLite stores only; no production state or credentials."""
import importlib.util
import io
import json
import os
from pathlib import Path
import sqlite3
import stat
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from types import SimpleNamespace
from unittest.mock import Mock, patch

FILES = Path(__file__).resolve().parents[1] / 'files'
sys.path.insert(0, str(FILES))
import migrate_sqlite_rollback as M


class RollbackMigrationTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='gw-sqlite-convert-')
        self.base = Path(self.tmp.name)
        self.database = self.base / 'provider.sqlite'
        self.backups = self.base / 'backups'
        self.backups.mkdir(mode=0o700)

    def tearDown(self):
        self.tmp.cleanup()

    def guard(self, path, *, directory=True):
        path = Path(path)
        self.assertTrue(path == self.base or self.base in path.parents)
        for current in (path, *path.parents):
            if current == self.base.parent:
                break
            s = current.lstat()
            expected = stat.S_ISDIR if directory or current != path else stat.S_ISREG
            M.require(expected(s.st_mode) and s.st_uid == os.geteuid() and not s.st_mode & 0o022,
                      'UNPROTECTED_PATH')
        return path

    def wal(self):
        code = r'''
import os,sqlite3,sys
os.umask(0o077)
db=sqlite3.connect(sys.argv[1])
db.execute('PRAGMA journal_mode=WAL')
db.execute('PRAGMA wal_autocheckpoint=0')
db.execute('CREATE TABLE accounts (id INTEGER PRIMARY KEY, balance INTEGER NOT NULL)')
db.executemany('INSERT INTO accounts VALUES (?,?)', [(1,700),(2,300)])
db.execute('CREATE TABLE opaque (value)')
db.execute("INSERT INTO opaque VALUES (CAST(X'FF0080' AS TEXT))")
db.execute("INSERT INTO opaque VALUES (X'FF0080')")
db.commit()
os._exit(0)
'''
        subprocess.run([sys.executable, '-c', code, str(self.database)], check=True, timeout=10)
        self.assertTrue(Path(str(self.database) + '-wal').exists())

    def convert(self, apply=True):
        return M.migrate_database(self.database, self.backups, 'fixture-plan:provider.sqlite',
                                  apply=apply, guard=self.guard)

    def source_snapshot(self):
        return {p.name: (p.read_bytes(), p.stat().st_mtime_ns) for p in self.base.iterdir() if p.is_file()}

    def test_check_is_header_only_and_does_not_touch_wal_or_create_backups(self):
        self.wal()
        before = self.source_snapshot()
        self.assertEqual(self.convert(False), 'wal')
        self.assertEqual(before, self.source_snapshot())
        self.assertEqual(list(self.backups.iterdir()), [])

    def test_committed_wal_frames_and_binary_text_survive_conversion_and_rerun(self):
        self.wal()
        before = self.source_snapshot()
        self.assertEqual(self.convert(), 'delete')
        folder = next(self.backups.iterdir())
        record = json.loads((folder / 'record.json').read_text())
        self.assertEqual(record['state'], 'complete')
        for name, (data, _) in before.items():
            copied = 'database.sqlite' + name[len('provider.sqlite'):]
            self.assertEqual((folder / copied).read_bytes(), data)
        with sqlite3.connect(self.database) as db:
            self.assertEqual(db.execute('PRAGMA journal_mode').fetchone(), ('delete',))
            self.assertEqual(db.execute('SELECT SUM(balance) FROM accounts').fetchone(), (1000,))
            self.assertEqual(db.execute('SELECT hex(value),typeof(value) FROM opaque').fetchall(),
                             [('FF0080','text'),('FF0080','blob')])
        self.assertEqual(self.convert(), 'delete')

    def test_completed_sqlite_conversion_before_receipt_write_can_resume(self):
        self.wal()
        write = M.write_record
        def interrupted(path, value):
            if value['state'] == 'complete':
                raise OSError('synthetic interruption')
            write(path, value)
        with patch.object(M, 'write_record', side_effect=interrupted):
            with self.assertRaises(OSError):
                self.convert()
        self.assertEqual(self.convert(), 'delete')

    def test_changed_committed_state_or_backup_refuses_rerun(self):
        self.wal()
        self.convert()
        with sqlite3.connect(self.database) as db:
            db.execute('UPDATE accounts SET balance=balance+1 WHERE id=1')
        with self.assertRaisesRegex(M.MigrationError, 'DATABASE_CHANGED_AFTER_BACKUP'):
            self.convert()
        folder = next(self.backups.iterdir())
        (folder / 'database.sqlite').write_bytes(b'corrupt backup')
        with self.assertRaisesRegex(M.MigrationError, 'RETAINED_BACKUP_CHANGED'):
            self.convert()

    def test_symlink_and_hot_rollback_journal_are_not_discarded(self):
        self.wal()
        journal = Path(str(self.database) + '-journal')
        journal.write_bytes(b'preserve for recovery')
        with self.assertRaisesRegex(M.MigrationError, 'JOURNAL_REQUIRES_REVIEW'):
            self.convert()
        self.assertEqual(journal.read_bytes(), b'preserve for recovery')
        journal.unlink()
        target = self.base / 'real.sqlite'
        self.database.rename(target)
        self.database.symlink_to(target)
        with self.assertRaisesRegex(M.MigrationError, 'UNPROTECTED_PATH'):
            self.convert()

    def test_active_writer_refuses_conversion_without_committing_its_transaction(self):
        self.wal()
        writer = sqlite3.connect(self.database)
        try:
            writer.execute('BEGIN IMMEDIATE')
            writer.execute('UPDATE accounts SET balance=999 WHERE id=1')
            with self.assertRaises((sqlite3.OperationalError, M.MigrationError)):
                self.convert()
            self.assertEqual(writer.execute('PRAGMA journal_mode').fetchone(), ('wal',))
            records = list(self.backups.glob('*/record.json'))
            self.assertTrue(records)
            self.assertTrue(all(json.loads(path.read_text())['state'] == 'prepared' for path in records))
        finally:
            writer.rollback()
            writer.close()
        self.assertEqual(self.convert(), 'delete')
        with sqlite3.connect(self.database) as db:
            self.assertEqual(db.execute('SELECT SUM(balance) FROM accounts').fetchone(), (1000,))

    def test_new_backup_directories_sync_their_parent_entries(self):
        directory = self.backups / 'new-fixture'
        with patch.object(M, 'fsync_directory', wraps=M.fsync_directory) as sync:
            M.durable_mkdir(directory)
        self.assertEqual([call.args[0] for call in sync.call_args_list], [directory, self.backups])


class PrivateMountNamespaceTest(unittest.TestCase):
    MOUNTINFO = '30 25 8:1 / / rw,relatime - ext4 /dev/fixture rw\n'

    def test_namespace_is_new_and_recursively_private_before_any_state_access(self):
        events = []
        def unshare(flags):
            self.assertEqual(flags, os.CLONE_NEWNS)
            events.append('unshare')
        def command(argv, **kwargs):
            self.assertEqual(argv, ['/usr/bin/mount', '--make-rprivate', '/'])
            events.append('private')
            return SimpleNamespace(returncode=0)
        with patch.object(os, 'unshare', side_effect=unshare, create=True), \
             patch.object(os, 'CLONE_NEWNS', getattr(os, 'CLONE_NEWNS', 0x20000), create=True), \
             patch.object(os, 'readlink', side_effect=['mnt:[old]', 'mnt:[new]']), \
             patch.object(M.subprocess, 'run', side_effect=command), \
             patch.object(Path, 'read_text', return_value=self.MOUNTINFO):
            M.isolate_mount_namespace()
        self.assertEqual(events, ['unshare', 'private'])

    def test_namespace_unavailable_denied_unchanged_or_shared_fails_closed(self):
        with patch.object(os, 'unshare', None, create=True):
            with self.assertRaisesRegex(M.MigrationError, 'UNAVAILABLE'):
                M.isolate_mount_namespace()
        with patch.object(os, 'unshare', side_effect=PermissionError('fixture'), create=True), \
             patch.object(os, 'CLONE_NEWNS', getattr(os, 'CLONE_NEWNS', 0x20000), create=True), \
             patch.object(os, 'readlink', return_value='mnt:[old]'), \
             patch.object(M.subprocess, 'run') as command:
            with self.assertRaisesRegex(M.MigrationError, 'DENIED'):
                M.isolate_mount_namespace()
            command.assert_not_called()
        with patch.object(os, 'unshare', create=True), \
             patch.object(os, 'CLONE_NEWNS', getattr(os, 'CLONE_NEWNS', 0x20000), create=True), \
             patch.object(os, 'readlink', return_value='mnt:[same]'), \
             patch.object(M.subprocess, 'run') as command:
            with self.assertRaisesRegex(M.MigrationError, 'NOT_CREATED'):
                M.isolate_mount_namespace()
            command.assert_not_called()
        for result, metadata, reason in [
            (1, self.MOUNTINFO, 'PROPAGATION_FAILED'),
            (0, self.MOUNTINFO.replace(' - ext4', ' shared:1 - ext4'), 'PROPAGATION_REMAINS'),
            (0, '', 'INVALID_PRIVATE_MOUNT_METADATA')]:
            with self.subTest(reason=reason), patch.object(os, 'unshare', create=True), \
                 patch.object(os, 'CLONE_NEWNS', getattr(os, 'CLONE_NEWNS', 0x20000), create=True), \
                 patch.object(os, 'readlink', side_effect=['mnt:[old]', 'mnt:[new]']), \
                 patch.object(M.subprocess, 'run', return_value=SimpleNamespace(returncode=result)), \
                 patch.object(Path, 'read_text', return_value=metadata):
                with self.assertRaisesRegex(M.MigrationError, reason):
                    M.isolate_mount_namespace()

    def test_cli_never_inspects_or_converts_state_if_isolation_fails(self):
        argv = ['migrate_sqlite_rollback.py', '--plan-json', '{}', '--apply', '--approve-sqlite-conversion']
        output = io.StringIO()
        with patch.object(sys, 'argv', argv), patch.object(os, 'geteuid', return_value=0), \
             patch.object(M.state, 'validate_plan', return_value={}), \
             patch.object(M, 'isolate_mount_namespace', side_effect=M.MigrationError('PRIVATE_MOUNT_NAMESPACE_DENIED')), \
             patch.object(M, 'run') as convert, redirect_stderr(output):
            with self.assertRaises(SystemExit):
                M.main()
            convert.assert_not_called()
        self.assertIn('PRIVATE_MOUNT_NAMESPACE_DENIED', output.getvalue())

    def test_cli_orders_namespace_setup_before_state_inspection(self):
        events = []
        argv = ['migrate_sqlite_rollback.py', '--plan-json', '{}', '--check']
        with patch.object(sys, 'argv', argv), patch.object(os, 'geteuid', return_value=0), \
             patch.object(M.state, 'validate_plan', return_value={}), \
             patch.object(M, 'isolate_mount_namespace', side_effect=lambda: events.append('isolate')), \
             patch.object(M, 'run', side_effect=lambda *args, **kwargs: events.append('inspect') or {'status': 'fixture'}), \
             redirect_stdout(io.StringIO()):
            M.main()
        self.assertEqual(events, ['isolate', 'inspect'])


if __name__ == '__main__':
    unittest.main()
