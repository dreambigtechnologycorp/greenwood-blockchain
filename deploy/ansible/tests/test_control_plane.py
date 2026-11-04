"""Source-only checks. No SSH, systemd changes, or policy signing."""
import base64
import json
import pathlib
import subprocess
import tempfile
import unittest

import jinja2
import yaml

ROOT = pathlib.Path(__file__).resolve().parents[1]


class ControlPlaneTests(unittest.TestCase):
    def render(self, name, **values):
        env = jinja2.Environment(undefined=jinja2.StrictUndefined)
        env.filters['to_json'] = json.dumps
        env.filters['b64encode'] = lambda value: base64.b64encode(value.encode()).decode()
        return env.from_string((ROOT / 'roles/control_plane/templates' / name).read_text()).render(**values)

    def test_data_only_configuration_escapes_php(self):
        data = {'deployment_enabled': False, 'value': "'; system('false'); //"}
        rendered = self.render('config.php.j2', item={'value': data})
        with tempfile.NamedTemporaryFile(suffix='.php', mode='w') as fixture:
            fixture.write(rendered)
            fixture.flush()
            result = subprocess.check_output(['php', '-r', 'echo json_encode(require $argv[1]);', fixture.name])
        self.assertEqual(data, json.loads(result))

    def test_units_use_root_release_and_private_configuration(self):
        for service in ('controller', 'receiver', 'observer', 'edge'):
            result = self.render('service.j2', item=service, greenwood_hosting_release='/opt/approved-release')
            self.assertIn('WorkingDirectory=/opt/approved-release/', result)
            self.assertIn('ProtectSystem=strict', result)
            self.assertIn('UMask=0077', result)
            self.assertIn(f'/etc/greenwood/control-plane/{service}.php', result)
            if service in ('controller', 'observer'):
                self.assertIn('Requires=greenwood-provider.service', result)
            self.assertNotIn('192.168.', result)

    def test_staging_never_starts_services_or_replaces_keys(self):
        tasks = yaml.safe_load((ROOT / 'roles/control_plane/tasks/main.yml').read_text())
        for task in tasks:
            self.assertNotIn('ansible.builtin.shell', task)
            self.assertNotIn('ansible.builtin.systemd_service', task)
        key_task = next(t for t in tasks if 'externally authorized' in t['name'])
        self.assertFalse(key_task['ansible.builtin.copy']['force'])
        self.assertTrue(key_task['no_log'])
        self.assertFalse(key_task['diff'])

    def test_gateway_convergence_never_deletes_or_stops(self):
        tasks = yaml.safe_load((ROOT / 'roles/gateway/tasks/main.yml').read_text())
        for task in tasks:
            self.assertNotIn('ansible.builtin.file', task)
            self.assertNotIn('ansible.builtin.systemd_service', task)
        teardown = yaml.safe_load((ROOT / 'roles/gateway/tasks/teardown_protocol.yml').read_text())
        self.assertIn('greenwood_gateway_teardown_authorized', str(teardown[0]))

    def test_frontend_and_policy_templates(self):
        frontend = {'name':'controller', 'address':'192.168.9.10', 'port':19449,
                    'tls_name':'controller.example.test', 'backend':'http://127.0.0.1:19450',
                    'request_limit':16384, 'certificate':'/etc/greenwood/tls/service.pem',
                    'private_key':'/etc/greenwood/tls/service.key'}
        result=self.render('https.conf.j2',item=frontend)
        self.assertIn('SSLProtocol -all +TLSv1.2 +TLSv1.3',result)
        self.assertIn('ProxyPass / http://127.0.0.1:19450/',result)
        self.assertNotIn('CustomLog',result)
        policy=self.render('policy.service.j2',greenwood_policy_mode='pull',
            greenwood_hosting_release='/opt/approved-release',
            greenwood_policy_environment={'GREENWOOD_POLICY_SSH_PEER':'policy@192.168.9.10'})
        self.assertIn('receiver-policy-pull.sh',policy)
        self.assertIn('policy@192.168.9.10',policy)
        timer=self.render('reconcile.timer.j2')
        self.assertIn('Unit=greenwood-managed-edge.service',timer)


if __name__ == '__main__':
    unittest.main()
