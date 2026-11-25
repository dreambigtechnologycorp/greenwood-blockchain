"""Metadata-only fixtures; no production storage, inventories or keys are read."""
import importlib.util
import json
from pathlib import Path
import stat
from types import SimpleNamespace
import unittest
from unittest.mock import Mock


FILE = Path(__file__).resolve().parents[1] / 'files/validate_durable_storage.py'
SPEC = importlib.util.spec_from_file_location('durable_storage', FILE)
V = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(V)


class DurableStorageTest(unittest.TestCase):
    def setUp(self):
        self.nodes = {Path(path): self.directory(index) for index, path in enumerate(
            ('/', '/var', '/var/lib', '/var/lib/greenwood-provider'), 1)}
        self.seen = []
        self.result = self.mount()
        self.run = Mock(side_effect=lambda *args, **kwargs: self.result)

    @staticmethod
    def directory(inode=1, mode=0o755, uid=0, kind=stat.S_IFDIR):
        return SimpleNamespace(st_dev=1, st_ino=inode, st_mode=kind | mode, st_uid=uid, st_gid=0)

    @staticmethod
    def mount(fstype='ext4', source='/dev/mapper/greenwood-state', options='rw,relatime', target='/var/lib'):
        value = {'filesystems': [{'target': target, 'source': source, 'fstype': fstype, 'options': options}]}
        return SimpleNamespace(returncode=0, stdout=json.dumps(value))

    def lstat(self, path):
        path = Path(path)
        self.seen.append(path)
        if path not in self.nodes:
            raise FileNotFoundError(2, 'fixture directory absent')
        return self.nodes[path]

    def validate(self, paths=None):
        return V.validate(paths if paths is not None else ['/var/lib/greenwood-provider'],
                          lstat=self.lstat, run=self.run)

    def test_allowed_filesystems_return_only_mount_and_path_metadata(self):
        for fstype, source in [('ext4', '/dev/sda1'), ('xfs', '/dev/mapper/state'),
                               ('btrfs', '/dev/sda1[/state]'), ('zfs', 'pool/greenwood')]:
            with self.subTest(fstype=fstype):
                self.result = self.mount(fstype=fstype, source=source)
                result = self.validate()
                self.assertEqual(result['status'], 'PASS')
                self.assertEqual(result['paths'][0]['mount']['source'], source)
                self.assertEqual(set(result['paths'][0]), {'path', 'existing_ancestor', 'mount'})
                argv = self.run.call_args.args[0]
                self.assertEqual(argv, ['/usr/bin/findmnt', '--json', '--target',
                                        '/var/lib/greenwood-provider', '--output', 'TARGET,SOURCE,FSTYPE,OPTIONS'])

    def test_missing_leaf_uses_nearest_protected_existing_ancestor(self):
        result = self.validate(['/var/lib/new/subdirectory'])
        self.assertEqual(result['paths'][0]['existing_ancestor'], '/var/lib')
        self.assertIn('/var/lib', self.run.call_args.args[0])
        self.assertNotIn(Path('/var/lib/new/subdirectory'), self.seen)

    def test_network_volatile_unknown_or_read_only_storage_rejected(self):
        for fstype in ('nfs', 'nfs4', 'tmpfs', 'ramfs', 'overlay', 'cifs', 'fuse', '', 'unknown'):
            with self.subTest(fstype=fstype):
                self.result = self.mount(fstype=fstype)
                with self.assertRaisesRegex(V.StorageError, 'LOCAL_FILESYSTEM'):
                    self.validate()
        for options in ('ro,relatime', 'relatime', 'ro,rw'):
            self.result = self.mount(options=options)
            with self.assertRaisesRegex(V.StorageError, 'WRITABLE_DURABLE_STORAGE'):
                self.validate()

    def test_symlink_regular_file_owner_and_permissions_rejected_before_probe(self):
        for location, metadata in [('/var/lib', self.directory(kind=stat.S_IFLNK)),
                                   ('/var/lib', self.directory(kind=stat.S_IFREG)),
                                   ('/var', self.directory(uid=1000)),
                                   ('/var/lib', self.directory(mode=0o775)),
                                   ('/var/lib/greenwood-provider', self.directory(mode=0o777))]:
            with self.subTest(location=location, metadata=metadata):
                original = self.nodes[Path(location)]
                self.nodes[Path(location)] = metadata
                with self.assertRaisesRegex(V.StorageError, 'UNPROTECTED_DIRECTORY'):
                    self.validate()
                self.nodes[Path(location)] = original
        self.run.assert_not_called()

    def test_unsafe_paths_and_lists_rejected(self):
        for paths in ([], {}, ['/var/lib/../etc'], ['relative'], ['//var/lib'], ['/var/lib/'],
                      ['/var/lib\n'], ['/var/lib', '/var/lib'], [None]):
            with self.subTest(paths=paths):
                with self.assertRaises(V.StorageError):
                    self.validate(paths)
        self.run.assert_not_called()

    def test_ambiguous_missing_or_unsafe_mount_metadata_rejected(self):
        for body in ('{}', '{"filesystems":[]}', '{"filesystems":[{},{}]}', 'not json'):
            self.result = SimpleNamespace(returncode=0, stdout=body)
            with self.assertRaises(V.StorageError):
                self.validate()
        for kwargs in ({'source': ''}, {'source': 'bad\nsource'}, {'target': '/elsewhere'},
                       {'target': '/var/../var'}, {'options': 'rw,,relatime'}, {'options': 'rw,rw'}):
            self.result = self.mount(**kwargs)
            with self.assertRaises(V.StorageError):
                self.validate()
        self.result = SimpleNamespace(returncode=1, stdout='')
        with self.assertRaisesRegex(V.StorageError, 'MOUNT_PROBE_FAILED'):
            self.validate()

    def test_directory_replacement_or_creation_during_probe_rejected(self):
        def replace(*args, **kwargs):
            self.nodes[Path('/var/lib')] = self.directory(inode=99)
            return self.result
        self.run.side_effect = replace
        with self.assertRaisesRegex(V.StorageError, 'CHANGED_DURING_CHECK'):
            self.validate()
        self.run.side_effect = lambda *args, **kwargs: (
            self.nodes.update({Path('/var/lib/new'): self.directory(inode=100)}) or self.result)
        with self.assertRaisesRegex(V.StorageError, 'CHANGED_DURING_CHECK'):
            self.validate(['/var/lib/new'])


if __name__ == '__main__':
    unittest.main()
