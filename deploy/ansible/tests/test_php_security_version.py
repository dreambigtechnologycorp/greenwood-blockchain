import pathlib
import unittest

import yaml


ROOT = pathlib.Path(__file__).resolve().parents[1]


class PhpSecurityVersionTest(unittest.TestCase):
    def test_runtime_packages_and_executable_are_pinned(self):
        tasks = yaml.safe_load((ROOT / "tasks/php_runtime.yml").read_text())
        self.assertEqual("php_repository.yml", tasks[0]["ansible.builtin.import_tasks"])
        apt = tasks[1]["ansible.builtin.apt"]
        self.assertTrue(apt["name"])
        self.assertTrue(all(p.endswith("=8.5.12*") for p in apt["name"]))
        self.assertEqual(101, apt["policy_rc_d"])
        self.assertIn("PHP_VERSION_ID !== 80512", tasks[2]["ansible.builtin.command"]["argv"][-1])

    def test_provisioning_entrypoints_include_security_runtime(self):
        paths = [f"roles/{role}/tasks/main.yml" for role in
                 ("common", "controller", "provider", "control_plane")]
        for path in paths:
            with self.subTest(path=path):
                self.assertIn("../tasks/php_runtime.yml", (ROOT / path).read_text())
        for path in ["playbooks/bootstrap_greenwood.yml", "playbooks/bootstrap_local.yml"]:
            self.assertIn("common", yaml.safe_load((ROOT / path).read_text())[0]["roles"])


if __name__ == "__main__":
    unittest.main()
