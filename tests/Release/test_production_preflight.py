import base64
import copy
import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('preflight', Path(__file__).resolve().parents[2] / 'scripts/production-preflight.py')
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)

class PreflightTest(unittest.TestCase):
    def setUp(self):
        self.blob = b'public-test-host-key'
        fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(self.blob).digest()).decode().rstrip('=')
        self.data = {'version': 1, 'hosts': [dict(name=f'node-{i}', address=f'192.0.2.{i}', user='operator', machine_id=f'{i:032x}', architecture='aarch64', host_key_sha256=fingerprint) for i in range(1, 5)]}

    def test_four_unique_identities(self):
        self.assertEqual(len(M.validate_inventory(self.data)), 4)
        self.data['hosts'][1]['machine_id'] = self.data['hosts'][0]['machine_id']
        with self.assertRaises(ValueError): M.validate_inventory(self.data)

    def test_inventory_cannot_inject_shell(self):
        for field, value in [('user', 'root;id'), ('address', '192.0.2.1;id'), ('machine_id', 'unknown')]:
            data = copy.deepcopy(self.data); data['hosts'][0][field] = value
            with self.assertRaises(ValueError): M.validate_inventory(data)

    def test_host_fingerprints_are_exact(self):
        with tempfile.TemporaryDirectory() as directory:
            p = Path(directory) / 'known_hosts'
            p.write_text(''.join(f"{h['address']} ssh-ed25519 {base64.b64encode(self.blob).decode()}\n" for h in self.data['hosts']))
            M.verify_host_keys(self.data['hosts'], p)
            self.data['hosts'][0]['host_key_sha256'] = 'SHA256:' + 'A' * 43
            with self.assertRaises(ValueError): M.verify_host_keys(self.data['hosts'], p)

    def test_machine_mismatch_stops_collection(self):
        import subprocess
        with patch.object(M.subprocess, 'run', side_effect=[subprocess.CompletedProcess([], 0, 'node-1\n', ''), subprocess.CompletedProcess([], 0, 'wrong-machine\n', '')]) as run:
            result = M.inspect(self.data['hosts'][0], Path('/private/key'), Path('/private/hosts'))
        self.assertFalse(result['identity_verified'])
        self.assertEqual(run.call_count, 2)
        self.assertIn('StrictHostKeyChecking=yes', run.call_args.args[0])

    def test_ssh_failure_stops_collection(self):
        import subprocess
        with patch.object(M.subprocess, 'run', return_value=subprocess.CompletedProcess([], 255, '', 'host rejected')) as run:
            result = M.inspect(self.data['hosts'][0], Path('/private/key'), Path('/private/hosts'))
        self.assertFalse(result['identity_verified'])
        self.assertEqual(run.call_count, 1)
