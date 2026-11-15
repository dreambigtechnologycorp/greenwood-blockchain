import pathlib
import shutil
import subprocess
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]


class RuntimeBootstrapTest(unittest.TestCase):
    def test_release_autoload_does_not_depend_on_development_scripts(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            shutil.copytree(ROOT / 'src', root / 'src')
            target = root / 'system-services/runtime'
            target.mkdir(parents=True)
            shutil.copy2(ROOT / 'system-services/runtime/bootstrap.php', target)
            code = "require $argv[1]; exit(class_exists('Greenwood\\\\Provider\\\\MeteringService') && class_exists('Greenwood\\\\Provider\\\\GovernedServiceWatcher') ? 0 : 1);"
            result = subprocess.run(['php', '-r', code, str(target / 'bootstrap.php')], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertFalse((root / 'scripts').exists())


if __name__ == '__main__':
    unittest.main()
