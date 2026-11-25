"""Prepared-target resume checks use only small local files and stubbed probes."""
from contextlib import ExitStack, redirect_stderr
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

from jinja2 import Environment, StrictUndefined
import yaml

from test_state_storage_provisioning import FILES, P, S, public_plan


sys.modules['prepare_state_storage'] = S
spec = importlib.util.spec_from_file_location('validate_prepared_storage_target',
                                            FILES / 'validate_prepared_storage_target.py')
R = importlib.util.module_from_spec(spec)
spec.loader.exec_module(R)


class PreparedStorageTargetTest(unittest.TestCase):
    def setUp(self):
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        self.temp = Path(self.stack.enter_context(tempfile.TemporaryDirectory(prefix='gw-prepared-target-')))
        self.ssd = self.temp / 'ssd'; self.ssd.mkdir()
        self.backing = self.ssd / 'block-volumes'; self.backing.mkdir()
        self.config = self.temp / 'config-target'
        (self.config / 'core/alua/lu_gps/default_lu_gp').mkdir(parents=True)
        self.plan = public_plan(); self.approved = P.digest(self.plan)
        for name, state in (('creation.json', 'creating'), ('prepared.json', 'prepared')):
            (self.backing / name).write_text(json.dumps({'plan_sha256': self.approved, 'state': state}))
        for node in P.ISCSI:
            with (self.backing / (node + '.img')).open('wb') as stream:
                os.posix_fallocate(stream.fileno(), 0, 4096)
        self.overrides = {}
        original_lstat = Path.lstat

        def metadata(path):
            original = original_lstat(path)
            # Exercise the real path-protection function without requiring root
            # ownership or trusting /tmp's writable parent in the test fixture.
            values = {key: getattr(original, key) for key in
                      ('st_mode', 'st_uid', 'st_gid', 'st_dev', 'st_ino', 'st_nlink', 'st_size', 'st_blocks')}
            values.update(st_uid=0, st_gid=0, st_mode=original.st_mode & ~0o022)
            values.update(self.overrides.get(str(path), {}))
            return SimpleNamespace(**values)

        self.commands = []
        self.listener = ''
        self.bad_uuid = None

        def probe(argv, **kwargs):
            self.commands.append(argv)
            if argv == ['ss', '-H', '-ltn', 'sport', '=', ':3260']:
                return self.listener
            if argv[:6] == ['blkid', '-p', '-s', 'UUID', '-o', 'value']:
                return self.bad_uuid or self.plan['clients'][Path(argv[-1]).stem]['filesystem_uuid']
            raise AssertionError('Unexpected probe: ' + repr(argv))

        self.stack.enter_context(patch.object(Path, 'lstat', metadata))
        self.stack.enter_context(patch.object(R, 'SSD', self.ssd))
        self.stack.enter_context(patch.object(R, 'BACKING', str(self.backing)))
        self.stack.enter_context(patch.object(R, 'CONFIGFS_TARGET', self.config))
        self.stack.enter_context(patch.object(R, 'LUN_BYTES', 4096))
        self.common_check = self.stack.enter_context(patch.object(S, 'check_target'))
        self.stack.enter_context(patch.object(S, 'run', side_effect=probe))
        self.disk = self.stack.enter_context(patch.object(R.os, 'statvfs', return_value=SimpleNamespace(
            f_bavail=self.plan['target']['reserve_free_bytes'] // 4096, f_frsize=4096)))

    def check(self):
        R.check_prepared_target(self.plan, self.approved)

    def test_complete_exact_prepared_files_pass_without_changes_or_library_import(self):
        before = {path.name: (path.stat().st_size, path.stat().st_mtime_ns, path.stat().st_ino)
                  for path in self.backing.iterdir()}
        original_import = __import__

        def read_only_import(name, *args, **kwargs):
            if name.startswith('rtslib'):
                raise AssertionError('Target library import is forbidden during preflight')
            return original_import(name, *args, **kwargs)

        original_open = os.open

        def read_only_open(path, flags, *args, **kwargs):
            self.assertFalse(flags & (os.O_WRONLY | os.O_RDWR | os.O_CREAT | os.O_TRUNC))
            return original_open(path, flags, *args, **kwargs)

        with patch('builtins.__import__', side_effect=read_only_import), \
             patch.object(os, 'open', side_effect=read_only_open), \
             patch.object(os, 'mkdir', side_effect=AssertionError('must not create')), \
             patch.object(os, 'unlink', side_effect=AssertionError('must not remove')), \
             patch.object(os, 'chmod', side_effect=AssertionError('must not chmod')), \
             patch.object(S, 'chap', side_effect=AssertionError('must not read CHAP contents')):
            self.check()
        self.common_check.assert_called_once_with(self.plan, new=False)
        self.assertEqual(len(self.commands), 4)
        self.assertEqual(before, {path.name: (path.stat().st_size, path.stat().st_mtime_ns, path.stat().st_ino)
                                  for path in self.backing.iterdir()})

    def test_unknown_or_missing_directory_child_stops(self):
        unexpected = self.backing / 'unreviewed'
        unexpected.mkdir()
        with self.assertRaisesRegex(P.PlanError, 'PREPARED_DIRECTORY_CONTENTS_MISMATCH'):
            self.check()
        unexpected.rmdir()
        (self.backing / 'node-3.img').unlink()
        with self.assertRaisesRegex(P.PlanError, 'PREPARED_DIRECTORY_CONTENTS_MISMATCH'):
            self.check()

    def test_wrong_receipt_state_hash_or_extra_fields_stops(self):
        path = self.backing / 'prepared.json'
        for value in ({'plan_sha256': self.approved, 'state': 'creating'},
                      {'plan_sha256': '0' * 64, 'state': 'prepared'},
                      {'plan_sha256': self.approved, 'state': 'prepared', 'extra': True}):
            with self.subTest(value=value):
                path.write_text(json.dumps(value))
                with self.assertRaisesRegex(P.PlanError, 'PREPARED_RECEIPT_MISMATCH'):
                    self.check()

    def test_unprotected_linked_oversized_or_foreign_receipt_stops(self):
        path = self.backing / 'creation.json'
        for fields, message in (({'st_uid': 1000}, 'UNPROTECTED_PATH'),
                                ({'st_mode': stat.S_IFREG | 0o666}, 'UNPROTECTED_PATH'),
                                ({'st_nlink': 2}, 'PREPARED_RECEIPT_METADATA_MISMATCH'),
                                ({'st_size': 4097}, 'PREPARED_RECEIPT_METADATA_MISMATCH'),
                                ({'st_dev': -1}, 'PREPARED_RECEIPT_METADATA_MISMATCH')):
            with self.subTest(fields=fields):
                self.overrides[str(path)] = fields
                with self.assertRaisesRegex(P.PlanError, message):
                    self.check()
        self.overrides.clear()
        path.unlink(); path.symlink_to(self.backing / 'prepared.json')
        with self.assertRaisesRegex(P.PlanError, 'UNPROTECTED_PATH'):
            self.check()

    def test_wrong_image_metadata_uuid_or_allocation_stops(self):
        path = self.backing / 'node-1.img'
        for fields, message in (({'st_nlink': 2}, 'BACKING_FILE_MISMATCH'),
                                ({'st_size': 4095}, 'BACKING_FILE_MISMATCH'),
                                ({'st_blocks': 0}, 'BACKING_ALLOCATION_NOT_RESERVED'),
                                ({'st_dev': -1}, 'PREPARED_BACKING_FILESYSTEM_MISMATCH'),
                                ({'st_uid': 1000}, 'UNPROTECTED_PATH')):
            with self.subTest(fields=fields):
                self.overrides[str(path)] = fields
                with self.assertRaisesRegex(P.PlanError, message):
                    self.check()
        self.overrides.clear(); self.bad_uuid = 'unapproved-uuid'
        with self.assertRaisesRegex(P.PlanError, 'BACKING_UUID_MISMATCH'):
            self.check()

    def test_backing_on_foreign_filesystem_or_insufficient_remaining_reserve_stops(self):
        self.overrides[str(self.backing)] = {'st_dev': -1}
        with self.assertRaisesRegex(P.PlanError, 'PREPARED_BACKING_FILESYSTEM_MISMATCH'):
            self.check()
        self.overrides.clear()
        self.disk.return_value.f_bavail -= 1
        with self.assertRaisesRegex(P.PlanError, 'INSUFFICIENT_SHARED_SSD_RESERVE'):
            self.check()

    def test_any_fabric_target_partial_backstore_or_listener_stops(self):
        for name, message in (('iscsi/iqn.fixture', 'EXISTING_TARGETS_REQUIRE_REVIEW'),
                              ('loopback/naa.fixture', 'EXISTING_TARGETS_REQUIRE_REVIEW'),
                              ('core/fileio_0', 'EXISTING_BACKSTORES_REQUIRE_REVIEW')):
            path = self.config / name; path.mkdir(parents=True)
            with self.subTest(name=name):
                with self.assertRaisesRegex(P.PlanError, message):
                    self.check()
            path.rmdir()
        self.listener = 'LISTEN fixture'
        with self.assertRaisesRegex(P.PlanError, 'ISCSI_PORT_ALREADY_IN_USE'):
            self.check()

    def test_entrypoint_is_check_only_and_sanitizes_unexpected_errors(self):
        argv = ['validator', '--plan-json', json.dumps(self.plan), '--approved-sha256', self.approved,
                '--node', 'node-4', '--action', 'check']
        stderr = io.StringIO()
        with patch.object(sys, 'argv', argv), patch.object(os, 'geteuid', return_value=0), \
             patch.object(S, 'protected'), patch.object(R, 'check_prepared_target',
                 side_effect=RuntimeError('PRIVATE_DETAIL_MUST_NOT_ESCAPE')), redirect_stderr(stderr):
            with self.assertRaises(SystemExit):
                R.main()
        self.assertIn('STORAGE STOP: RuntimeError;', stderr.getvalue())
        self.assertNotIn('PRIVATE_DETAIL', stderr.getvalue())
        argv[-1] = 'start-target'
        with patch.object(sys, 'argv', argv), redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit):
                R.main()


class PreparedResumeRoleTest(unittest.TestCase):
    role = FILES.parent / 'roles/state_storage'

    def tasks(self, filename):
        return yaml.safe_load((self.role / 'tasks' / filename).read_text())

    def test_default_strict_boolean_full_scope_and_hash_barrier(self):
        defaults = yaml.safe_load((self.role / 'defaults/main.yml').read_text())
        self.assertIs(defaults['greenwood_storage_resume_prepared_target'], False)
        tasks = self.tasks('preflight.yml')
        assertions = tasks[0]['ansible.builtin.assert']['that']
        self.assertIn('greenwood_storage_resume_prepared_target is boolean', assertions)
        environment = Environment(undefined=StrictUndefined)
        expression = environment.compile_expression('greenwood_storage_resume_prepared_target is boolean')
        for value in (True, False, 'true', 'false', 1, 0, None):
            self.assertIs(expression(greenwood_storage_resume_prepared_target=value), type(value) is bool)
        self.assertIn("ansible_play_hosts_all | sort == groups['all'] | sort", assertions)
        self.assertEqual(tasks[-1]['ansible.builtin.set_fact'],
                         {'greenwood_storage_checked_sha256': '{{ greenwood_storage_plan_sha256 }}'})
        self.assertEqual(tasks[-2]['ansible.builtin.assert']['that'], 'greenwood_storage_probe.rc == 0')
        self.assertIn('greenwood_storage_checked_sha256', str(self.tasks('install_tools.yml')[0]))

    def test_inline_dispatch_only_changes_node4_when_resume_is_true(self):
        task = next(task for task in self.tasks('preflight.yml') if 'ansible.builtin.command' in task)
        source = task['ansible.builtin.command']['argv'][2]
        environment = Environment(undefined=StrictUndefined)
        environment.filters['to_json'] = json.dumps

        def lookup(kind, path):
            self.assertEqual(kind, 'file')
            text = Path(path).read_text()
            if path.endswith('prepare_state_storage.py'):
                text += '\ndef main(): print("ordinary")\n'
            elif path.endswith('validate_prepared_storage_target.py'):
                text += '\ndef main(): print("prepared")\n'
            return text

        for resume in (False, True):
            for node in (*P.CLIENTS, 'node-4'):
                with self.subTest(resume=resume, node=node):
                    rendered = environment.from_string(source).render(
                        role_path=str(self.role), lookup=lookup,
                        greenwood_storage_resume_prepared_target=resume, inventory_hostname=node)
                    result = subprocess.run([sys.executable, '-c', rendered], capture_output=True,
                                            text=True, timeout=10)
                    self.assertEqual(result.returncode, 0, result.stderr)
                    self.assertEqual(result.stdout.strip(), 'prepared' if resume and node == 'node-4' else 'ordinary')
        self.assertIs(task['changed_when'], False)
        self.assertIs(task['check_mode'], False)
        self.assertIs(task['no_log'], True)
        self.assertNotIn('validate_prepared_storage_target.py', str(self.tasks('install_tools.yml')))

    def test_resume_does_not_bypass_existing_client_session_mount_or_node_records(self):
        with patch.object(S, 'machine'), patch.object(S, 'check_network'), patch.object(S, 'offline_services'), \
             patch.object(S, 'protected'), patch.object(Path, 'exists', return_value=True):
            with self.assertRaisesRegex(P.PlanError, 'STATE_VOLUME_ALREADY_STAGED_REVIEW_REQUIRED'):
                S.check_client(public_plan(), 'node-1')
        for session in ('session present', ''):
            with self.subTest(session=bool(session)), patch.object(S, 'machine'), \
                 patch.object(S, 'check_network'), patch.object(S, 'offline_services'), \
                 patch.object(S, 'protected'), patch.object(Path, 'exists', return_value=False), \
                 patch.object(Path, 'read_text', return_value='InitiatorName=' + public_plan()['clients']['node-1']['initiator_iqn']), \
                 patch.object(S, 'chap'), patch.object(S, 'run', return_value=session), \
                 patch.object(S, 'unused_iscsi_records', side_effect=P.PlanError('EXISTING_ISCSI_NODE_RECORDS_REQUIRE_REVIEW')):
                with self.assertRaisesRegex(P.PlanError, 'EXISTING_ISCSI_SESSION_REQUIRES_REVIEW' if session
                                            else 'EXISTING_ISCSI_NODE_RECORDS_REQUIRE_REVIEW'):
                    S.check_client(public_plan(), 'node-1')


if __name__ == '__main__':
    unittest.main()
