"""Bounded storage-plan/provisioning fixtures; never invokes real media tools."""
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import shutil
import subprocess
import sys
import tempfile
from types import ModuleType, SimpleNamespace
import unittest
from unittest.mock import Mock, patch
import uuid
from contextlib import redirect_stderr
import configparser
from jinja2 import Environment, StrictUndefined
import yaml


FILES = Path(__file__).resolve().parents[1] / 'files'
spec = importlib.util.spec_from_file_location('storage_plan', FILES / 'storage_plan.py')
P = importlib.util.module_from_spec(spec)
spec.loader.exec_module(P)
sys.modules['storage_plan'] = P
spec = importlib.util.spec_from_file_location('prepare_state_storage', FILES / 'prepare_state_storage.py')
S = importlib.util.module_from_spec(spec)
spec.loader.exec_module(S)


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


class PublicStoragePlanTest(unittest.TestCase):
    def test_exact_approved_plan_and_role_scopes(self):
        plan = public_plan()
        self.assertIs(P.validate(plan, P.digest(plan)), plan)
        self.assertEqual(P.sources('node-5'), ['/var/lib/containerd', '/var/lib/greenwood-control-plane'])
        self.assertNotIn('/var/lib/greenwood', P.sources('node-6'))
        self.assertEqual(len(P.sources('node-1')), 4)
        for node in P.CLIENTS:
            manifest = P.copy_manifest(plan, node)
            self.assertEqual(manifest['plan_sha256'], P.digest({k: v for k, v in manifest.items() if k != 'plan_sha256'}))
            self.assertEqual(manifest['expected_machine_id'], plan['clients'][node]['machine_id'])

    def test_changed_scope_identity_capacity_and_boolean_rejected(self):
        mutations = [lambda p: p.update(version=True),
                     lambda p: p['target'].update(address='192.168.72.104'),
                     lambda p: p['target'].update(reserve_free_bytes=0),
                     lambda p: p['clients']['node-1'].update(device_bytes=1),
                     lambda p: p['clients']['node-5'].update(empty_media_approved='yes'),
                     lambda p: p['clients']['node-2'].update(filesystem_uuid=p['clients']['node-1']['filesystem_uuid']),
                     lambda p: p['clients']['node-2'].update(initiator_iqn=p['clients']['node-1']['initiator_iqn'])]
        for mutate in mutations:
            plan = public_plan(); mutate(plan)
            with self.subTest(plan=plan):
                with self.assertRaises(P.PlanError):
                    P.validate(plan, P.digest(plan))
        with self.assertRaises(P.PlanError):
            P.validate(public_plan(), '0' * 64)

    def test_plain_and_ansible_wrapped_public_numbers(self):
        filter_path = FILES.parent / 'roles/state_storage/filter_plugins/greenwood_state_storage.py'
        try:
            import ansible  # noqa: F401
        except ImportError:
            self.skipTest('Ansible filter integration requires Ansible')
        spec = importlib.util.spec_from_file_location('state_storage_filter_fixture', filter_path)
        F = importlib.util.module_from_spec(spec); spec.loader.exec_module(F)
        class TaggedInt(int):
            pass
        plan = public_plan()
        plan['version'] = TaggedInt(1)
        plan['target']['reserve_free_bytes'] = TaggedInt(128 * 1024 ** 3)
        self.assertEqual(F.checked(plan, P.digest(plan))['version'], 1)


class ProvisioningGuardTest(unittest.TestCase):
    def test_protected_path_reports_only_failing_metadata_before_descendant_access(self):
        visited = []
        def metadata(path):
            visited.append(str(path))
            mode, uid, gid = (0o775, 1000, 1000) if str(path) == '/etc/iscsi' else (0o755, 0, 0)
            return SimpleNamespace(st_mode=stat.S_IFDIR | mode, st_uid=uid, st_gid=gid, st_nlink=2)
        with patch.object(Path, 'lstat', metadata):
            with self.assertRaises(P.PlanError) as caught:
                S.protected('/etc/iscsi/nodes', absent=True, directory=True)
        self.assertEqual(str(caught.exception),
                         'UNPROTECTED_PATH path=/etc/iscsi uid=1000 gid=1000 mode=0775 type=directory links=2')
        self.assertEqual(visited, ['/', '/etc', '/etc/iscsi'])

    def test_inaccessible_metadata_is_not_treated_as_absent(self):
        def metadata(path):
            if str(path) == '/etc/iscsi':
                raise PermissionError(13, 'synthetic-private-detail')
            return SimpleNamespace(st_mode=stat.S_IFDIR | 0o755, st_uid=0, st_gid=0, st_nlink=2)
        with patch.object(Path, 'lstat', metadata):
            with self.assertRaises(P.PlanError) as caught:
                S.protected('/etc/iscsi/nodes', absent=True, directory=True)
        self.assertEqual(str(caught.exception), 'METADATA_UNAVAILABLE path=/etc/iscsi errno=13')

    def test_initiator_file_protection_precedes_reading_or_changing_public_identity(self):
        def guard(path, **kwargs):
            if str(path) == '/etc/iscsi/initiatorname.iscsi':
                raise P.PlanError('UNPROTECTED_PATH public initiator fixture')
        with patch.object(S, 'machine'), patch.object(S, 'check_network'), patch.object(S, 'offline_services'), \
             patch.object(S, 'protected', side_effect=guard), patch.object(Path, 'exists', return_value=False), \
             patch.object(Path, 'read_text', side_effect=AssertionError('must fail before reading identity')), \
             patch.object(S, 'chap', side_effect=AssertionError('must fail before credential inspection')):
            with self.assertRaisesRegex(P.PlanError, 'UNPROTECTED_PATH'):
                S.check_client(public_plan(), 'node-1')

    @unittest.skipUnless(shutil.which('mkfs.ext4') and shutil.which('blkid'), 'ext4 tools required')
    def test_real_new_file_format_preserves_preallocated_capacity(self):
        plan = public_plan()
        size = 32 * 1024 ** 2
        with tempfile.TemporaryDirectory(prefix='gw-ext4-new-files-fixture-') as temp:
            backing = Path(temp) / 'new-volume-files'
            with patch.object(S, 'BACKING', str(backing)), patch.object(S, 'LUN_BYTES', size), \
                 patch.object(S, 'check_target'):
                S.create_backing(plan, P.digest(plan))
            for node in P.ISCSI:
                file = backing / (node + '.img')
                self.assertEqual(file.stat().st_size, size)
                self.assertGreaterEqual(file.stat().st_blocks * 512, size)
                self.assertEqual(S.run(['blkid', '-p', '-s', 'UUID', '-o', 'value', str(file)]).strip(),
                                 plan['clients'][node]['filesystem_uuid'])

    def test_unapproved_sd_can_be_reviewed_but_not_applied(self):
        plan = public_plan(); plan['clients']['node-5']['empty_media_approved'] = False
        P.validate(plan, P.digest(plan))
        argv = ['prepare_state_storage.py', '--plan-json', json.dumps(plan), '--approved-sha256', P.digest(plan),
                '--node', 'node-5', '--action', 'connect-client', '--approve-storage-changes']
        output = io.StringIO()
        with patch.object(sys, 'argv', argv), patch.object(os, 'geteuid', return_value=0), patch.object(S, 'protected'), \
             patch.object(S, 'connect_client', side_effect=AssertionError('must not mutate')), redirect_stderr(output):
            with self.assertRaises(SystemExit):
                S.main()
        self.assertIn('EXISTING_SD_CONTENT_APPROVAL_REQUIRED', output.getvalue())

    def test_sd_probe_is_read_only_and_rejects_nonempty_media(self):
        commands = []
        listing = '/2/040755/0/0/.//\n/2/040755/0/0/..//\n/11/040700/0/0/lost+found//\n'
        recovered = '/11/040700/0/0/.//\n/2/040755/0/0/..//\n'
        def run(argv, **kwargs):
            commands.append(argv)
            if argv[0] == 'findmnt': return ''
            if argv[0] == 'debugfs': return recovered if '/lost+found' in argv[2] else listing
            if argv[0] == 'dumpe2fs': return 'Filesystem state:         clean\n'
            raise AssertionError(argv)
        with patch.object(S, 'run', side_effect=run):
            S.empty_media('/dev/fixture')
            self.assertTrue(all('-w' not in argv and not argv[0].startswith('mkfs') for argv in commands))
            listing += '/12/100600/0/0/existing-data/123/\n'
            with self.assertRaisesRegex(P.PlanError, 'EXISTING_MEDIA_CONTENT'):
                S.empty_media('/dev/fixture')
            listing = '/2/040755/0/0/.//\n/2/040755/0/0/..//\n/11/040700/0/0/lost+found//\n'
            recovered += '/12/100600/0/0/recovered-data/123/\n'
            with self.assertRaisesRegex(P.PlanError, 'RECOVERED_MEDIA_CONTENT'):
                S.empty_media('/dev/fixture')

    def test_uuid_must_resolve_to_one_exact_capacity_ext4_device(self):
        outputs = ['ext4']
        def run(argv, **kwargs):
            if argv[:2] == ['blkid', '-t']: return '/dev/fixture\n'
            if argv[0] == 'blockdev': return str(32 * 1024 ** 3)
            return outputs[0]
        with patch.object(S, 'run', side_effect=run), patch.object(Path, 'stat', return_value=SimpleNamespace(st_mode=stat.S_IFBLK)):
            self.assertEqual(S.block_for_uuid('fixture-uuid', 32 * 1024 ** 3), '/dev/fixture')
            with self.assertRaisesRegex(P.PlanError, 'CAPACITY'):
                S.block_for_uuid('fixture-uuid', 1)
            outputs[0] = 'vfat'
            with self.assertRaisesRegex(P.PlanError, 'EXISTING_EXT4'):
                S.block_for_uuid('fixture-uuid', 32 * 1024 ** 3)

    def test_only_new_exclusive_backing_files_are_formatted(self):
        plan = public_plan()
        with tempfile.TemporaryDirectory(prefix='gw-new-lun-fixture-') as temp:
            backing = str(Path(temp) / 'new-files')
            commands = []
            def run(argv, **kwargs):
                commands.append(argv)
                file = Path(argv[-1])
                self.assertEqual(file.parent, Path(backing))
                self.assertTrue(file.is_file())
                self.assertEqual(file.stat().st_size, 4096)
                if argv[0] == 'blkid':
                    return plan['clients'][file.stem]['filesystem_uuid']
                self.assertEqual(argv[0], 'mkfs.ext4')
                self.assertEqual(argv[argv.index('-E') + 1], 'nodiscard')
                return ''
            with patch.object(S, 'BACKING', backing), patch.object(S, 'LUN_BYTES', 4096), \
                 patch.object(S, 'check_target'), patch.object(S, 'run', side_effect=run):
                S.create_backing(plan, P.digest(plan))
                self.assertEqual(len([argv for argv in commands if argv[0] == 'mkfs.ext4']), 3)
                before = len(commands)
                with self.assertRaises(FileExistsError):
                    S.create_backing(plan, P.digest(plan))
                self.assertEqual(len(commands), before)
                self.assertEqual(json.loads((Path(backing) / 'prepared.json').read_text())['state'], 'prepared')

    def test_existing_sessions_block_initiator_or_login_changes(self):
        with patch.object(S, 'machine'), patch.object(S, 'run', return_value='tcp: existing approved-or-unapproved session\n'), \
             patch.object(Path, 'read_text', side_effect=AssertionError('must stop before identity file read')):
            with self.assertRaisesRegex(P.PlanError, 'EXISTING_ISCSI_SESSION'):
                S.connect_client(public_plan(), 'node-1')

    def test_wrong_initiator_blocks_replacement_without_writes(self):
        with patch.object(S, 'machine'), patch.object(S, 'protected'), patch.object(S, 'run', return_value=''), \
             patch.object(S, 'unused_iscsi_records'), \
             patch.object(Path, 'read_text', return_value='InitiatorName=iqn.unapproved\n'), \
             patch.object(os, 'open', side_effect=AssertionError('must not write')):
            with self.assertRaisesRegex(P.PlanError, 'INITIATOR_IQN_MISMATCH'):
                S.connect_client(public_plan(), 'node-1')

    def test_dormant_node_records_block_repair_without_reading_them(self):
        with tempfile.TemporaryDirectory(prefix='gw-iscsi-records-fixture-') as temp:
            base = Path(temp)
            (base / 'nodes' / 'unrelated-target').mkdir(parents=True)
            with patch.object(S, 'ISCSI_DIRECTORY', base), patch.object(S, 'protected'), \
                 patch.object(Path, 'read_text', side_effect=AssertionError('metadata only')):
                with self.assertRaisesRegex(P.PlanError, 'EXISTING_ISCSI_NODE_RECORDS'):
                    S.unused_iscsi_records()

    def test_client_uses_exact_tpgt_default_interface_and_automatic_login(self):
        plan = public_plan(); client = plan['clients']['node-1']
        with tempfile.TemporaryDirectory(prefix='gw-iscsi-client-fixture-') as temp:
            base = Path(temp)
            (base / 'initiatorname.iscsi').write_text('InitiatorName=' + client['initiator_iqn'] + '\n')
            commands = []
            record = base / 'nodes' / client['target_iqn'] / '192.168.72.103,3260,1' / 'default'
            def run(argv, **kwargs):
                commands.append(argv)
                if argv[:3] == ['iscsiadm', '-m', 'node']:
                    self.assertEqual(argv[argv.index('-p') + 1], '192.168.72.103:3260,1')
                    if '--op' in argv:
                        self.assertEqual(argv[argv.index('-I') + 1], 'default')
                        record.parent.mkdir(parents=True)
                        record.write_text('node.startup = manual\nnode.conn[0].startup = manual\n')
                    if '--login' in argv:
                        self.assertEqual(argv[argv.index('-I') + 1], 'default')
                        values = record.read_text()
                        self.assertIn('node.startup = automatic\n', values)
                        self.assertIn('node.conn[0].startup = automatic\n', values)
                        self.assertIn('node.session.auth.authmethod = CHAP\n', values)
                        self.assertEqual(stat.S_IMODE(record.stat().st_mode), 0o600)
                return ''
            with patch.object(S, 'ISCSI_DIRECTORY', base), patch.object(S, 'ALTERNATE_ISCSI_DIRECTORY', base / 'unused'), \
                 patch.object(S, 'protected'), patch.object(S, 'machine'), patch.object(S, 'run', side_effect=run), \
                 patch.object(S, 'chap', return_value={'username': 'fixture-node-1', 'password': 'fixture-not-a-secret'}):
                S.connect_client(plan, 'node-1')
            self.assertTrue(any('--login' in argv for argv in commands))
            self.assertFalse(any('fixture-not-a-secret' in str(argv) for argv in commands))

    def test_target_keeps_public_unit_serial_stable_across_reconstruction(self):
        plan = public_plan(); fake = ModuleType('rtslib_fb')
        for name in ('FabricModule', 'Target', 'LUN', 'NodeACL', 'MappedLUN', 'NetworkPortal'):
            setattr(fake, name, Mock())
        fake.RTSRoot = Mock(return_value=SimpleNamespace(targets=[], storage_objects=[]))
        fake.FileIOStorageObject = Mock()
        fake.TPG = Mock(side_effect=lambda *args, **kwargs: SimpleNamespace(enable=False, set_attribute=Mock()))
        metadata = SimpleNamespace(st_size=P.LUN_BYTES, st_nlink=1, st_blocks=P.LUN_BYTES // 512)
        with patch.dict(sys.modules, {'rtslib_fb': fake}), patch.object(S, 'check_target'), \
             patch.object(S, 'protected', return_value=metadata), \
             patch.object(Path, 'read_text', return_value=json.dumps({'plan_sha256': 'fixture', 'state': 'prepared'})), \
             patch.object(S, 'run', side_effect=lambda argv: plan['clients'][Path(argv[-1]).stem]['filesystem_uuid']), \
             patch.object(S, 'chap', return_value={'username': 'fixture', 'password': 'fixture-not-a-secret'}):
            S.configure_target(plan, 'fixture')
            S.configure_target(plan, 'fixture')
        serials = [call.kwargs['wwn'] for call in fake.FileIOStorageObject.call_args_list]
        expected = [plan['clients'][node]['filesystem_uuid'] for node in P.ISCSI]
        self.assertEqual(serials, expected + expected)
        self.assertTrue(all(call.kwargs['write_back'] is False for call in fake.FileIOStorageObject.call_args_list))

    def test_existing_target_config_is_never_cleared_or_replaced(self):
        fake = ModuleType('rtslib_fb')
        for name in ('FabricModule', 'FileIOStorageObject', 'Target', 'TPG', 'LUN', 'NodeACL', 'MappedLUN', 'NetworkPortal'):
            setattr(fake, name, Mock(side_effect=AssertionError('must not modify configfs')))
        fake.RTSRoot = Mock(return_value=SimpleNamespace(targets=['existing'], storage_objects=[]))
        with patch.dict(sys.modules, {'rtslib_fb': fake}), patch.object(S, 'check_target'), patch.object(S, 'protected'), \
             patch.object(Path, 'read_text', return_value=json.dumps({'plan_sha256': 'approved-fixture', 'state': 'prepared'})):
            with self.assertRaisesRegex(P.PlanError, 'TARGET_CONFIGURATION_NOT_EMPTY'):
                S.configure_target(public_plan(), 'approved-fixture')


class StorageRoleIntegrationTest(unittest.TestCase):
    def setUp(self):
        self.ansible = FILES.parent
        self.role = self.ansible / 'roles/state_storage'

    def tasks(self, filename):
        return yaml.safe_load((self.role / 'tasks' / filename).read_text())

    def test_probe_failure_reports_bounded_diagnostic_without_inline_program_dump(self):
        tasks = self.tasks('preflight.yml')
        probe = next(task for task in tasks if 'ansible.builtin.command' in task)
        report = next(task for task in tasks if task.get('name') ==
                      'Require a successful storage probe with a bounded diagnostic')
        probe['ansible.builtin.command']['argv'] = [sys.executable, '-c',
            "# INLINE_PROGRAM_MUST_NOT_BE_DUMPED\nimport sys; sys.stderr.write('STORAGE STOP: UNPROTECTED_PATH path=/fixture uid=1000 mode=0775\\n'); sys.exit(1)"]
        with tempfile.TemporaryDirectory(prefix='gw-storage-diagnostic-') as temp:
            play = Path(temp) / 'play.yml'
            play.write_text(yaml.safe_dump([{'hosts': 'localhost', 'gather_facts': False, 'tasks': [
                probe, report, {'ansible.builtin.debug': {'msg': 'MUST_NOT_REACH_STORAGE_CHANGES'}}]}]))
            result = subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local', str(play)],
                                    capture_output=True, text=True, timeout=60,
                                    env=dict(os.environ, ANSIBLE_LOCAL_TEMP=temp + '/local',
                                             ANSIBLE_REMOTE_TEMP=temp + '/remote'))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('STORAGE STOP: UNPROTECTED_PATH path=/fixture uid=1000 mode=0775', result.stdout)
        self.assertNotIn('INLINE_PROGRAM_MUST_NOT_BE_DUMPED', result.stdout + result.stderr)
        self.assertNotIn('MUST_NOT_REACH_STORAGE_CHANGES', result.stdout)

    def test_all_host_preflight_is_fatal_and_precedes_any_mutating_play(self):
        plays = yaml.safe_load((self.ansible / 'playbooks/prepare_state_storage.yml').read_text())
        self.assertEqual(plays[0]['hosts'], 'all')
        self.assertIs(plays[0]['any_errors_fatal'], True)
        self.assertEqual(plays[0]['tasks'][0]['ansible.builtin.import_role']['tasks_from'], 'preflight')
        for play in plays[1:]:
            self.assertIs(play['any_errors_fatal'], True)
            for task in play['tasks']:
                self.assertEqual(task['when'], "greenwood_storage_operation == 'apply'")
        preflight = self.tasks('preflight.yml')
        command = next(task for task in preflight if 'ansible.builtin.command' in task)
        self.assertEqual(command['ansible.builtin.command']['argv'][-1], 'check')
        self.assertIs(command['changed_when'], False)
        self.assertIn("ansible_play_hosts_all | sort == groups['all'] | sort", str(preflight))
        installed = self.tasks('install_tools.yml')
        self.assertEqual(installed[0]['loop'], "{{ groups['all'] }}")
        self.assertIn('greenwood_storage_checked_sha256', str(installed[0]))

    def test_runtime_restart_requires_full_scope_new_source_and_completed_migration(self):
        plays = yaml.safe_load((self.ansible / 'playbooks/verify_state_runtime.yml').read_text())
        self.assertEqual(plays[0]['hosts'], 'all')
        self.assertIn("ansible_play_hosts_all | sort == groups['all'] | sort", str(plays[0]))
        self.assertTrue(all(play['any_errors_fatal'] for play in plays))
        before = str(plays[:-1])
        self.assertIn('resume_runtime_preflight.yml', before)
        self.assertIn('SqliteDurability.php', before)
        self.assertIn('verify_migration', before)
        starts = [task['ansible.builtin.systemd_service'] for play in plays
                  for task in play.get('tasks', []) if 'ansible.builtin.systemd_service' in task]
        self.assertEqual([item['name'] for item in starts], ['containerd.service'])
        migration = str(self.tasks('verify_migration.yml'))
        self.assertIn('wal_remaining == 0', migration)
        self.assertIn('--check', migration)

    def test_verified_exact_copy_receipt_precedes_binding_and_migration(self):
        tasks = self.tasks('client.yml')
        copy_index = next(index for index, task in enumerate(tasks)
                          if 'ansible.builtin.command' in task and 'prepare_durable_state.py' in str(task['ansible.builtin.command']))
        gate_index = next(index for index, task in enumerate(tasks)
                          if 'ansible.builtin.assert' in task and 'greenwood_storage_copy_marker' in str(task))
        bind_index = next(index for index, task in enumerate(tasks)
                          if task.get('ansible.builtin.template', {}).get('src') == 'bind.mount.j2')
        self.assertLess(copy_index, gate_index)
        self.assertLess(gate_index, bind_index)
        checks = tasks[gate_index]['ansible.builtin.assert']['that']
        self.assertTrue(any("state == 'complete'" in expression for expression in checks))
        self.assertTrue(any('plan == greenwood_storage_copy_manifest' in expression for expression in checks))
        migration_index = next(index for index, task in enumerate(tasks)
                               if 'ansible.builtin.command' in task and 'migrate_sqlite_rollback.py' in str(task['ansible.builtin.command']))
        dependency_index = next(index for index, task in enumerate(tasks)
                                if task.get('ansible.builtin.template', {}).get('src') == 'service-storage.conf.j2')
        self.assertLess(bind_index, migration_index)
        self.assertLess(dependency_index, migration_index)

    def test_preparation_does_not_start_greenwood_or_containerd(self):
        for filename in ('client.yml', 'target.yml', 'install_tools.yml'):
            for task in self.tasks(filename):
                settings = task.get('ansible.builtin.systemd_service', {})
                if settings.get('state') in ('started', 'restarted', 'reloaded'):
                    self.assertIn(settings['name'], ('var-lib-greenwood\\x2dstate.mount',
                                                     '{{ item.unit }}', 'greenwood-state-target.service'))
                if 'ansible.builtin.apt' in task:
                    self.assertEqual(task['ansible.builtin.apt']['policy_rc_d'], 101)
        source = (FILES / 'prepare_state_storage.py').read_text()
        self.assertNotIn("['systemctl', 'restart', 'systemd-networkd", source)
        self.assertNotIn("['systemctl', 'restart', 'ssh", source)

    def test_systemd_templates_keep_install_section_and_all_mount_guards(self):
        # Ansible enables trim_blocks; defaults would conceal joined directives.
        environment = Environment(undefined=StrictUndefined, trim_blocks=True)
        text = environment.from_string((self.role / 'templates/target.service.j2').read_text()).render(
            greenwood_storage_plan_sha256='a' * 64)
        config = configparser.ConfigParser(interpolation=None, strict=False)
        config.read_string(text)
        self.assertEqual(config['Install']['WantedBy'], 'multi-user.target')
        self.assertIn('--action start-target', config['Service']['ExecStart'])
        for service in ('systemd-networkd.service', 'ssh.service', 'nfs-server.service'):
            self.assertIn(service, config['Unit']['After'].split())
            self.assertIn(service, config['Unit']['Requires'].split())
        bindings = [{'destination': path, 'unit': path[1:].replace('-', r'\x2d').replace('/', '-') + '.mount'}
                    for path in P.sources('node-1')]
        guard = environment.from_string((self.role / 'templates/service-storage.conf.j2').read_text()).render(
            greenwood_storage_bindings=bindings)
        config.read_string(guard)
        self.assertEqual(config['Unit']['RequiresMountsFor'].split(),
                         ['/var/lib/greenwood-state'] + P.sources('node-1'))
        self.assertEqual(config['Unit']['BindsTo'].split(),
                         [r'var-lib-greenwood\x2dstate.mount'] + [binding['unit'] for binding in bindings])
        self.assertIn('ConditionPathIsMountPoint=/var/lib/greenwood-state', guard.splitlines())
        for binding in bindings:
            self.assertIn('ConditionPathIsMountPoint=' + binding['destination'], guard.splitlines())
        for node in ('node-1', 'node-5'):
            mount = environment.from_string((self.role / 'templates/state.mount.j2').read_text()).render(
                inventory_hostname=node, greenwood_storage_plan=public_plan())
            config = configparser.ConfigParser(interpolation=None, strict=False)
            config.read_string(mount)
            self.assertEqual(config['Mount']['Options'], 'rw,noatime,_netdev' if node == 'node-1' else 'rw,noatime')
            self.assertEqual(config['Mount']['TimeoutSec'], '120')
            self.assertEqual(config['Install']['WantedBy'], 'remote-fs.target' if node == 'node-1' else 'local-fs.target')
        mount = environment.from_string((self.role / 'templates/bind.mount.j2').read_text()).render(
            item={'destination': '/var/lib/greenwood', 'source': '/var/lib/greenwood-state/greenwood'})
        config = configparser.ConfigParser(interpolation=None, strict=False)
        config.read_string(mount)
        self.assertEqual(config['Unit']['BindsTo'], r'var-lib-greenwood\x2dstate.mount')
        client = self.tasks('client.yml')
        dependencies = next(task for task in client if 'loop' in task and
                            isinstance(task['loop'], list) and 'containerd.service' in task['loop'])['loop']
        self.assertIn('greenwood-policy-sftp.service', dependencies)


if __name__ == '__main__':
    unittest.main()
