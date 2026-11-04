import json
import pathlib
import subprocess
import unittest

import yaml

ROOT = pathlib.Path(__file__).resolve().parents[1]


class FreshClusterTest(unittest.TestCase):
    def topology(self):
        return {f"node-{n}": dict(address=f"10.77.0.{99+n}", ssh_user="operator",
                architecture="aarch64", roles=["validator", "provider"] if n < 4 else [],
                validator_label=chr(64+n) if n < 4 else None,
                provider_label=chr(64+n) if n < 4 else None, controller=n == 1,
                gateway=n == 4, gateway_address="10.77.0.1" if n == 4 else None)
                for n in range(1, 5)}

    def generate(self, nodes):
        return subprocess.run(["php", str(ROOT / "generate_inventory.php")],
            input=json.dumps({"node_topology": {"value": nodes}}), text=True, capture_output=True)

    def test_inventory_includes_fixed_hosting_roles(self):
        result = self.generate(self.topology())
        self.assertEqual(0, result.returncode, result.stderr)
        for section in ("[control_plane]\nnode-1\nnode-4", "[application_providers]\nnode-1", "[application_edges]\nnode-4"):
            self.assertIn(section, result.stdout)

    def test_inventory_rejects_injection_and_swapped_validators(self):
        for field, value in (("ssh_user", "operator injected=yes"), ("address", "127.0.0.1 extra=yes"), ("validator_label", "B")):
            nodes = self.topology()
            nodes["node-1"][field] = value
            self.assertNotEqual(0, self.generate(nodes).returncode)

    def test_fresh_order_and_destructive_guards(self):
        plays = yaml.safe_load((ROOT / "playbooks/fresh_cluster.yml").read_text())
        imports = [p["ansible.builtin.import_playbook"] for p in plays if "ansible.builtin.import_playbook" in p]
        self.assertLess(imports.index("bootstrap_greenwood.yml"), imports.index("deploy_greenwood.yml"))
        self.assertEqual("activate_hosting.yml", imports[-1])
        self.assertTrue(plays[0]["any_errors_fatal"])
        preflight = (ROOT / "tasks/fresh_preflight.yml").read_text()
        for guard in ("greenwood_fresh_cluster_approved", "not ansible_check_mode", "/var/lib/greenwood/signing.sqlite", "not item.stat.exists"):
            self.assertIn(guard, preflight)

    def test_identity_never_generates_keys_or_overwrites(self):
        source = (ROOT / "tasks/fresh_identity.yml").read_text()
        self.assertIn("force: false", source)
        self.assertIn("no_log: true", source)
        self.assertIn("validate_cluster_identity.php", source)
        self.assertNotIn("generate", source.lower())

    def test_release_contains_hosting_and_recovery(self):
        builder = (ROOT.parent / "build-validator-release.sh").read_text()
        self.assertIn("runtime system-services deploy/application-recovery composer.json", builder)


if __name__ == "__main__":
    unittest.main()
