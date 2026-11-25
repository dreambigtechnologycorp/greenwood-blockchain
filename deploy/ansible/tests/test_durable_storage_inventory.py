"""Public role/config selection and pre-start barrier; no keys or live hosts."""
import importlib.util
from pathlib import Path
import unittest

import yaml
from ansible.errors import AnsibleFilterError

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('greenwood_storage', ROOT / 'roles/control_plane/filter_plugins/greenwood_storage.py')
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
select = MODULE.greenwood_durable_storage_paths


def config(groups):
    return {'groups': groups, 'containerd_config': 'version = 3\n',
            'control_services': [], 'control_configs': {}, 'system_services': []}


class DurableStorageInventoryTest(unittest.TestCase):
    def test_validator_provider_roots_and_custom_paths_are_included(self):
        value = config(['validators', 'providers'])
        value.update(validator_data='/srv/validator', provider_data='/srv/provider',
                     provider_workers='/srv/workers')
        selected = select(value)
        for expected in ('/var/lib/greenwood', '/var/lib/greenwood-provider', '/srv/validator',
                         '/srv/provider', '/srv/provider/applications',
                         '/srv/provider/application-network', '/srv/workers', '/var/lib/containerd'):
            self.assertIn(expected, selected)
        self.assertNotIn('/run', selected)

    def test_control_paths_are_derived_and_missing_settings_rejected(self):
        value = config(['control_plane'])
        value['control_services'] = ['controller', 'receiver', 'observer', 'edge']
        common = {'policy_ledger': '/srv/policy/policy.sqlite',
                  'assignments_directory': '/srv/assignments'}
        value['control_configs'] = {
            'controller': dict(common, database_path='/srv/controller/operations.sqlite', edge_remote={'endpoint': 'https://edge.example'}),
            'receiver': dict(common, receiver_ledger='/srv/receiver/receiver.sqlite'),
            'observer': dict(common),
            'edge': dict(common, distribution_ledger='/srv/edge/distribution.sqlite', edge_directory='/srv/edge'),
        }
        selected = select(value)
        for expected in ('/srv/policy', '/srv/controller', '/srv/receiver', '/srv/edge/route-state'):
            self.assertIn(expected, selected)
        self.assertNotIn('/srv/assignments', selected)
        del value['control_configs']['receiver']['receiver_ledger']
        with self.assertRaises(AnsibleFilterError):
            select(value)

    def test_json_publication_directories_and_colocated_keys_are_not_relocated(self):
        value = config(['control_plane'])
        value['control_services'] = ['receiver']
        value['control_configs'] = {'receiver': {
            'policy_ledger': '/var/lib/greenwood-control-plane/receiver/policy.sqlite',
            'receiver_ledger': '/var/lib/greenwood-control-plane/receiver/receiver.sqlite',
            'policy_publisher_directory': '/etc/greenwood/policy/publisher',
            'assignments_directory': '/srv/assignments',
            'admissions_directory': '/srv/admissions',
        }}
        value['policy_environment'] = {
            'GREENWOOD_POLICY_PUBLIC_DIRECTORY': '/srv/policy-public',
            'GREENWOOD_POLICY_PUBLISHER_DIRECTORY': '/etc/greenwood/policy/publisher',
            'GREENWOOD_POLICY_STAGING_DIRECTORY': '/srv/policy-staging',
        }
        selected = select(value)
        self.assertIn('/var/lib/greenwood-control-plane/receiver', selected)
        for excluded in ('/etc/greenwood/policy/publisher', '/srv/assignments', '/srv/admissions',
                         '/srv/policy-public', '/srv/policy-staging'):
            self.assertNotIn(excluded, selected)

    def test_seed_database_paths_resolve_through_public_writable_binds(self):
        value = config(['control_plane', 'system_runtime_hosts'])
        value['system_services'] = [{
            'id': 'greenwood-registry-auth',
            'environment': {'GREENWOOD_REGISTRY_AUTH_DB': '/data/auth.sqlite'},
            'mounts': [{'type': 'bind', 'source': '/srv/auth', 'destination': '/data', 'options': ['rbind', 'rw']},
                       {'type': 'bind', 'source': '/etc/greenwood/issuer.key', 'destination': '/issuer.key', 'options': ['bind', 'ro']}],
        }, {
            'id': 'greenwood-registry',
            'mounts': [{'type': 'bind', 'source': '/srv/registry', 'destination': '/var/lib/registry', 'options': ['rbind', 'rw']}],
        }]
        selected = select(value)
        self.assertIn('/srv/auth', selected)
        self.assertIn('/srv/registry', selected)
        self.assertNotIn('/etc/greenwood/issuer.key', selected)
        value['system_services'][0]['environment']['GREENWOOD_REGISTRY_AUTH_DB'] = '/unmapped/auth.sqlite'
        with self.assertRaises(AnsibleFilterError):
            select(value)

    def test_custom_registry_root_requires_explicit_public_mapping(self):
        value = config(['control_plane'])
        value['system_services'] = [{'id': 'greenwood-registry', 'mounts': [{
            'type': 'bind', 'source': '/srv/zot', 'destination': '/zot', 'options': ['rw', 'bind'],
        }]}]
        with self.assertRaises(AnsibleFilterError):
            select(value)
        value['registry_container_storage'] = '/zot/data'
        self.assertIn('/srv/zot/data', select(value))

    def test_containerd_root_and_snapshotter_override_are_checked(self):
        value = config(['system_runtime_hosts'])
        value['containerd_config'] = ('version = 3\nroot = "/srv/containerd"\n'
                                      '[plugins."io.containerd.snapshotter.v1.erofs"]\nroot_path = "/srv/erofs"\n')
        self.assertTrue({'/var/lib/containerd', '/srv/containerd', '/srv/erofs'} <= set(select(value)))
        for raw in ('', 'invalid TOML', 'imports=["/private/other.toml"]', '[proxy_plugins.external]\ntype="snapshot"'):
            with self.subTest(raw=raw), self.assertRaises(AnsibleFilterError):
                select(dict(value, containerd_config=raw))

    def test_unsafe_paths_and_unselected_gateway_fail_closed(self):
        for bad in ('relative', '/', '/srv/../tmp', '/srv//state', '/srv/state;cat'):
            with self.subTest(path=bad), self.assertRaises(AnsibleFilterError):
                select(dict(config(['providers']), provider_data=bad))
        with self.assertRaises(AnsibleFilterError):
            select(config(['gateway']))

    def test_shared_finish_checks_every_runtime_host_before_activation(self):
        plays = yaml.safe_load((ROOT / 'playbooks/finish_installation.yml').read_text())
        guard = next(p for p in plays if p.get('name') == 'Reject unsupported durable storage before any first-start mutation')
        self.assertEqual('validators:providers:control_plane:system_runtime_hosts', guard['hosts'])
        self.assertTrue(guard['any_errors_fatal'])
        self.assertEqual('storage_preflight', guard['tasks'][0]['ansible.builtin.import_role']['tasks_from'])
        staging = next(i for i, p in enumerate(plays) if p.get('ansible.builtin.import_playbook') == 'control_plane.yml')
        self.assertLess(plays.index(guard), staging)
        for filename in ('fresh_cluster.yml', 'resume_control_plane.yml'):
            self.assertEqual('finish_installation.yml', yaml.safe_load((ROOT / 'playbooks' / filename).read_text())[-1]['ansible.builtin.import_playbook'])


if __name__ == '__main__':
    unittest.main()
