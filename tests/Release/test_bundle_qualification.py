import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('bundle', Path(__file__).resolve().parents[2] / 'scripts/package-production-bundle.py')
bundle = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bundle)

class BundleQualification(unittest.TestCase):
    def test_rejects_dirty_wrong_revision_failed_or_absent_status(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'verification.json'
            good = dict(status='PASS', source_commit='a'*40, working_tree_may_be_dirty=False)
            for patch in [dict(status='FAIL'), dict(source_commit='b'*40), dict(working_tree_may_be_dirty=True), dict(working_tree_may_be_dirty=None), dict(status=None)]:
                path.write_text(json.dumps(good | patch))
                with self.assertRaises(ValueError): bundle.qualification(path, 'a'*40)
            path.write_text(json.dumps(good))
            self.assertEqual(bundle.qualification(path, 'a'*40), good)
