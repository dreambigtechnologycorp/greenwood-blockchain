"""Exercise the real first-task custody gate without accessing any key files."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[1]
ROLE = ROOT / 'roles/control_plane'

class OperatorKeyBoundaryTest(unittest.TestCase):
    def test_gate_in_check_mode(self):
        gate = yaml.safe_load((ROLE / 'tasks/main.yml').read_text())[0]
        defaults = yaml.safe_load((ROLE / 'defaults/main.yml').read_text())
        for files, https, authorized, allowed in [
            ([], [], False, True),
            ([{'src': '/never/read/key', 'dest': '/never/write/key'}], [], False, False),
            ([], [{'private_key': '/never/read/key'}], False, False),
            ([], [{'private_key': '/never/read/key'}], True, True),
        ]:
            with self.subTest(files=bool(files), https=bool(https), authorized=authorized):
                with tempfile.TemporaryDirectory() as directory:
                    work = Path(directory)
                    variables = defaults | {'greenwood_control_files': files,
                        'greenwood_https_frontends': https,
                        'greenwood_operator_secret_access_authorized': authorized}
                    play = work / 'gate.yml'
                    play.write_text(yaml.safe_dump([{'hosts': 'localhost', 'gather_facts': False,
                        'vars': variables, 'tasks': [gate]}]))
                    result = subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local',
                        str(play), '--check'], capture_output=True, text=True, timeout=30,
                        env=dict(os.environ, ANSIBLE_LOCAL_TEMP=str(work / 'tmp')))
                    self.assertEqual(result.returncode == 0, allowed, result.stdout + result.stderr)

    def test_fresh_identity_gates_deny_by_default(self):
        fresh = yaml.safe_load((ROOT / 'tasks/fresh_preflight.yml').read_text())
        self.assertEqual('installation_preflight.yml', fresh[0]['ansible.builtin.import_tasks'])
        for name in ['installation_preflight.yml', 'fresh_identity.yml']:
            gate = yaml.safe_load((ROOT / 'tasks' / name).read_text())[0]
            with self.subTest(task=name), tempfile.TemporaryDirectory() as directory:
                work = Path(directory)
                play = work / 'gate.yml'
                play.write_text(yaml.safe_dump([{'hosts': 'localhost', 'gather_facts': False,
                    'tasks': [gate]}]))
                result = subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local',
                    str(play), '--check'], capture_output=True, text=True, timeout=30,
                    env=dict(os.environ, ANSIBLE_LOCAL_TEMP=str(work / 'tmp')))
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('human operator', result.stdout)
