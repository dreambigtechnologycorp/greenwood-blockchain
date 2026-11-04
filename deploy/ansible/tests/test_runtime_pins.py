"""Exercise the provider pin gate without installing runtime packages."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[1]


class RuntimePinTests(unittest.TestCase):
    def test_runtime_pin_gate(self):
        # These are synthetic syntax fixtures, not approved runtime versions.
        approved = dict(containerd_version='1.2.3', runc_version='1.2.3',
                        containerd_sha256='a' * 64, runc_sha256='b' * 64,
                        greenwood_containerd_config='version = 3',
                        containerd_snapshotter='erofs', erofs_utils_version='1.2.3-1')
        cases = [(approved, True), (dict(approved, containerd_version=''), False),
                 (dict(approved, erofs_utils_version=''), False),
                 (dict(approved, erofs_utils_version='*'), False),
                 (dict(approved, runc_sha256=''), False)]
        for variables, expected in cases:
            with self.subTest(variables=variables), tempfile.TemporaryDirectory(prefix='gw-pins-') as tmp:
                play = [{'hosts': 'localhost', 'gather_facts': False, 'vars': variables,
                         'tasks': [{'ansible.builtin.import_tasks': str(ROOT / 'roles/provider/tasks/runtime_preflight.yml')}]}]
                path = Path(tmp) / 'test.yml'
                path.write_text(yaml.safe_dump(play))
                result = subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local', str(path), '--check'],
                                        env=dict(os.environ, ANSIBLE_LOCAL_TEMP=str(Path(tmp) / 'ansible-tmp')),
                                        capture_output=True, text=True, timeout=60)
                self.assertEqual(expected, result.returncode == 0, result.stdout + result.stderr)

    def test_preflight_precedes_mutations_and_erofs_is_pinned(self):
        tasks = yaml.safe_load((ROOT / 'roles/provider/tasks/main.yml').read_text())
        self.assertEqual('runtime_preflight.yml', tasks[0]['ansible.builtin.import_tasks'])
        erofs = next(task for task in tasks if task['name'] == 'Install EROFS tools')
        self.assertEqual(['erofs-utils={{ erofs_utils_version }}'], erofs['ansible.builtin.apt']['name'])
        defaults = yaml.safe_load((ROOT / 'roles/provider/defaults/main.yml').read_text())
        for pin in ('containerd_version', 'runc_version', 'erofs_utils_version'):
            self.assertEqual('', defaults[pin])
