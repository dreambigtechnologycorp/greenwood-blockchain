"""Validate staged transport boundaries without SSH or credential creation."""
import base64
import importlib.util
import pathlib
import struct
import unittest

import jinja2
import yaml
from ansible.errors import AnsibleFilterError

ROOT = pathlib.Path(__file__).resolve().parents[1]
ROLE = ROOT / 'roles/control_plane'
SPEC = importlib.util.spec_from_file_location('greenwood_policy_sftp', ROLE / 'filter_plugins/greenwood_policy_sftp.py')
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class PolicySftpTest(unittest.TestCase):
    def setUp(self):
        # Public wire-format fixture only. No signing or SSH identity is generated.
        wire = struct.pack('>I', 11) + b'ssh-ed25519' + struct.pack('>I', 32) + b'x' * 32
        self.config = {'user': 'gw-policy-reader', 'port': 19448, 'address': '192.168.9.10',
                       'host_key': '/etc/greenwood/policy-transport/host_key',
                       'client_public_key': 'ssh-ed25519 ' + base64.b64encode(wire).decode()}
        self.env = {'GREENWOOD_POLICY_PUBLIC_DIRECTORY': '/var/lib/greenwood-control-plane/public-policy',
                    'GREENWOOD_POLICY_PUBLISHER_DIRECTORY': '/var/lib/greenwood-control-plane/publisher'}

    def test_valid_and_disabled(self):
        self.assertTrue(MODULE.validate_sftp({}, '', {}))
        self.assertTrue(MODULE.validate_sftp(self.config, 'renew', self.env))

    def test_invalid_inventory(self):
        for field, values in {
            'user': ['root', 'operator', 'gw-policy-x\n'],
            'port': [True, '19448', 22, 65536],
            'address': ['0.0.0.0', '999.1.2.3', '224.0.0.1'],
            'host_key': ['/etc/greenwood/../key', '/etc/greenwood//key'],
            'client_public_key': ['ssh-ed25519 AAAA', self.config['client_public_key'] + ' comment'],
        }.items():
            for value in values:
                with self.subTest(field=field, value=value), self.assertRaises(AnsibleFilterError):
                    MODULE.validate_sftp(dict(self.config, **{field: value}), 'renew', self.env)
        with self.assertRaises(AnsibleFilterError):
            MODULE.validate_sftp(self.config, 'pull', self.env)
        for publisher in (self.env['GREENWOOD_POLICY_PUBLIC_DIRECTORY'],
                          self.env['GREENWOOD_POLICY_PUBLIC_DIRECTORY'] + '/keys'):
            with self.assertRaises(AnsibleFilterError):
                MODULE.validate_sftp(self.config, 'renew', dict(self.env, GREENWOOD_POLICY_PUBLISHER_DIRECTORY=publisher))

    def test_restricted_rendered_server(self):
        template = jinja2.Environment(undefined=jinja2.StrictUndefined).from_string(
            (ROLE / 'templates/policy-sftp.conf.j2').read_text())
        result = template.render(greenwood_policy_sftp=self.config, greenwood_policy_environment=self.env)
        for directive in ('PermitRootLogin no', 'PasswordAuthentication no', 'AuthenticationMethods publickey',
                          'DisableForwarding yes', 'PermitTTY no', 'PermitUserRC no',
                          'ForceCommand internal-sftp -R', 'AllowUsers gw-policy-reader'):
            self.assertIn(directive, result)
        self.assertNotIn(self.env['GREENWOOD_POLICY_PUBLISHER_DIRECTORY'], result)
        self.assertNotIn('Include ', result)

    def test_staging_does_not_start_distribution_or_default_daemons(self):
        for name in ('main.yml', 'https.yml', 'policy_sftp.yml'):
            tasks = yaml.safe_load((ROLE / 'tasks' / name).read_text())
            for task in tasks:
                self.assertNotIn('ansible.builtin.systemd_service', task)
                if 'ansible.builtin.apt' in task:
                    self.assertEqual(101, task['ansible.builtin.apt']['policy_rc_d'])

    def test_pull_uses_pinned_batch_sftp_then_verifier(self):
        script = (ROOT.parents[1] / 'system-services/deployment-controller/deploy/receiver-policy-pull.sh').read_text()
        for required in ('/usr/bin/sftp', 'StrictHostKeyChecking=yes', 'IdentitiesOnly=yes',
                         'get /bundle.json', 'receiver-policy-install.php'):
            self.assertIn(required, script)
        self.assertLess(script.index('get /bundle.json'), script.index('receiver-policy-install.php'))


if __name__ == '__main__':
    unittest.main()
