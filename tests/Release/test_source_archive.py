import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest


SPEC = importlib.util.spec_from_file_location('source_package', Path(__file__).resolve().parents[2] / 'scripts/package-source.py')
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class SourceArchiveTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'repo'
        self.root.mkdir()
        self.original = MODULE.ROOT
        self.addCleanup(setattr, MODULE, 'ROOT', self.original)
        MODULE.ROOT = self.root
        self.git('init', '-q')
        self.git('config', 'user.name', 'Release Test')
        self.git('config', 'user.email', 'release@example.invalid')
        for name in MODULE.REQUIRED:
            target = self.root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text('{}\n')
        self.commit()

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.root), *args], stderr=subprocess.STDOUT)

    def commit(self):
        self.git('add', '.')
        self.git('commit', '-qm', 'fixture')

    def test_only_committed_source_and_stable_checksums(self):
        (self.root / 'private.key').write_text('LOCAL ONLY')
        (self.root / 'LICENSE').write_text('UNCOMMITTED')
        output = Path(self.temp.name) / 'one'
        result = MODULE.package('HEAD', output)
        other = Path(self.temp.name) / 'two'
        self.assertEqual(result, MODULE.package('HEAD', other))
        blob = (output / result['archive']).read_bytes()
        self.assertEqual(result['archive_sha256'], hashlib.sha256(blob).hexdigest())
        with tarfile.open(output / result['archive']) as archive:
            self.assertFalse(any(m.name.endswith('private.key') for m in archive))
            member = next(m for m in archive if m.name.endswith('/LICENSE'))
            self.assertEqual(b'{}\n', archive.extractfile(member).read())
        self.assertEqual(result, json.loads((output / 'manifest.json').read_text()))
        with self.assertRaises(FileExistsError):
            MODULE.package('HEAD', output)

    def test_rejects_tracked_private_files(self):
        (self.root / 'private.key').write_text('fixture, not a real key')
        self.commit()
        with self.assertRaisesRegex(ValueError, 'Refusing sensitive'):
            MODULE.package('HEAD', Path(self.temp.name) / 'rejected')

    def test_rejects_missing_license(self):
        self.git('rm', 'LICENSE')
        self.commit()
        with self.assertRaisesRegex(ValueError, 'Missing release files'):
            MODULE.package('HEAD', Path(self.temp.name) / 'rejected')

    def test_path_policy(self):
        for path in ['docs/acceptance/result.json', '.env', 'node.sqlite', 'dist/archive.tar']:
            self.assertTrue(MODULE.forbidden(path))
        self.assertFalse(MODULE.forbidden('.env.example'))
        self.assertFalse(MODULE.forbidden('tests/Unit/ExampleTest.php'))


if __name__ == '__main__':
    unittest.main()
