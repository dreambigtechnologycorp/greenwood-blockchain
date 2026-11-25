"""Synthetic operator-copy tests; no real devices, node state or secrets."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
from types import SimpleNamespace
import tempfile
import unittest
from unittest.mock import Mock, patch

FILE = Path(__file__).resolve().parents[1] / 'files/prepare_durable_state.py'
SPEC = importlib.util.spec_from_file_location('prepare_durable_state', FILE)
V = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(V)


@unittest.skipUnless(shutil.which('rsync'), 'rsync required for real synthetic-copy verification')
class DurableStateCopyTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='gw-state-copy-fixture-')
        self.base = Path(self.temp.name)
        self.volume = self.base / 'volume'
        self.volume.mkdir(mode=0o700)
        (self.volume / 'lost+found').mkdir(mode=0o700)
        self.original = self.base / 'retained-nfs'
        self.original.mkdir(mode=0o700)
        self.source = self.original / 'greenwood-provider'
        self.source.mkdir(mode=0o700)
        (self.source / 'state.db').write_bytes(b'fixture state, not a real database')
        (self.source / 'state.db').chmod(0o600)
        os.link(self.source / 'state.db', self.source / 'same-inode.db')
        (self.source / 'public-link').symlink_to('state.db')
        os.setxattr(self.source / 'state.db', 'user.greenwood-fixture', b'preserved')
        self.absent = self.original / 'greenwood-control-plane'
        self.machine = self.base / 'machine-id'
        self.machine.write_text('a' * 32 + '\n')
        self.machine.chmod(0o600)
        self.patches = [patch.object(V, 'MOUNT', str(self.volume)),
                        patch.object(V, 'SOURCES', frozenset((str(self.source), str(self.absent))))]
        for item in self.patches:
            item.start()
        self.plan = {'version': 1, 'node': 'node-6', 'expected_machine_id': 'a' * 32,
                     'filesystem_uuid': '12345678-1234-1234-1234-123456789abc',
                     'mount_path': str(self.volume), 'sources': [str(self.source), str(self.absent)]}
        self.plan['plan_sha256'] = hashlib.sha256(V.encoded(self.plan)).hexdigest()
        self.verify = Mock()
        self.stopped = Mock()

    def tearDown(self):
        for item in reversed(self.patches):
            item.stop()
        self.temp.cleanup()

    def guard(self, path, *, directory=True):
        path = Path(path)
        self.assertTrue(path == self.base or self.base in path.parents)
        for current in (path, *path.parents):
            if current == self.base.parent:
                break
            info = current.lstat()
            kind = stat.S_ISDIR if directory or current != path else stat.S_ISREG
            V.require(kind(info.st_mode) and info.st_uid == os.geteuid() and not info.st_mode & 0o022,
                      'UNPROTECTED_PATH')
        return path

    def execute(self, **kwargs):
        return V.copy_state(self.plan, guard=self.guard, verify=self.verify,
                            stopped=self.stopped, machine_id_path=self.machine, **kwargs)

    def test_check_is_metadata_only_without_destination_writes(self):
        with patch.object(V, 'tree_digest', side_effect=AssertionError('check must not read source contents')):
            result = self.execute()
        self.assertEqual(result['status'], 'READY')
        self.assertEqual(set(path.name for path in self.volume.iterdir()), {'lost+found'})
        self.assertFalse(self.absent.exists())

    def test_real_rsync_copy_retains_original_and_metadata_and_is_idempotent(self):
        before = V.tree_digest(self.source)
        result = self.execute(apply=True)
        self.assertEqual(result['status'], 'COPIED_AND_VERIFIED')
        self.assertTrue(result['originals_retained'])
        self.assertEqual(V.tree_digest(self.source), before)
        destination = self.volume / self.source.name
        self.assertEqual(V.tree_digest(destination), before)
        self.assertEqual((destination / 'state.db').stat().st_ino, (destination / 'same-inode.db').stat().st_ino)
        self.assertEqual(os.getxattr(destination / 'state.db', 'user.greenwood-fixture'), b'preserved')
        self.assertEqual((destination / 'public-link').readlink(), Path('state.db'))
        self.assertTrue((self.volume / self.absent.name).is_dir())
        self.assertFalse(self.absent.exists())
        again = self.execute(apply=True)
        self.assertEqual(again['manifest_sha256'], result['manifest_sha256'])

    def test_files_and_directories_are_flushed_before_completed_receipt(self):
        events = []
        commands = []
        original_sync = V.sync_copied_trees
        original_write = V.write_marker
        def run(argv, **kwargs):
            commands.append(argv)
            return subprocess.run(argv, **kwargs)
        def sync(names, fd):
            original_sync(names, fd)
            events.append('durable_destination')
        def write(fd, marker):
            if marker['state'] == 'complete':
                self.assertIn('durable_destination', events)
                events.append('complete')
            original_write(fd, marker)
        with patch.object(V, 'sync_copied_trees', side_effect=sync), patch.object(V, 'write_marker', side_effect=write):
            self.execute(apply=True, run=run)
        copied = [argv for argv in commands if argv[0] == '/usr/bin/rsync' and '--dry-run' not in argv]
        self.assertEqual(len(copied), 1)
        self.assertIn('--fsync', copied[0])
        self.assertEqual(events, ['durable_destination', 'complete'])

    def test_sync_failure_keeps_copy_receipt_incomplete(self):
        with patch.object(V, 'sync_copied_trees', side_effect=OSError('synthetic flush failure')):
            with self.assertRaises(OSError):
                self.execute(apply=True)
        marker = json.loads((self.volume / V.MARKER).read_text())
        self.assertEqual(marker['state'], 'copying')
        self.assertTrue((self.source / 'state.db').exists())

    def test_unknown_target_or_changed_completed_copy_is_never_overwritten(self):
        unknown = self.volume / 'unknown'
        unknown.write_text('keep')
        with self.assertRaisesRegex(V.CopyError, 'DESTINATION_NOT_EMPTY'):
            self.execute(apply=True)
        self.assertEqual(unknown.read_text(), 'keep')
        unknown.unlink()
        self.execute(apply=True)
        destination = self.volume / self.source.name / 'state.db'
        destination.write_text('independent retained destination')
        with self.assertRaisesRegex(V.CopyError, 'COPY_VERIFICATION_FAILED'):
            self.execute(apply=True)
        self.assertEqual(destination.read_text(), 'independent retained destination')

    def test_plan_machine_and_source_changes_fail_before_mutation(self):
        self.plan['plan_sha256'] = '0' * 64
        with self.assertRaisesRegex(V.CopyError, 'PLAN_HASH_MISMATCH'):
            self.execute(apply=True)
        self.plan['plan_sha256'] = hashlib.sha256(V.encoded({k: v for k,v in self.plan.items() if k != 'plan_sha256'})).hexdigest()
        self.machine.write_text('b' * 32)
        with self.assertRaisesRegex(V.CopyError, 'MACHINE_ID_MISMATCH'):
            self.execute(apply=True)
        self.machine.write_text('a' * 32)
        self.execute(apply=True)
        (self.source / 'state.db').write_text('changed source state')
        with self.assertRaisesRegex(V.CopyError, 'RETAINED_SOURCE_CHANGED'):
            self.execute(apply=True)

    def test_matching_interrupted_copy_resumes_and_unknown_plan_does_not(self):
        marker = {'plan': self.plan, 'state': 'copying', 'source_digests': {
            str(self.source): V.tree_digest(self.source), str(self.absent): 'absent'}}
        (self.volume / V.MARKER).write_bytes(V.encoded(marker))
        (self.volume / V.MARKER).chmod(0o600)
        partial = self.volume / self.source.name
        partial.mkdir(mode=0o700)
        (partial / 'state.db').write_bytes(b'partial fixture')
        self.execute(apply=True)
        self.assertEqual(V.tree_digest(partial), V.tree_digest(self.source))
        marker['plan']['node'] = 'node-7'
        (self.volume / V.MARKER).write_bytes(V.encoded(marker))
        with self.assertRaises(V.CopyError):
            self.execute(apply=True)

    def test_stopped_service_gate_and_missing_volume_fail_before_writes(self):
        self.stopped.side_effect = V.CopyError('SERVICE_NOT_STOPPED')
        with self.assertRaisesRegex(V.CopyError, 'SERVICE_NOT_STOPPED'):
            self.execute(apply=True)
        self.stopped.side_effect = None
        self.verify.side_effect = V.CopyError('EXACT_MOUNT_REQUIRED')
        with self.assertRaisesRegex(V.CopyError, 'EXACT_MOUNT_REQUIRED'):
            self.execute(apply=True)
        self.assertEqual(set(path.name for path in self.volume.iterdir()), {'lost+found'})

    def test_symlink_destination_rejected_and_source_symlink_not_followed(self):
        (self.volume / 'lost+found').rmdir()
        (self.volume / 'lost+found').symlink_to(self.source, target_is_directory=True)
        with self.assertRaisesRegex(V.CopyError, 'UNPROTECTED_PATH'):
            self.execute(apply=True)
        self.assertTrue((self.source / 'state.db').exists())


class StorageManifestTest(unittest.TestCase):
    def test_service_guard_refuses_active_units(self):
        def run(argv, **kwargs):
            output = ('greenwood-provider.service loaded active running fixture\n'
                      if 'list-units' in argv else 'ActiveState=active\nMainPID=100\n')
            return SimpleNamespace(returncode=0, stdout=output)
        with self.assertRaisesRegex(V.CopyError, 'SERVICE_NOT_STOPPED'):
            V.require_stopped(run=run, proc=Mock())

    def test_service_guard_refuses_surviving_containerd_shims(self):
        def run(argv, **kwargs):
            return SimpleNamespace(returncode=0, stdout='' if 'list-units' in argv else 'ActiveState=inactive\nMainPID=0\n')
        proc = Mock()
        proc.iterdir.return_value = [Path('/proc/1234')]
        with patch.object(os, 'readlink', return_value='/usr/bin/containerd-shim-runc-v2'):
            with self.assertRaisesRegex(V.CopyError, 'RUNTIME_PROCESS_STILL_RUNNING'):
                V.require_stopped(run=run, proc=proc)

    def test_nested_mounts_rejected_without_traversing_them(self):
        run = Mock(return_value=SimpleNamespace(returncode=0, stdout=json.dumps({'filesystems': [
            {'target': '/'}, {'target': '/var/lib/containerd/snapshot/root/etc'}]})))
        with self.assertRaisesRegex(V.CopyError, 'NESTED_SOURCE_MOUNT'):
            V.reject_nested_mounts(['/var/lib/containerd'], run=run)

    def test_writable_ext4_exact_uuid_and_device_binding_required(self):
        plan = {'mount_path': '/var/lib/greenwood-state', 'filesystem_uuid': '12345678-1234-1234-1234-123456789abc'}
        record = {'target': plan['mount_path'], 'source': '/dev/fixture', 'fstype': 'ext4',
                  'options': 'rw,relatime', 'uuid': plan['filesystem_uuid'], 'maj:min': '8:1'}
        def run(argv, **kwargs):
            output = json.dumps({'filesystems': [record]}) if 'findmnt' in argv[0] else plan['filesystem_uuid'] + '\n'
            return SimpleNamespace(returncode=0, stdout=output)
        info = SimpleNamespace(st_mode=stat.S_IFBLK | 0o600, st_rdev=os.makedev(8, 1))
        with patch.object(Path, 'resolve', lambda path, **kwargs: path):
            V.verify_volume(plan, run=run, guard=lambda path: path, lstat=lambda path: info)
            for field, bad in [('fstype', 'nfs4'), ('options', 'ro'), ('uuid', 'other'),
                               ('target', '/var/lib'), ('maj:min', '8:2')]:
                original = record[field]; record[field] = bad
                with self.assertRaises(V.CopyError):
                    V.verify_volume(plan, run=run, guard=lambda path: path, lstat=lambda path: info)
                record[field] = original


if __name__ == '__main__':
    unittest.main()
