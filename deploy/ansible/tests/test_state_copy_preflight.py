"""Public synthetic metadata only; never contact a host, disk, or credential."""
from contextlib import ExitStack, redirect_stderr, redirect_stdout
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import sys
from types import SimpleNamespace
import unittest
from unittest.mock import patch
import uuid


FILES = Path(__file__).resolve().parents[1] / 'files'


def load(name):
    spec = importlib.util.spec_from_file_location(name, FILES / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


with patch.dict(sys.modules):
    P = load('storage_plan')
    sys.modules['storage_plan'] = P
    S = load('prepare_state_storage')
    C = load('prepare_durable_state')
    sys.modules['prepare_state_storage'] = S
    sys.modules['prepare_durable_state'] = C
    V = load('validate_state_copy_preflight')


def public_plan():
    plan = {'version': 1, 'chain_id': 'greenwood-prod-v1', 'target': {
        'node': 'node-4', 'machine_id': f'{4:032x}', 'address': '192.168.72.103',
        'filesystem_uuid': str(uuid.UUID(int=104)), 'backing_directory': P.BACKING,
        'reserve_free_bytes': 128 * 1024 ** 3}, 'clients': {}}
    for node in P.CLIENTS:
        number = int(node[-1])
        client = {'machine_id': f'{number:032x}', 'filesystem_uuid': str(uuid.UUID(int=100+number)),
                  'device_bytes': P.LUN_BYTES if node in P.ISCSI else 32 * 1024 ** 3,
                  'kind': 'iscsi' if node in P.ISCSI else 'sd'}
        if node in P.ISCSI:
            client.update(expected_existing_iqn='iqn.2004-10.com.ubuntu:01:d066b4bf9ef',
                          initiator_iqn=f'iqn.2026-11.com.greenwoodblockchain:greenwood-prod-v1.{node}.initiator',
                          target_iqn=f'iqn.2026-11.com.greenwoodblockchain:greenwood-prod-v1.{node}.state',
                          chap_file=f'/root/.secrets/greenwood-storage/{node}.json')
        else:
            client['empty_media_approved'] = True
        plan['clients'][node] = client
    return plan


def metadata(*, directory=True, uid=0, gid=0, mode=None, device=None, links=None):
    return SimpleNamespace(st_mode=(stat.S_IFDIR if directory else stat.S_IFREG)
                           | (mode if mode is not None else (0o755 if directory else 0o444)),
                           st_uid=uid, st_gid=gid,
                           st_nlink=links if links is not None else (2 if directory else 1),
                           st_size=33, st_dev=device if device is not None else os.makedev(0, 12),
                           st_ino=7)


class SourceMetadataTest(unittest.TestCase):
    def setUp(self):
        self.plan = public_plan()
        self.paths = {path: metadata() for path in ('/', '/etc', '/var', '/var/lib', '/etc/iscsi')}
        self.paths['/etc/machine-id'] = metadata(directory=False)
        for node in P.CLIENTS:
            for path in P.sources(node):
                self.paths[path] = metadata()
        self.visited = []
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        self.stack.enter_context(patch.object(Path, 'lstat', autospec=True, side_effect=self.lstat))
        # Every accidental content read, recursion, command, or write fails.
        for name in ('read_text', 'read_bytes', 'iterdir', 'glob', 'open', 'chmod', 'mkdir',
                     'write_text', 'write_bytes', 'unlink'):
            self.stack.enter_context(patch.object(Path, name, side_effect=AssertionError(name + ' forbidden')))
        for name in ('open', 'chmod', 'chown', 'mkdir', 'replace'):
            self.stack.enter_context(patch.object(os, name, side_effect=AssertionError(name + ' forbidden')))
        self.stack.enter_context(patch.object(S, 'run', side_effect=AssertionError('commands forbidden')))

    def lstat(self, path):
        key = str(path)
        self.visited.append(key)
        if key not in self.paths:
            raise FileNotFoundError(key)
        return self.paths[key]

    def test_root_owned_0444_identity_and_all_six_source_scopes_pass_without_reads(self):
        for node in P.CLIENTS:
            with self.subTest(node=node):
                self.visited.clear()
                result = V.check_source_metadata(self.plan, node)
                self.assertEqual(result['status'], 'PASS')
                self.assertEqual(set(result['sources']), set(P.sources(node)))
                self.assertEqual(self.visited[:3], ['/', '/etc', '/etc/machine-id'])
                self.assertNotIn(P.MOUNT + '/lost+found', self.visited)

    def test_incident_identity_is_rejected_with_metadata_before_any_source_access(self):
        self.paths['/etc/machine-id'] = metadata(directory=False, uid=1000, gid=1000, mode=0o775)
        with self.assertRaisesRegex(P.PlanError,
                                   'UNPROTECTED_PATH path=/etc/machine-id uid=1000 gid=1000 mode=0775'):
            V.check_source_metadata(self.plan, 'node-1')
        self.assertEqual(self.visited, ['/', '/etc', '/etc/machine-id'])

    def test_writable_or_symlink_parent_fails_before_child_metadata(self):
        for field, value in [('st_mode', stat.S_IFDIR | 0o775), ('st_mode', stat.S_IFLNK | 0o777),
                             ('st_uid', 1000)]:
            self.paths['/var'] = metadata()
            setattr(self.paths['/var'], field, value)
            self.visited.clear()
            with self.assertRaisesRegex(P.PlanError, 'UNPROTECTED_PATH path=/var '):
                V.check_source_metadata(self.plan, 'node-1')
            self.assertNotIn('/var/lib', self.visited)

    def test_only_missing_leaf_is_allowed(self):
        del self.paths['/var/lib/greenwood-provider']
        result = V.check_source_metadata(self.plan, 'node-1')
        self.assertFalse(result['sources']['/var/lib/greenwood-provider'])
        del self.paths['/var/lib']
        with self.assertRaisesRegex(P.PlanError, 'REQUIRED_PATH_MISSING path=/var/lib'):
            V.check_source_metadata(self.plan, 'node-1')

    def test_service_owned_descendants_and_source_metadata_are_preserved(self):
        child = '/var/lib/greenwood-provider/workers'
        self.paths[child] = metadata(uid=999, gid=988, mode=0o700)
        before = {name: vars(value).copy() for name, value in self.paths.items()}
        V.check_source_metadata(self.plan, 'node-1')
        self.assertNotIn(child, self.visited)
        self.assertEqual(before, {name: vars(value) for name, value in self.paths.items()})
        self.paths['/var/lib/greenwood-provider'].st_uid = 999
        with self.assertRaisesRegex(P.PlanError, 'UNPROTECTED_PATH path=/var/lib/greenwood-provider '):
            V.check_source_metadata(self.plan, 'node-1')

    def test_existing_mount_fixed_children_checked_without_enumeration(self):
        self.paths[P.MOUNT] = metadata()
        self.paths[P.MOUNT + '/lost+found'] = metadata(mode=0o700)
        self.paths[P.MOUNT + '/' + C.MARKER] = metadata(directory=False, mode=0o600)
        self.paths[P.MOUNT + '/greenwood'] = metadata()
        V.check_source_metadata(self.plan, 'node-1')
        for suffix in ('/lost+found', '/' + C.MARKER, '/greenwood', '/containerd'):
            self.assertIn(P.MOUNT + suffix, self.visited)
        self.paths[P.MOUNT + '/lost+found'].st_mode = stat.S_IFLNK | 0o777
        with self.assertRaisesRegex(P.PlanError, 'UNPROTECTED_PATH path=.*/lost\\+found '):
            V.check_source_metadata(self.plan, 'node-1')

    def test_linked_identity_and_marker_fail(self):
        self.paths['/etc/machine-id'].st_nlink = 2
        with self.assertRaisesRegex(P.PlanError, 'LINKED_MACHINE_ID'):
            V.check_source_metadata(self.plan, 'node-1')
        self.paths['/etc/machine-id'].st_nlink = 1
        self.paths[P.MOUNT] = metadata()
        self.paths[P.MOUNT + '/' + C.MARKER] = metadata(directory=False, links=2)
        with self.assertRaisesRegex(P.PlanError, 'INVALID_COMPLETION_MANIFEST_METADATA'):
            V.check_source_metadata(self.plan, 'node-1')


class MountedClientTest(unittest.TestCase):
    def setUp(self):
        self.plan = public_plan()
        self.client = self.plan['clients']['node-1']
        self.volume_device = os.makedev(8, 1)
        self.mounted = {'target': P.MOUNT, 'source': '/dev/fixture', 'fstype': 'ext4',
                        'uuid': self.client['filesystem_uuid'], 'options': 'rw,relatime',
                        'id': 47, 'maj:min': '8:1', 'fsroot': '/'}
        self.mounts = [{'target': '/', 'maj:min': '0:12', 'id': 12},
                       {'target': P.MOUNT, 'maj:min': '8:1', 'id': 47}]
        self.session = 'tcp: [1] 192.168.72.103:3260,1 ' + self.client['target_iqn'] + ' (non-flash)\n'
        self.initiator = self.client['initiator_iqn']
        self.source_device = os.makedev(0, 12)
        self.commands = []
        self.reads = []
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        self.source_check = self.stack.enter_context(patch.object(V, 'check_source_metadata', return_value={'status': 'PASS'}))
        self.stack.enter_context(patch.object(S, 'protected', side_effect=self.protected))
        self.stack.enter_context(patch.object(Path, 'read_text', autospec=True, side_effect=self.read_text))
        self.stack.enter_context(patch.object(Path, 'stat', return_value=SimpleNamespace(st_rdev=self.volume_device)))
        self.network = self.stack.enter_context(patch.object(S, 'check_network'))
        self.stopped = self.stack.enter_context(patch.object(C, 'require_stopped'))
        self.stack.enter_context(patch.object(S, 'filesystem', return_value=self.mounted))
        self.block = self.stack.enter_context(patch.object(S, 'block_for_uuid', return_value='/dev/fixture'))
        self.stack.enter_context(patch.object(S, 'run', side_effect=self.run_command))
        self.check_copy = self.stack.enter_context(patch.object(C, 'copy_state', return_value={
            'status': 'READY', 'copy_state': 'not_started'}))
        self.stack.enter_context(patch.object(S, 'chap', side_effect=AssertionError('auth forbidden')))
        for name in ('chown', 'chmod', 'mkdir', 'replace'):
            self.stack.enter_context(patch.object(os, name, side_effect=AssertionError(name + ' forbidden')))

    def protected(self, path, **kwargs):
        path = str(path)
        if path in ('/etc/iscsi/initiatorname.iscsi', '/etc/machine-id'):
            return metadata(directory=False)
        return metadata(device=self.volume_device if path == P.MOUNT else self.source_device)

    def read_text(self, path, *args, **kwargs):
        path = str(path)
        self.reads.append(path)
        if path == '/etc/machine-id':
            return self.client['machine_id'] + '\n'
        if path == '/etc/iscsi/initiatorname.iscsi':
            return 'InitiatorName=' + self.initiator + '\n'
        raise AssertionError('unexpected content read: ' + path)

    def run_command(self, argv, **kwargs):
        self.commands.append(argv)
        if argv == ['iscsiadm', '-m', 'session']:
            return self.session
        if argv == ['findmnt', '--kernel', '--list', '--json', '--output', 'TARGET,MAJ:MIN,ID']:
            return json.dumps({'filesystems': self.mounts})
        raise AssertionError('unexpected command: ' + repr(argv))

    def test_only_exact_empty_node_1_state_passes_read_only(self):
        result = V.check_mounted_client(self.plan, 'node-1')
        self.assertEqual(result['copy_state'], 'not_started')
        self.source_check.assert_called_once_with(self.plan, 'node-1')
        self.network.assert_called_once_with()
        self.stopped.assert_called_once_with()
        self.block.assert_called_once_with(self.client['filesystem_uuid'], self.client['device_bytes'])
        self.check_copy.assert_called_once_with(P.copy_manifest(self.plan, 'node-1'), apply=False)
        self.assertEqual(self.reads, ['/etc/machine-id', '/etc/iscsi/initiatorname.iscsi'])
        self.assertTrue(all('-P' not in argv and 'node' not in argv for argv in self.commands))

    def test_other_clients_rejected_before_any_inspection(self):
        for node in ('node-2', 'node-3', 'node-4', 'node-5', 'node-6', 'node-7'):
            with self.assertRaisesRegex(P.PlanError, 'ONLY_NODE_1'):
                V.check_mounted_client(self.plan, node)
        self.source_check.assert_not_called()
        self.assertEqual(self.reads, [])

    def test_metadata_failure_precedes_public_content_reads(self):
        self.source_check.side_effect = P.PlanError('UNPROTECTED_PATH path=/etc/machine-id')
        with self.assertRaisesRegex(P.PlanError, 'UNPROTECTED_PATH'):
            V.check_mounted_client(self.plan, 'node-1')
        self.assertEqual(self.reads, [])
        self.assertEqual(self.commands, [])
        self.check_copy.assert_not_called()

    def test_wrong_identity_session_target_portal_transport_and_multiple_sessions_fail(self):
        self.initiator = self.plan['clients']['node-2']['initiator_iqn']
        with self.assertRaisesRegex(P.PlanError, 'INITIATOR_IQN_MISMATCH'):
            V.check_mounted_client(self.plan, 'node-1')
        self.initiator = self.client['initiator_iqn']
        original = self.session
        for text in ('', original + original, original.replace(':3260,1', ':3261,1'),
                     original.replace('72.103', '72.104'), original.replace('node-1.state', 'node-2.state'),
                     original.replace('tcp:', 'iser:')):
            self.session = text
            with self.subTest(session=text), self.assertRaisesRegex(P.PlanError, 'EXACT_APPROVED_ISCSI_SESSION'):
                V.check_mounted_client(self.plan, 'node-1')
        self.check_copy.assert_not_called()

    def test_wrong_volume_uuid_filesystem_readonly_device_and_capacity_fail(self):
        for key, value in [('uuid', str(uuid.UUID(int=999))), ('fstype', 'nfs4'), ('options', 'ro,relatime'),
                           ('maj:min', '8:2')]:
            previous = self.mounted[key]
            self.mounted[key] = value
            with self.subTest(key=key), self.assertRaises(P.PlanError):
                V.check_mounted_client(self.plan, 'node-1')
            self.mounted[key] = previous
        self.block.side_effect = P.PlanError('BLOCK_DEVICE_CAPACITY_MISMATCH')
        with self.assertRaisesRegex(P.PlanError, 'CAPACITY_MISMATCH'):
            V.check_mounted_client(self.plan, 'node-1')
        self.check_copy.assert_not_called()

    def test_bound_source_nested_mount_volume_alias_and_mount_stack_fail(self):
        for record, code in [({'target': '/var/lib/greenwood', 'maj:min': '8:1', 'id': 60}, 'CANONICAL_SOURCE_MOUNT'),
                             ({'target': '/var/lib/containerd/snapshot', 'maj:min': '0:20', 'id': 60}, 'CANONICAL_SOURCE_MOUNT'),
                             ({'target': P.MOUNT + '/unexpected', 'maj:min': '0:20', 'id': 60}, 'NESTED_STATE_MOUNT'),
                             ({'target': '/mnt/alias', 'maj:min': '8:1', 'id': 60}, 'STATE_VOLUME_ALIAS'),
                             ({'target': P.MOUNT, 'maj:min': '0:20', 'id': 60}, 'STATE_MOUNT_STACK')]:
            self.mounts.append(record)
            with self.subTest(record=record), self.assertRaisesRegex(P.PlanError, code):
                V.check_mounted_client(self.plan, 'node-1')
            self.mounts.pop()
        self.source_device = self.volume_device
        with self.assertRaisesRegex(P.PlanError, 'CANONICAL_SOURCE_ON_STATE_VOLUME'):
            V.check_mounted_client(self.plan, 'node-1')
        self.check_copy.assert_not_called()

    def test_missing_or_duplicate_visible_mount_fails(self):
        self.mounts.pop()
        with self.assertRaisesRegex(P.PlanError, 'EXACT_VISIBLE_STATE_MOUNT'):
            V.check_mounted_client(self.plan, 'node-1')
        self.mounts.extend([{'target': P.MOUNT, 'maj:min': '8:1', 'id': 47}] * 2)
        with self.assertRaisesRegex(P.PlanError, 'EXACT_VISIBLE_STATE_MOUNT'):
            V.check_mounted_client(self.plan, 'node-1')

    def test_any_started_or_complete_copy_marker_prevents_this_resume(self):
        for state in ('copying', 'complete'):
            self.check_copy.return_value = {'status': 'READY', 'copy_state': state}
            with self.assertRaisesRegex(P.PlanError, 'COPY_ALREADY_STARTED'):
                V.check_mounted_client(self.plan, 'node-1')
        self.check_copy.side_effect = C.CopyError('COPY_MANIFEST_MISMATCH')
        with self.assertRaisesRegex(C.CopyError, 'COPY_MANIFEST_MISMATCH'):
            V.check_mounted_client(self.plan, 'node-1')


class CommandLineTest(unittest.TestCase):
    def test_cli_hash_root_action_and_bounded_error_handling(self):
        plan = public_plan()
        argv = ['validate_state_copy_preflight.py', '--plan-json', json.dumps(plan),
                '--approved-sha256', P.digest(plan), '--node', 'node-1', '--action', 'source-metadata']
        with patch.object(sys, 'argv', argv), patch.object(os, 'geteuid', return_value=0), \
             patch.object(V, 'check_source_metadata', return_value={'status': 'PASS', 'node': 'node-1'}) as check:
            output = io.StringIO()
            with redirect_stdout(output):
                V.main()
            self.assertEqual(json.loads(output.getvalue())['plan_sha256'], P.digest(plan))
            check.assert_called_once_with(plan, 'node-1')
            check.side_effect = OSError('synthetic private subprocess detail')
            errors = io.StringIO()
            with redirect_stderr(errors), self.assertRaises(SystemExit):
                V.main()
            self.assertIn('STORAGE STOP: OSError;', errors.getvalue())
            self.assertNotIn('synthetic private', errors.getvalue())
        argv[argv.index('--approved-sha256') + 1] = '0' * 64
        with patch.object(sys, 'argv', argv), patch.object(os, 'geteuid', return_value=0), \
             patch.object(V, 'check_source_metadata') as check, redirect_stderr(io.StringIO()), \
             self.assertRaises(SystemExit):
            V.main()
        check.assert_not_called()


if __name__ == '__main__':
    unittest.main()
