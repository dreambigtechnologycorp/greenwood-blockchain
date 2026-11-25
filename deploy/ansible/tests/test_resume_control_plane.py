"""Local assertions for bounded continuation; no nodes, keys or services touched."""
import copy
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[1]


def task(filename, name):
    return next(t for t in yaml.safe_load((ROOT / 'tasks' / filename).read_text())
                if t.get('name') == name)


def run_gate(gate, variables, setup=()):
    with tempfile.TemporaryDirectory(prefix='gw-resume-gate-') as directory:
        path = Path(directory) / 'play.yml'
        path.write_text(yaml.safe_dump([{
            'hosts': 'localhost', 'gather_facts': False, 'vars': variables,
            'tasks': [*setup, gate, {'ansible.builtin.debug': {'msg': 'RESUME_GATE_PASSED'}}],
        }]))
        return subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local', str(path)],
                              capture_output=True, text=True, timeout=30,
                              env=dict(os.environ, ANSIBLE_LOCAL_TEMP=directory + '/local'))


class ResumeControlPlaneTest(unittest.TestCase):
    def test_resume_rechecks_all_targets_and_reuses_exact_activation_tail(self):
        fresh = yaml.safe_load((ROOT / 'playbooks/fresh_cluster.yml').read_text())
        resume = yaml.safe_load((ROOT / 'playbooks/resume_control_plane.yml').read_text())
        self.assertEqual(fresh[-1], resume[-1])
        self.assertEqual('finish_installation.yml', resume[-1]['ansible.builtin.import_playbook'])
        self.assertEqual('all', resume[0]['hosts'])
        self.assertTrue(all(p['any_errors_fatal'] for p in resume[:-1]))
        self.assertEqual('../tasks/installation_preflight.yml',
                         resume[0]['tasks'][1]['ansible.builtin.import_tasks'])
        # All runtime bytes are checked before any installed PHP identity check.
        self.assertIn('resume_runtime_preflight.yml',
                      resume[2]['tasks'][0]['ansible.builtin.import_tasks'])
        self.assertIn('validate_installed_identity.yml',
                      resume[3]['tasks'][-1]['ansible.builtin.import_tasks'])
        self.assertNotIn('deploy_greenwood.yml', str(resume))
        self.assertNotIn('fresh_identity.yml', str(resume))

    def test_explicit_continuation_authority_is_required(self):
        gate = yaml.safe_load((ROOT / 'playbooks/resume_control_plane.yml').read_text())[0]['tasks'][0]
        for approved in (False, True):
            with self.subTest(approved=approved):
                result = run_gate(gate, {'greenwood_resume_control_plane_approved': approved})
                self.assertEqual(result.returncode == 0, approved, result.stdout + result.stderr)

    def test_shared_tail_rejects_direct_entry_without_same_run_staging(self):
        gate = yaml.safe_load((ROOT / 'playbooks/finish_installation.yml').read_text())[0]['tasks'][0]
        variables = {
            'greenwood_operator_secret_access_authorized': True,
            'greenwood_fresh_cluster_approved': True,
            'greenwood_activate_protocol': True,
            'greenwood_activate_hosting': True,
            'greenwood_recovery_access_confirmed': True,
            'greenwood_source_sha': 'a' * 40,
            'greenwood_genesis_sha256': 'b' * 64,
            'greenwood_chain_config_sha256': 'c' * 64,
        }
        result = run_gate(gate, variables)
        self.assertNotEqual(0, result.returncode)
        self.assertIn('Verified staging gates are required', result.stdout)
        self.assertNotIn('RESUME_GATE_PASSED', result.stdout)
        variables.update({
            'greenwood_installation_staging_verified': True,
            'greenwood_installation_inputs_verified': {
                'source_sha': variables['greenwood_source_sha'],
                'genesis_sha256': variables['greenwood_genesis_sha256'],
                'chain_config_sha256': variables['greenwood_chain_config_sha256'],
            },
        })
        # Real staging uses set_fact: play-local vars alone are not hostvars.
        setup = [{'ansible.builtin.set_fact': {
            key: variables[key] for key in (
                'greenwood_installation_staging_verified',
                'greenwood_installation_inputs_verified',
            )
        }}]
        result = run_gate(gate, variables, setup)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        variables['greenwood_installation_inputs_verified']['source_sha'] = 'd' * 40
        result = run_gate(gate, variables, setup)
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn('RESUME_GATE_PASSED', result.stdout)

    def test_fresh_state_gate_and_all_original_approvals_remain_required(self):
        fresh = (ROOT / 'tasks/fresh_preflight.yml').read_text()
        self.assertIn('not item.stat.exists', fresh)
        self.assertIn('/etc/greenwood/genesis.json', fresh)
        shared = (ROOT / 'tasks/installation_preflight.yml').read_text()
        for gate in ('greenwood_operator_secret_access_authorized',
                     'greenwood_fresh_cluster_approved', 'greenwood_activate_protocol',
                     'greenwood_activate_hosting', 'not ansible_check_mode',
                     'topology_preflight.yml', 'rpc_dependencies.yml',
                     'fresh_inputs', 'fresh_seeds', 'fresh_validator_secret'):
            self.assertIn(gate, shared)
        self.assertIn('greenwood_recovery_access_confirmed',
                      (ROOT / 'tasks/topology_preflight.yml').read_text())

    def test_validator_marker_and_sidecar_cannot_resume(self):
        gate = task('resume_protocol_preflight.yml', 'Refuse initialized validator state')
        for path in ('validator.initialized', 'signing.sqlite-wal', 'state.sqlite-shm'):
            with self.subTest(path=path):
                result = run_gate(gate, {'resume_protocol_state': {'results': [
                    {'item': '/var/lib/greenwood/' + path, 'stat': {'exists': True}},
                ]}})
                self.assertNotEqual(0, result.returncode)
                self.assertNotIn('RESUME_GATE_PASSED', result.stdout)
        inspect = task('resume_protocol_preflight.yml',
                       'Inspect protocol initialization markers without reading state contents')
        self.assertFalse(inspect['ansible.builtin.stat']['get_checksum'])
        self.assertNotIn('/var/lib/greenwood-provider/provider.sqlite', inspect['loop'])

    def test_protocol_requires_loaded_stopped_unit_and_no_live_pid(self):
        gate = task('resume_protocol_preflight.yml', 'Require installed inactive protocol units')
        for load, state, pid, allowed in [
            ('loaded', 'inactive', 0, True), ('loaded', 'failed', 0, True),
            ('not-found', 'inactive', 0, False), ('loaded', 'active', 7, False),
            ('loaded', 'activating', 0, False), ('loaded', 'inactive', 9, False),
        ]:
            with self.subTest(load=load, state=state, pid=pid):
                result = run_gate(gate, {'resume_protocol_units': {'results': [{
                    'item': 'greenwood-provider.service',
                    'stdout_lines': [f'LoadState={load}', f'ActiveState={state}', f'MainPID={pid}'],
                }]}})
                self.assertEqual(result.returncode == 0, allowed, result.stdout + result.stderr)

    def test_runtime_mismatch_prevents_installed_code_execution(self):
        gate = task('resume_runtime_preflight.yml',
                    'Reject any runtime drift before executing installed identity validation')
        # Replace hostvar access only with a local public fixture constant.
        gate = copy.deepcopy(gate)
        gate['ansible.builtin.assert']['that'] = [
            condition.replace("hostvars[groups['controller'][0]].release_facts.tree_digest",
                              "expected_tree_digest")
            for condition in gate['ansible.builtin.assert']['that']
        ]
        for actual in ('a' * 64, 'b' * 64):
            result = run_gate(gate, {'resume_runtime_tree': {'stdout': actual},
                                     'expected_tree_digest': 'a' * 64})
            self.assertEqual(result.returncode == 0, actual == 'a' * 64,
                             result.stdout + result.stderr)

    def test_fresh_stages_recovery_without_indirect_provider_startup(self):
        fresh = yaml.safe_load((ROOT / 'playbooks/fresh_cluster.yml').read_text())
        hosting = next(p for p in fresh if p.get('hosts') == 'application_providers')
        recovery = next(t for t in hosting['tasks']
                        if t.get('ansible.builtin.import_tasks') == '../tasks/application_recovery.yml')
        self.assertIs(False, recovery['vars']['greenwood_application_recovery_activate'])
        task_list = yaml.safe_load((ROOT / 'tasks/application_recovery.yml').read_text())
        start = next(t for t in task_list if t.get('name') == 'Enable retained-application recovery')
        self.assertIn('greenwood_application_recovery_activate', start['when'])
        tail = (ROOT / 'playbooks/finish_installation.yml').read_text()
        self.assertNotIn('name: greenwood-application-recovery.timer', tail)


if __name__ == '__main__':
    unittest.main()
