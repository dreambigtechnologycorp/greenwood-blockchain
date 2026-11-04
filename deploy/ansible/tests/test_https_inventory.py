"""Pure inventory validation; no service or network operations."""
import copy
import base64
import importlib.util
import pathlib
import os
import subprocess
import struct
import tempfile
import unittest

import yaml

from ansible.errors import AnsibleFilterError

PLUGIN = pathlib.Path(__file__).resolve().parents[1] / 'roles/control_plane/filter_plugins/greenwood_https.py'
SPEC = importlib.util.spec_from_file_location('greenwood_https', PLUGIN)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class HttpsInventoryTest(unittest.TestCase):
    def setUp(self):
        self.frontend = dict(name='controller', address='192.168.9.10', port=19449,
                             tls_name='controller.example.test', backend='http://127.0.0.1:19450',
                             request_limit=16384, certificate='/etc/greenwood/tls/service.pem',
                             private_key='/etc/greenwood/tls/service.key', ca='/etc/greenwood/tls/ca.pem',
                             ca_sha256='a' * 64)

    def test_valid_and_disabled(self):
        self.assertTrue(MODULE.validate_frontends([]))
        self.assertTrue(MODULE.validate_frontends([self.frontend]))
        self.frontend['tls_name'] = self.frontend['address']
        self.assertTrue(MODULE.validate_frontends([self.frontend]))

    def test_rejects_invalid_and_injected_values(self):
        cases = {
            'address': ['999.1.2.3', '0.0.0.0', '224.0.0.1', '::1', 123],
            'port': [True, '19449', 0, 65536, 19449.5],
            'tls_name': ['-bad.example', 'bad..example', '999.1.2.3', 'host\nListen 80', ''],
            'backend': ['http://example.test:19450', 'http://127.0.0.1:65536', 'http://127.0.0.1:0',
                        'http://127.0.0.1:19450/path', 'http://127.0.0.1:19450\n'],
            'request_limit': [True, '0', -1, 2147483648],
            'ca': ['/etc/greenwood/../other.pem', '/etc/greenwood//ca.pem', '/etc/greenwood/./ca.pem',
                   '/etc/greenwood/ca.pem\n'],
            'ca_sha256': ['A' * 64, 'a' * 63, None],
        }
        for field, values in cases.items():
            for value in values:
                with self.subTest(field=field, value=value):
                    entry = dict(self.frontend, **{field: value})
                    with self.assertRaises(AnsibleFilterError):
                        MODULE.validate_frontends([entry])

    def test_duplicate_service_or_listener(self):
        other = copy.deepcopy(self.frontend)
        with self.assertRaises(AnsibleFilterError):
            MODULE.validate_frontends([self.frontend, other])
        other['name'] = 'rpc'
        with self.assertRaises(AnsibleFilterError):
            MODULE.validate_frontends([self.frontend, other])
        other['port'] += 1
        self.assertTrue(MODULE.validate_frontends([self.frontend, other]))

    def test_role_discovers_filter_in_local_ansible(self):
        root = PLUGIN.parents[3]
        for valid in (True, False):
            with self.subTest(valid=valid), tempfile.TemporaryDirectory(prefix='gw-https-test-') as tmp:
                frontend = dict(self.frontend)
                if not valid:
                    frontend['address'] = '999.1.2.3'
                wire = struct.pack('>I', 11) + b'ssh-ed25519' + struct.pack('>I', 32) + b'x' * 32
                play = [{'hosts': 'localhost', 'gather_facts': False,
                         'vars': {'greenwood_https_frontends': [frontend],
                                  'greenwood_policy_mode': 'renew',
                                  'greenwood_policy_environment': {
                                      'GREENWOOD_POLICY_PUBLIC_DIRECTORY': '/var/lib/greenwood-control-plane/public',
                                      'GREENWOOD_POLICY_PUBLISHER_DIRECTORY': '/var/lib/greenwood-control-plane/publisher'},
                                  'greenwood_policy_sftp': {
                                      'user': 'gw-policy-reader', 'port': 19448, 'address': '192.168.9.10',
                                      'host_key': '/etc/greenwood/transport/host_key',
                                      'client_public_key': 'ssh-ed25519 ' + base64.b64encode(wire).decode()}},
                         'tasks': [{'ansible.builtin.include_role': {
                             'name': 'control_plane', 'tasks_from': 'https_preflight'}}]}]
                path = pathlib.Path(tmp) / 'test.yml'
                path.write_text(yaml.safe_dump(play))
                env = dict(os.environ, ANSIBLE_CONFIG=str(root / 'ansible.cfg'),
                           ANSIBLE_LOCAL_TEMP=str(pathlib.Path(tmp) / 'ansible-tmp'))
                result = subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local',
                                         str(path), '--check'], env=env, cwd=tmp,
                                        text=True, capture_output=True, timeout=60)
                self.assertEqual(result.returncode == 0, valid, result.stdout + result.stderr)
                if not valid:
                    self.assertIn('IPv4 address required', result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
