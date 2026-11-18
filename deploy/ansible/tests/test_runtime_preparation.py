"""Execute preparation rejection paths before any remote installation task."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[1]

class RuntimePreparationTests(unittest.TestCase):
    def run_play(self, play, inventory='localhost,', extra=()):
        with tempfile.TemporaryDirectory(prefix='gw-runtime-prepare-') as tmp:
            path = Path(tmp) / 'play.yml'
            path.write_text(yaml.safe_dump(play))
            return subprocess.run(
                ['ansible-playbook', '-i', inventory, '-c', 'local', str(path), '--check', *extra],
                env=dict(os.environ, ANSIBLE_LOCAL_TEMP=tmp+'/local', ANSIBLE_REMOTE_TEMP=tmp+'/remote'),
                capture_output=True, text=True, timeout=60)

    def test_wildcard_package_is_rejected_before_host_access(self):
        result = self.run_play([{'hosts':'localhost', 'gather_facts':False,
            'vars':{'greenwood_php_package_version':'8.5.12*'},
            'tasks':[{'ansible.builtin.import_tasks':str(ROOT/'tasks/runtime_install_preflight.yml')}]}])
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('wildcards are not installation inputs', result.stdout)
        self.assertNotIn('Read host identity platform', result.stdout)

    def test_corrupt_artifact_is_rejected_before_host_access(self):
        with tempfile.TemporaryDirectory() as tmp:
            artifact=Path(tmp)/'artifact'
            artifact.write_text('modified bytes')
            variables=dict(greenwood_php_package_version='8.5.12-1',
                greenwood_php_grpc_package_version='1.83.1-1',
                greenwood_php_repository_key_src=str(artifact),
                greenwood_php_repository_key_sha256='a'*64,
                greenwood_php_apt_repository='deb [signed-by=/etc/apt/keyrings/greenwood-php.asc] https://example.invalid/php noble main',
                greenwood_runtime_artifacts={'containerd':str(artifact),'runc':str(artifact)},
                containerd_version='2.3.7', runc_version='1.5.3', containerd_sha256='a'*64,
                runc_sha256='a'*64, greenwood_containerd_config='version = 3',
                containerd_snapshotter='erofs', erofs_utils_version='1.7.1-1build2')
            result=self.run_play([{'hosts':'localhost','gather_facts':False,'vars':variables,
                'tasks':[{'ansible.builtin.import_tasks':str(ROOT/'tasks/runtime_install_preflight.yml')}]}])
            self.assertNotEqual(result.returncode,0)
            self.assertIn('Reject missing or modified runtime inputs',result.stdout)
            self.assertNotIn('Read host identity platform',result.stdout)

    def test_limited_inventory_cannot_skip_all_host_barrier(self):
        result=subprocess.run(['ansible-playbook','-i',str(ROOT/'inventory/seven-node.example.yml'),
            str(ROOT/'playbooks/runtime_prerequisites.yml'),'--check','--limit','node-5'],
            capture_output=True,text=True,timeout=60)
        self.assertNotEqual(result.returncode,0)
        self.assertIn('Reject incomplete audit scope',result.stdout)
        self.assertNotIn('Apply approved runtime prerequisite installation',result.stdout)

    def test_deferred_recovery_requires_named_host_and_reason(self):
        source = yaml.safe_load((ROOT/'playbooks/runtime_prerequisites.yml').read_text())
        gate = source[1]['tasks'][0]['block'][0]
        # Scope this test to recovery/package conditions; the full inventory barrier
        # has a separate executable rejection test above.
        gate['ansible.builtin.assert']['that'] = gate['ansible.builtin.assert']['that'][1:]
        base = {'greenwood_runtime_preflight_passed': True,
                'runtime_package_audit': {'stdout': ''},
                'greenwood_recovery_access_confirmed': False}
        for hosts, reason, success in [([], '', False), (['localhost'], '', False),
                                       (['another-host'], 'explicit operator authorization', False),
                                       (['localhost'], 'explicit operator authorization', True)]:
            with self.subTest(hosts=hosts, reason=reason):
                variables = dict(base, greenwood_runtime_deferred_recovery_hosts=hosts,
                                 greenwood_runtime_deferred_recovery_reason=reason)
                result = self.run_play([{'hosts': 'localhost', 'gather_facts': False,
                                         'vars': variables, 'tasks': [gate]}])
                self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)

    def test_idle_runtime_preparation_rejects_unapproved_or_wrong_target(self):
        import json
        for host, approved in [('node-1', False), ('node-4', True)]:
            with self.subTest(host=host, approved=approved):
                result = subprocess.run(['ansible-playbook', '-i', str(ROOT/'inventory/seven-node.example.yml'),
                    str(ROOT/'playbooks/prepare_idle_runtime_upgrade.yml'), '--check', '-e',
                    json.dumps({'maintenance_host': host, 'maintenance_approved': approved})],
                    capture_output=True, text=True, timeout=60)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('Require a named legacy host', result.stdout)
                self.assertNotIn('Verify identity and absence of workloads', result.stdout)
