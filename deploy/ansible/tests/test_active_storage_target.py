"""Read-only active-target audits against a synthetic public configfs tree."""
from contextlib import ExitStack, redirect_stderr
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import sys
import tempfile
import unittest
from unittest.mock import patch

from test_prepared_storage_resume import R as prepared
from test_state_storage_provisioning import FILES, P, S, public_plan


sys.modules['validate_prepared_storage_target'] = prepared
spec = importlib.util.spec_from_file_location('validate_active_storage_target',
                                            FILES / 'validate_active_storage_target.py')
A = importlib.util.module_from_spec(spec)
spec.loader.exec_module(A)


def write(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(value + '\n')


class ActiveStorageTargetTest(unittest.TestCase):
    def setUp(self):
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        self.temp = Path(self.stack.enter_context(tempfile.TemporaryDirectory(prefix='gw-active-target-')))
        self.config = self.temp / 'target'
        (self.config / 'core/alua/lu_gps/default_lu_gp').mkdir(parents=True)
        (self.config / 'iscsi/discovery_auth').mkdir(parents=True)
        write(self.config / 'iscsi/discovery_auth/password', 'SECRET_NEVER_READ')
        self.plan = public_plan(); self.approved = P.digest(self.plan)
        self.backstores, self.groups, self.acls = {}, {}, {}
        for index, node in enumerate(P.ISCSI):
            client = self.plan['clients'][node]
            backstore = self.config / 'core' / ('fileio_' + str(7 + index * 2)) / ('greenwood-' + node)
            image = P.BACKING + '/' + node + '.img'
            write(backstore / 'udev_path', image)
            write(backstore / 'wwn/vpd_unit_serial', 'T10 VPD Unit Serial Number: ' + client['filesystem_uuid'])
            write(backstore / 'attrib/emulate_write_cache', '0')
            write(backstore / 'info', 'Status: ACTIVATED  Max Queue Depth: 128  SectorSize: 512\n'
                  + f'TCM FILEIO ID: 0        File: {image}  Size: {P.LUN_BYTES}  Mode: O_DSYNC Async: 0')
            target = self.config / 'iscsi' / client['target_iqn']
            for directory in ('fabric_statistics', 'param'):
                (target / directory).mkdir(parents=True)
            group = target / 'tpgt_1'
            for directory in ('lun', 'np', 'acls', 'attrib', 'auth', 'param'):
                (group / directory).mkdir(parents=True)
            write(group / 'auth/password', 'SECRET_NEVER_READ')
            write(group / 'enable', '1')
            for attribute, value in (('generate_node_acls', '0'), ('cache_dynamic_acls', '0'),
                                     ('demo_mode_write_protect', '1'), ('authentication', '1')):
                write(group / 'attrib' / attribute, value)
            (group / 'np' / (self.plan['target']['address'] + ':3260')).mkdir()
            lun = group / 'lun/lun_0'
            (lun / 'statistics').mkdir(parents=True)
            (lun / 'generated-lun-alias').symlink_to(os.path.relpath(backstore, lun))
            acl = group / 'acls' / client['initiator_iqn']
            for directory in ('attrib', 'auth', 'param', 'fabric_statistics', 'lun_0/statistics'):
                (acl / directory).mkdir(parents=True)
            write(acl / 'auth/password', 'SECRET_NEVER_READ')
            write(acl / 'attrib/authentication', '-1')
            write(acl / 'lun_0/write_protect', '0')
            (acl / 'lun_0/generated-mapped-alias').symlink_to(lun)
            self.backstores[node] = backstore; self.groups[node] = group; self.acls[node] = acl
        self.state = 'SubState=exited\nActiveState=active\nMainPID=0\n'
        self.listener = 'LISTEN 0 256 192.168.72.103:3260 0.0.0.0:*\n'
        self.commands = []

        def run(argv, **kwargs):
            self.commands.append(argv)
            if argv == ['systemctl', 'show', 'greenwood-state-target.service',
                        '-p', 'ActiveState', '-p', 'SubState', '-p', 'MainPID']:
                return self.state
            if argv == ['ss', '-H', '-ltn', 'sport', '=', ':3260']:
                return self.listener
            raise AssertionError('Unexpected operation: ' + repr(argv))

        self.stack.enter_context(patch.object(A, 'CONFIGFS_TARGET', self.config))
        self.common = self.stack.enter_context(patch.object(S, 'check_target'))
        self.backing = self.stack.enter_context(patch.object(prepared, 'check_backing_files'))
        self.stack.enter_context(patch.object(S, 'run', side_effect=run))

    def check(self):
        A.check_active_target(self.plan, self.approved)

    def test_exact_public_configuration_passes_without_auth_reads_or_mutations(self):
        original_open, original_import = os.open, __import__
        reads = []

        def open_read_only(path, flags, *args, **kwargs):
            self.assertFalse(flags & (os.O_WRONLY | os.O_RDWR | os.O_CREAT | os.O_TRUNC))
            self.assertFalse({'auth', 'discovery_auth'} & set(Path(path).parts))
            reads.append(str(path))
            return original_open(path, flags, *args, **kwargs)

        def no_target_library(name, *args, **kwargs):
            self.assertFalse(name.startswith('rtslib'))
            return original_import(name, *args, **kwargs)

        with patch.object(os, 'open', side_effect=open_read_only), \
             patch('builtins.__import__', side_effect=no_target_library), \
             patch.object(os, 'mkdir', side_effect=AssertionError('must not create')), \
             patch.object(os, 'unlink', side_effect=AssertionError('must not remove')), \
             patch.object(S, 'chap', side_effect=AssertionError('must not read credentials')):
            self.check()
        self.common.assert_called_once_with(self.plan, new=False)
        self.backing.assert_called_once_with(self.plan, self.approved)
        self.assertEqual(len(self.commands), 2)
        self.assertTrue(any(path.endswith('/info') for path in reads))

    def test_foreign_partial_missing_or_duplicate_backstores_stop(self):
        for relative in ('core/fileio_23', 'core/iblock_24/foreign',
                         'core/fileio_25/foreign', 'core/fileio_26/greenwood-node-1'):
            added = self.config / relative
            added.mkdir(parents=True)
            with self.subTest(relative=relative), self.assertRaisesRegex(P.PlanError, 'ACTIVE_BACKSTORE_LAYOUT_MISMATCH'):
                self.check()
            shutil.rmtree(self.config / relative.split('/')[0] / relative.split('/')[1])
        shutil.rmtree(self.backstores['node-3'].parent)
        with self.assertRaisesRegex(P.PlanError, 'ACTIVE_BACKSTORE_LAYOUT_MISMATCH'):
            self.check()

    def test_foreign_targets_extra_tpg_acl_lun_or_portal_stop(self):
        group = self.groups['node-1']; acl = self.acls['node-1']
        paths = [self.config / 'loopback/naa.foreign', self.config / 'iscsi/iqn.foreign',
                 group.parent / 'tpgt_2', group / 'acls/iqn.foreign', group / 'lun/lun_1',
                 acl / 'lun_1', group / 'np/0.0.0.0:3260']
        for path in paths:
            path.mkdir(parents=True)
            with self.subTest(path=path.name), self.assertRaisesRegex(P.PlanError, 'ACTIVE_TARGET_LAYOUT_MISMATCH'):
                self.check()
            path.rmdir()

    def test_disabled_or_weakened_authentication_policies_stop(self):
        group = self.groups['node-1']; acl = self.acls['node-1']
        cases = [(group / 'enable', '0', 'ACTIVE_TARGET_DISABLED'),
                 (group / 'attrib/authentication', '0', 'ACTIVE_TARGET_POLICY_MISMATCH'),
                 (group / 'attrib/generate_node_acls', '1', 'ACTIVE_TARGET_POLICY_MISMATCH'),
                 (group / 'attrib/cache_dynamic_acls', '1', 'ACTIVE_TARGET_POLICY_MISMATCH'),
                 (group / 'attrib/demo_mode_write_protect', '0', 'ACTIVE_TARGET_POLICY_MISMATCH'),
                 (acl / 'attrib/authentication', '0', 'ACTIVE_ACL_AUTHENTICATION_MISMATCH'),
                 (acl / 'lun_0/write_protect', '1', 'ACTIVE_LUN_WRITE_PROTECTED')]
        for path, value, message in cases:
            previous = path.read_text(); path.write_text(value)
            with self.subTest(path=path), self.assertRaisesRegex(P.PlanError, message):
                self.check()
            path.write_text(previous)

    def test_backend_path_serial_cache_capacity_and_durability_must_match(self):
        backstore = self.backstores['node-1']
        for attribute, replacement, message in (
                ('udev_path', '/unapproved.img', 'ACTIVE_BACKSTORE_PATH_MISMATCH'),
                ('wwn/vpd_unit_serial', 'T10 VPD Unit Serial Number: wrong', 'ACTIVE_BACKSTORE_SERIAL_MISMATCH'),
                ('attrib/emulate_write_cache', '1', 'ACTIVE_BACKSTORE_CACHE_MISMATCH')):
            path = backstore / attribute; previous = path.read_text(); path.write_text(replacement)
            with self.subTest(attribute=attribute), self.assertRaisesRegex(P.PlanError, message):
                self.check()
            path.write_text(previous)
        path = backstore / 'info'; previous = path.read_text()
        for old, replacement in ((P.BACKING + '/node-1.img', '/different.img'),
                                 (str(P.LUN_BYTES), '4096'), ('O_DSYNC', 'Buffered-WCE'),
                                 ('Async: 0', 'Async: 1'), ('ACTIVATED', 'DEACTIVATED')):
            path.write_text(previous.replace(old, replacement))
            with self.subTest(field=old), self.assertRaisesRegex(P.PlanError, 'ACTIVE_BACKSTORE_INFO_MISMATCH'):
                self.check()
        path.write_text(previous + 'Size: 4096\n')
        with self.assertRaisesRegex(P.PlanError, 'ACTIVE_BACKSTORE_INFO_MISMATCH'):
            self.check()

    def test_lun_and_acl_links_cannot_point_to_other_nodes_or_be_ambiguous(self):
        paths = [self.groups['node-1'] / 'lun/lun_0/generated-lun-alias',
                 self.acls['node-1'] / 'lun_0/generated-mapped-alias']
        for path in paths:
            previous = os.readlink(path); path.unlink(); path.symlink_to(self.backstores['node-2'])
            with self.subTest(path=path), self.assertRaisesRegex(P.PlanError, 'ACTIVE_LUN_LINK_MISMATCH'):
                self.check()
            path.unlink(); path.symlink_to(previous)
            duplicate = path.parent / 'another-link'; duplicate.symlink_to(previous)
            with self.assertRaisesRegex(P.PlanError, 'ACTIVE_LUN_LINK_MISMATCH'):
                self.check()
            duplicate.unlink()

    def test_missing_or_symlinked_public_attribute_stops(self):
        path = self.backstores['node-1'] / 'udev_path'
        path.unlink(); path.symlink_to(self.config / 'iscsi/discovery_auth/password')
        with self.assertRaisesRegex(P.PlanError, 'ACTIVE_TARGET_ATTRIBUTE_REQUIRED'):
            self.check()
        with self.assertRaisesRegex(P.PlanError, 'AUTH_CONTENT_READ_FORBIDDEN'):
            A.public_text(self.config / 'iscsi/discovery_auth/password')

    def test_inactive_or_running_target_service_stops(self):
        for state in ('ActiveState=failed\nSubState=failed\nMainPID=0\n',
                      'ActiveState=active\nSubState=running\nMainPID=123\n',
                      'ActiveState=active\nSubState=exited\n'):
            self.state = state
            with self.subTest(state=state), self.assertRaisesRegex(P.PlanError, 'ACTIVE_TARGET_SERVICE_REQUIRED'):
                self.check()

    def test_listener_formats_normalize_but_wildcard_foreign_and_missing_stop(self):
        for listener in ('LISTEN 0 256 192.168.72.103:3260 0.0.0.0:*',
                         'tcp LISTEN 0 256 192.168.72.103:3260 0.0.0.0:*',
                         'LISTEN 0 256 [::ffff:192.168.72.103]:3260 [::]:*'):
            self.listener = listener
            with self.subTest(listener=listener):
                self.check()
        for listener in ('', 'LISTEN 0 256 0.0.0.0:3260 0.0.0.0:*',
                         'LISTEN 0 256 [::]:3260 [::]:*',
                         'LISTEN 0 256 192.168.72.104:3260 0.0.0.0:*',
                         'LISTEN 0 256 *:3260 *:*',
                         'UNPARSEABLE',
                         'LISTEN 0 256 192.168.72.103:3260 0.0.0.0:*\nLISTEN 0 256 0.0.0.0:3260 0.0.0.0:*'):
            self.listener = listener
            with self.subTest(listener=listener), self.assertRaisesRegex(P.PlanError, 'ACTIVE_TARGET_LISTENER_'):
                self.check()

    def test_shared_backing_failure_propagates_before_configfs_or_service_probe(self):
        self.backing.side_effect = P.PlanError('PREPARED_RECEIPT_MISMATCH')
        with self.assertRaisesRegex(P.PlanError, 'PREPARED_RECEIPT_MISMATCH'):
            self.check()
        self.assertEqual(self.commands, [])

    def test_cli_sanitizes_errors_and_only_accepts_read_only_action(self):
        argv = ['validator', '--plan-json', json.dumps(self.plan), '--approved-sha256', self.approved,
                '--node', 'node-4', '--action', 'check']
        stderr = io.StringIO()
        with patch.object(sys, 'argv', argv), patch.object(os, 'geteuid', return_value=0), \
             patch.object(S, 'protected'), patch.object(A, 'check_active_target',
                 side_effect=RuntimeError('DO_NOT_PRINT_PRIVATE_VALUES')), redirect_stderr(stderr):
            with self.assertRaises(SystemExit):
                A.main()
        self.assertIn('STORAGE STOP: RuntimeError;', stderr.getvalue())
        self.assertNotIn('PRIVATE_VALUES', stderr.getvalue())
        argv[-1] = 'start-target'
        with patch.object(sys, 'argv', argv), redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit):
                A.main()


if __name__ == '__main__':
    unittest.main()
