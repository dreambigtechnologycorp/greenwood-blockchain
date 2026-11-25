"""Storage-auth fixtures only; production credentials are never generated."""
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import subprocess
import unittest
import yaml

FILE = Path(__file__).resolve().parents[3] / 'scripts/prepare-storage-auth.py'
SPEC = importlib.util.spec_from_file_location('storage_auth', FILE)
A = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(A)


class StorageAuthTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='gw-storage-auth-fixture-')
        self.root = Path(self.tmp.name) / 'auth'

    def tearDown(self):
        self.tmp.cleanup()

    def generate(self):
        values = iter('A' * 16 + 'B' * 16 + 'C' * 16)
        return A.prepare(self.root, generate=True, choose=lambda alphabet: next(values))

    def test_explicit_generation_protects_distinct_credentials_and_does_not_print_them(self):
        result = self.generate()
        self.assertEqual(result, {'status': 'PREPARED', 'files': 3, 'existing_preserved': 0})
        self.assertEqual(self.root.stat().st_mode & 0o777, 0o700)
        for index, node in enumerate(A.NODES):
            p = self.root / (node + '.json')
            self.assertEqual(p.stat().st_mode & 0o777, 0o600)
            value = json.loads(p.read_text())
            self.assertEqual(value['username'], 'greenwood-prod-v1-' + node)
            self.assertEqual(value['password'], chr(ord('A') + index) * 16)
            self.assertNotIn(value['password'], json.dumps(result))

    def test_check_and_rerun_preserve_existing_files(self):
        self.generate()
        before = {p.name: (p.read_bytes(), p.stat().st_mtime_ns) for p in self.root.iterdir()}
        self.assertEqual(A.prepare(self.root)['status'], 'CHECKED')
        A.prepare(self.root, generate=True, choose=lambda _: self.fail('must not regenerate'))
        self.assertEqual(before, {p.name: (p.read_bytes(), p.stat().st_mtime_ns) for p in self.root.iterdir()})

    def test_read_only_check_does_not_create_missing_directory(self):
        with self.assertRaisesRegex(A.AuthError, 'MISSING'):
            A.prepare(self.root)
        self.assertFalse(self.root.exists())

    def test_unsafe_permissions_and_links_fail(self):
        self.generate()
        p = self.root / 'node-1.json'
        p.chmod(0o644)
        with self.assertRaisesRegex(A.AuthError, '0600'):
            A.prepare(self.root)
        p.chmod(0o600)
        linked = self.root / 'linked'
        os.link(p, linked)
        with self.assertRaisesRegex(A.AuthError, '0600'):
            A.prepare(self.root)


class StorageAuthPlacementTest(unittest.TestCase):
    ROOT = FILE.parents[1] / 'deploy/ansible'

    def test_no_authorization_or_limited_scope_stops_before_host_and_secret_access(self):
        for options in ([], ['--limit', 'node-1', '-e',
                             '{"greenwood_operator_secret_access_authorized":true,"greenwood_storage_preparation_approved":true,"greenwood_storage_auth_directory":"/unused-fixture"}']):
            with self.subTest(options=options), tempfile.TemporaryDirectory(prefix='gw-auth-gate-') as tmp:
                result = subprocess.run(
                    ['ansible-playbook', '-i', str(self.ROOT / 'inventory/seven-node.example.yml'),
                     str(self.ROOT / 'playbooks/install_storage_auth.yml'), *options],
                    capture_output=True, text=True, timeout=60,
                    env=dict(os.environ, ANSIBLE_LOCAL_TEMP=tmp + '/local', ANSIBLE_REMOTE_TEMP=tmp + '/remote'))
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('Human execution with the reviewed plan', result.stdout)
                self.assertNotIn('Check actual machine identity', result.stdout)
                self.assertNotIn('Validate all local CHAP files', result.stdout)
                self.assertNotIn('Place separate storage credentials', result.stdout)

    def test_metadata_guard_rejects_linked_or_unprotected_paths_without_reading_contents(self):
        tasks = yaml.safe_load((self.ROOT / 'roles/state_storage/tasks/auth_preflight.yml').read_text())
        probe = next(t['ansible.builtin.command']['argv'][2] for t in tasks
                     if t.get('name', '').startswith('Reject unprotected'))
        # Execute the actual preflight probe against temporary metadata; the
        # synthetic file is deliberately unreadable and no read API is needed.
        with tempfile.TemporaryDirectory(prefix='gw-auth-metadata-') as tmp:
            root = Path(tmp)
            directory = root / '.secrets/greenwood-storage'
            directory.mkdir(parents=True, mode=0o700)
            path = directory / 'node-1.json'
            path.touch(mode=0o600)
            path.write_text('synthetic-content-must-not-be-disclosed')
            # Model root-owned ancestry with our fixture's uid. This does not
            # require root or touch /root; all paths are in this fixture only.
            probe = probe.replace("'/root/.secrets/greenwood-storage'", repr(str(directory)))
            probe = probe.replace('for part in reversed((path, *path.parents)):',
                                  'for part in reversed((path, path.parent)):')
            probe = probe.replace('value.st_uid != 0', 'value.st_uid != os.geteuid()')
            def run():
                return subprocess.run(['python3', '-c', probe, '["node-1"]'],
                                      capture_output=True, text=True)
            self.assertEqual(run().returncode, 0)
            directory.chmod(0o775)
            result = run()
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('path=' + str(directory), result.stderr)
            self.assertIn('mode=0775 type=directory', result.stderr)
            self.assertNotIn('synthetic-content-must-not-be-disclosed', result.stderr)
            directory.chmod(0o700)
            path.chmod(0o644)
            result = run()
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('path=' + str(path), result.stderr)
            self.assertIn('mode=0644 type=regular-file', result.stderr)
            self.assertNotIn('synthetic-content-must-not-be-disclosed', result.stderr)
            path.chmod(0o600)
            os.link(path, root / 'linked')
            self.assertNotEqual(run().returncode, 0)
            path.unlink()
            path.symlink_to(root / 'linked')
            result = run()
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('type=symlink', result.stderr)
            self.assertNotIn(str(root / 'linked'), result.stderr)
            denied = ('from pathlib import Path\n'
                      'original_lstat = Path.lstat\n'
                      'def denied_lstat(self, *args, **kwargs):\n'
                      f'    if str(self) == {str(directory)!r}:\n'
                      "        raise PermissionError(13, 'synthetic-private-error-detail')\n"
                      '    return original_lstat(self, *args, **kwargs)\n'
                      'Path.lstat = denied_lstat\n')
            result = subprocess.run(['python3', '-c', denied + probe, '["node-1"]'],
                                    capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('metadata unavailable: path=' + str(directory) + ' errno=13', result.stderr)
            self.assertNotIn('synthetic-private-error-detail', result.stderr)


if __name__ == '__main__':
    unittest.main()
