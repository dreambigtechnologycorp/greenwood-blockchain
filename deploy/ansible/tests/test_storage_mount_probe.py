"""Exact visible mount selection; synthetic metadata only, never mounts devices."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch


FILES = Path(__file__).resolve().parents[1] / 'files'
spec = importlib.util.spec_from_file_location('storage_plan', FILES / 'storage_plan.py')
P = importlib.util.module_from_spec(spec)
spec.loader.exec_module(P)
sys.modules['storage_plan'] = P
spec = importlib.util.spec_from_file_location('storage_mount_probe_fixture', FILES / 'prepare_state_storage.py')
S = importlib.util.module_from_spec(spec)
spec.loader.exec_module(S)


class StorageMountProbeTest(unittest.TestCase):
    PATH = '/mnt/greenwood'
    UUID = 'c6e5221f-aa62-49ab-a291-8f754e2506d3'

    def records(self):
        return [
            {'id': 50, 'maj:min': '0:50', 'fsroot': '/', 'target': self.PATH,
             'source': 'systemd-1', 'fstype': 'autofs', 'uuid': None, 'options': 'rw,relatime'},
            {'id': 51, 'maj:min': '8:1', 'fsroot': '/', 'target': self.PATH,
             'source': '/dev/fixture', 'fstype': 'ext4', 'uuid': self.UUID, 'options': 'rw,relatime'},
        ]

    def probe(self, records, ids=(51, 51)):
        metadata = SimpleNamespace(st_dev=os.makedev(8, 1), st_ino=2)
        with patch.object(S.os, 'open', side_effect=[9001, 9002]) as opened, \
             patch.object(S.os, 'fstat', return_value=metadata), patch.object(S.os, 'close') as closed, \
             patch.object(S, 'descriptor_mount_id', side_effect=ids), \
             patch.object(S, 'run', return_value=json.dumps({'filesystems': records})) as run:
            result = S.filesystem(self.PATH)
            self.assertEqual([call.args[0] for call in closed.call_args_list], [9002, 9001])
            self.assertEqual(opened.call_count, 2)
            self.assertIn('--mountpoint', run.call_args.args[0])
            self.assertNotIn('--first-only', run.call_args.args[0])
            return result

    def test_autofs_underlay_is_allowed_independent_of_listing_order(self):
        records = self.records()
        for listing in (records, list(reversed(records))):
            self.assertEqual(self.probe(listing), records[1])

    def test_visible_device_subdirectory_or_mount_change_is_rejected(self):
        for field, value, error in (
            ('maj:min', '8:9', 'VISIBLE_MOUNT_DEVICE_MISMATCH'),
            ('fsroot', '/subdirectory', 'EXACT_FILESYSTEM_ROOT_REQUIRED'),
            ('target', '/other', 'EXACT_FILESYSTEM_ROOT_REQUIRED'),
        ):
            records = self.records(); records[1][field] = value
            with self.subTest(field=field), self.assertRaisesRegex(P.PlanError, error):
                self.probe(records)
        with self.assertRaisesRegex(P.PlanError, 'MOUNT_CHANGED_DURING_INSPECTION'):
            self.probe(self.records(), ids=(51, 52))
        for ids in ((999,), (50,)):
            with self.assertRaises(P.PlanError):
                self.probe(self.records(), ids=ids)

    def test_duplicate_or_missing_kernel_mount_id_is_rejected(self):
        records = self.records()
        with self.assertRaisesRegex(P.PlanError, 'VISIBLE_MOUNT_NOT_UNIQUE'):
            self.probe(records + [records[1]])
        records[1].pop('id')
        with self.assertRaisesRegex(P.PlanError, 'INVALID_MOUNT_METADATA'):
            self.probe(records)

    def test_target_identity_guards_still_reject_visible_wrong_filesystem(self):
        for field, value in (('uuid', 'wrong-uuid'), ('fstype', 'nfs4'), ('options', 'ro,relatime')):
            records = self.records(); records[1][field] = value
            selected = self.probe(records)
            plan = {'target': {'machine_id': '0' * 32, 'filesystem_uuid': self.UUID}}
            def run(argv, **kwargs):
                return 'yes\n' if '-p' in argv else ''
            with self.subTest(field=field), patch.object(S, 'machine'), patch.object(S, 'protected'), \
                 patch.object(S, 'run', side_effect=run), patch.object(S, 'filesystem', return_value=selected):
                with self.assertRaisesRegex(P.PlanError, 'SHARED_SSD_IDENTITY_MISMATCH'):
                    S.check_target(plan, new=False)

    def test_kernel_descriptor_mount_id_parser_rejects_missing_and_duplicate(self):
        for contents in ('pos:\t0\nflags:\t0100000\nmnt_id:\t51\nino:\t2\n',):
            with patch.object(Path, 'read_text', return_value=contents):
                self.assertEqual(S.descriptor_mount_id(9001), 51)
        for contents in ('pos:\t0\n', 'mnt_id:\t51\nmnt_id:\t52\n', 'mnt_id:\t0\n'):
            with patch.object(Path, 'read_text', return_value=contents):
                with self.assertRaisesRegex(P.PlanError, 'MOUNT_DESCRIPTOR_ID_UNAVAILABLE'):
                    S.descriptor_mount_id(9001)

    @unittest.skipUnless(shutil.which('findmnt'), 'util-linux findmnt required')
    def test_real_findmnt_parses_autofs_and_visible_ext4_without_mounting(self):
        with tempfile.TemporaryDirectory(prefix='gw-storage-mountinfo-fixture-') as temp:
            table = Path(temp) / 'mountinfo'
            table.write_text(
                '1 0 0:1 / / rw - tmpfs tmpfs rw\n'
                '50 1 0:50 / /mnt/greenwood rw,relatime - autofs systemd-1 rw\n'
                '51 50 8:1 / /mnt/greenwood rw,relatime - ext4 /dev/fixture rw\n')
            result = subprocess.run(
                ['findmnt', '--kernel', '--list', '--json', '--tab-file', str(table), '--nocanonicalize',
                 '--mountpoint', self.PATH, '--output', 'TARGET,SOURCE,FSTYPE,UUID,OPTIONS,ID,MAJ:MIN,FSROOT'],
                capture_output=True, text=True, check=True)
            records = json.loads(result.stdout)['filesystems']
            self.assertEqual({item['fstype'] for item in records}, {'autofs', 'ext4'})
            self.assertEqual(self.probe(records)['id'], 51)


if __name__ == '__main__':
    unittest.main()
