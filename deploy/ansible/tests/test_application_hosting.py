"""Local rendering/static checks; never contacts a physical host."""
import pathlib
import unittest

import jinja2
import yaml

ROOT = pathlib.Path(__file__).resolve().parents[1]


class HostingTests(unittest.TestCase):
    def test_edge_render_matches_approved_boundary(self):
        template = jinja2.Environment(undefined=jinja2.StrictUndefined).from_string(
            (ROOT / 'roles/gateway/templates/application-ingress.conf.j2').read_text())
        result = template.render(greenwood_ingress_application_id=7,
                                 greenwood_ingress_hostname='app-7.greenwoodblockchain.com',
                                 greenwood_ingress_source='10.77.0.103',
                                 greenwood_ingress_backend='10.77.0.100',
                                 greenwood_ingress_port=21007)
        self.assertIn('ProxySourceAddress 10.77.0.103', result)
        self.assertIn('ProxyPass / http://10.77.0.100:21007/', result)
        self.assertIn('ServerName app-7.greenwoodblockchain.com', result)
        self.assertNotIn('18089', result)

    def test_provider_unit_ordering(self):
        template = jinja2.Environment(undefined=jinja2.StrictUndefined).from_string(
            (ROOT / 'roles/provider/templates/application-hosting.conf.j2').read_text())
        result = template.render(greenwood_hosting_release='/opt/reviewed-release',
                                 greenwood_provider_address='10.77.0.100',
                                 greenwood_edge_address='10.77.0.103')
        self.assertIn('Requires=containerd.service greenwood-application-forwarding.service', result)
        self.assertIn('--application-peer=10.77.0.103', result)
        self.assertIn('--operator-token-file=/etc/greenwood/operator.token', result)
        self.assertNotIn('19448', result)

    def test_playbook_does_not_invoke_cleanup_or_bootstrap_stop(self):
        plays = yaml.safe_load((ROOT / 'playbooks/application_hosting.yml').read_text())
        self.assertEqual(['application_hosting', 'application_ingress'], [
            p['tasks'][0]['ansible.builtin.import_role']['tasks_from'] for p in plays])
        for role, task in [('provider', 'application_hosting'), ('gateway', 'application_ingress')]:
            data = yaml.safe_load((ROOT / f'roles/{role}/tasks/{task}.yml').read_text())
            for entry in data:
                unit = entry.get('ansible.builtin.systemd_service', {})
                self.assertNotIn(unit.get('state'), ['stopped', 'restarted'])
                self.assertNotEqual(entry.get('ansible.builtin.file', {}).get('state'), 'absent')

    def test_apache_validation_precedes_reload(self):
        handlers = yaml.safe_load((ROOT / 'roles/gateway/handlers/main.yml').read_text())
        self.assertEqual('apache2ctl configtest', handlers[0]['ansible.builtin.command'])
        self.assertEqual('reloaded', handlers[1]['ansible.builtin.systemd_service']['state'])
        self.assertEqual(handlers[0]['listen'], handlers[1]['listen'])


if __name__ == '__main__':
    unittest.main()
