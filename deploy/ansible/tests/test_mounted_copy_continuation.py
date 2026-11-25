"""Scope/dispatch checks for the mounted-but-not-copied Node 1 continuation."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

from jinja2 import Environment, StrictUndefined
import yaml


ROOT = Path(__file__).resolve().parents[1]
ROLE = ROOT / 'roles/state_storage'


class MountedCopyContinuationTest(unittest.TestCase):
    def tasks(self, filename):
        return yaml.safe_load((ROLE / 'tasks' / filename).read_text())

    def test_missing_approval_or_limited_scope_stops_before_host_access(self):
        plan = ROOT / 'playbooks/resume_mounted_state_copy.yml'
        for options in ([], ['--limit', 'node-1', '-e', json.dumps({
                'greenwood_storage_resume_mounted_client': 'node-1',
                'greenwood_storage_preparation_approved': True,
                'greenwood_recovery_access_confirmed': True})]):
            with self.subTest(options=options), tempfile.TemporaryDirectory(prefix='gw-copy-gate-') as temp:
                result = subprocess.run(
                    ['ansible-playbook', '-i', str(ROOT / 'inventory/seven-node.example.yml'), str(plan), *options],
                    capture_output=True, text=True, timeout=60,
                    env=dict(os.environ, ANSIBLE_LOCAL_TEMP=temp + '/local', ANSIBLE_REMOTE_TEMP=temp + '/remote'))
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('Continuation requires all seven nodes', result.stdout)
                self.assertNotIn('Check actual machine identity', result.stdout)
                self.assertNotIn('Inspect running targets', result.stdout)

    def test_all_host_phase_proof_precedes_serial_client_changes_without_target_mutations(self):
        plays = yaml.safe_load((ROOT / 'playbooks/resume_mounted_state_copy.yml').read_text())
        self.assertEqual(len(plays), 2)
        self.assertEqual(plays[0]['hosts'], 'all')
        self.assertTrue(all(play['any_errors_fatal'] for play in plays))
        self.assertEqual(plays[1]['serial'], 1)
        self.assertEqual(plays[0]['tasks'][0]['ansible.builtin.import_role']['tasks_from'], 'resume_copy_preflight')
        self.assertEqual(plays[1]['tasks'][0]['ansible.builtin.import_role']['tasks_from'], 'client')
        self.assertEqual(plays[1]['tasks'][0]['when'], "greenwood_storage_operation == 'apply'")
        tasks = self.tasks('resume_copy_preflight.yml')
        self.assertIn("greenwood_storage_resume_mounted_client == 'node-1'", str(tasks[0]))
        self.assertIn('greenwood_storage_resume_prepared_target is sameas false', str(tasks[0]))
        self.assertIn('ansible_play_hosts_all | sort == groups', str(tasks[0]))
        self.assertEqual(tasks[-2]['ansible.builtin.assert']['that'], 'greenwood_storage_resume_probe.rc == 0')
        self.assertEqual(tasks[-1]['ansible.builtin.set_fact'],
                         {'greenwood_storage_checked_sha256': '{{ greenwood_storage_plan_sha256 }}'})
        self.assertIn('greenwood_storage_checked_sha256', str(self.tasks('install_tools.yml')[0]))

    def test_copy_metadata_check_precedes_normal_and_continuation_probes(self):
        for filename in ('preflight.yml', 'resume_copy_preflight.yml'):
            tasks = self.tasks(filename)
            metadata = next(i for i, task in enumerate(tasks)
                            if task.get('ansible.builtin.import_tasks') == 'copy_metadata_preflight.yml')
            probe = next(i for i, task in enumerate(tasks) if 'ansible.builtin.command' in task)
            self.assertLess(metadata, probe)
        self.assertIn("greenwood_storage_resume_mounted_client == ''", str(self.tasks('preflight.yml')[0]))
        tasks = self.tasks('copy_metadata_preflight.yml')
        self.assertTrue(all(task['when'] == "inventory_hostname != 'node-4'" for task in tasks))
        self.assertEqual(tasks[0]['ansible.builtin.command']['argv'][-1], 'source-metadata')
        self.assertIs(tasks[0]['changed_when'], False)
        self.assertIs(tasks[0]['no_log'], True)
        self.assertEqual(tasks[1]['ansible.builtin.assert']['that'], 'greenwood_copy_metadata_probe.rc == 0')

    def test_only_mounted_node_skips_reconnect_and_empty_unmounted_media_check(self):
        tasks = self.tasks('client.yml')
        connection = next(task for task in tasks if 'connect-client' in str(task))
        empty = next(task for task in tasks if 'connected-check' in str(task))
        expression = 'inventory_hostname != greenwood_storage_resume_mounted_client'
        self.assertIn(expression, connection['when'])
        self.assertEqual(empty['when'], expression)
        env = Environment(undefined=StrictUndefined)
        condition = env.compile_expression(expression)
        for node in ('node-1', 'node-2', 'node-3', 'node-5', 'node-6', 'node-7'):
            self.assertEqual(condition(inventory_hostname=node, greenwood_storage_resume_mounted_client='node-1'),
                             node != 'node-1')
            self.assertTrue(condition(inventory_hostname=node, greenwood_storage_resume_mounted_client=''))
        copy = next(task for task in tasks if 'prepare_durable_state.py' in str(task))
        self.assertNotIn('when', copy)

    def test_actual_inline_program_routes_only_the_approved_host_to_each_probe(self):
        task = next(t for t in self.tasks('resume_copy_preflight.yml') if 'ansible.builtin.command' in t)
        program = task['ansible.builtin.command']['argv'][2]
        env = Environment(undefined=StrictUndefined)
        env.filters['to_json'] = json.dumps

        def lookup(kind, path):
            self.assertEqual(kind, 'file')
            name = Path(path).stem
            return f'def main(): print({name!r})\n'

        expected = {'node-4': 'validate_active_storage_target', 'node-1': 'validate_state_copy_preflight'}
        for node in ('node-1', 'node-2', 'node-3', 'node-4', 'node-5', 'node-6', 'node-7'):
            rendered = env.from_string(program).render(lookup=lookup, role_path=str(ROLE), inventory_hostname=node)
            result = subprocess.run(['python3', '-c', rendered], capture_output=True, text=True, timeout=10)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stdout.strip(), expected.get(node, 'prepare_state_storage'))
            action = env.from_string(task['ansible.builtin.command']['argv'][-1]).render(inventory_hostname=node)
            self.assertEqual(action, 'mounted-client' if node == 'node-1' else 'check')


if __name__ == '__main__':
    unittest.main()
