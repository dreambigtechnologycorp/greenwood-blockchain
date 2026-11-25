"""Exercise the target metadata prerequisite without targetctl or live devices."""
import copy
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

import yaml


ROOT = Path(__file__).resolve().parents[1]


class TargetMetadataInitializationTest(unittest.TestCase):
    def tasks(self):
        return yaml.safe_load((ROOT / 'roles/state_storage/tasks/target.yml').read_text())

    def test_directory_initialization_precedes_formatting_and_scoped_start(self):
        tasks = self.tasks()
        inspect = next(t for t in tasks if 'ansible.builtin.stat' in t)
        self.assertEqual(inspect['loop'], ['/', '/etc', '/etc/rtslib-fb-target'])
        for flag in ('follow', 'get_checksum', 'get_mime', 'get_attributes'):
            self.assertIs(inspect['ansible.builtin.stat'][flag], False)
        guard = next(i for i, t in enumerate(tasks) if 'ansible.builtin.assert' in t)
        create = next(i for i, t in enumerate(tasks) if 'ansible.builtin.file' in t)
        format_files = next(i for i, t in enumerate(tasks) if 'create-target-files' in str(t))
        start = next(i for i, t in enumerate(tasks)
                     if t.get('ansible.builtin.systemd_service', {}).get('state') == 'started')
        self.assertLess(guard, create)
        self.assertLess(create, format_files)
        self.assertLess(format_files, start)
        self.assertEqual(tasks[create]['ansible.builtin.file']['mode'], '0700')
        self.assertIn('not greenwood_target_metadata_paths', tasks[create]['when'])
        self.assertEqual(tasks[format_files]['when'], 'not greenwood_storage_resume_prepared_target | bool')
        self.assertFalse(any('targetctl restore' in str(t) or 'targetctl clear' in str(t) for t in tasks))

    def test_actual_ansible_guards_create_only_missing_directory_and_preserve_existing(self):
        tasks = self.tasks()
        guard = next(t for t in tasks if 'ansible.builtin.assert' in t)
        create = next(t for t in tasks if 'ansible.builtin.file' in t)
        safe = {'exists': True, 'isdir': True, 'islnk': False,
                'uid': 0, 'gid': 0, 'mode': '0755'}
        cases = [
            ('absent', {'exists': False}, True),
            ('existing', safe, True),
            ('symlink', dict(safe, islnk=True, isdir=False), False),
            ('user-owned', dict(safe, uid=1000), False),
            ('group-writable', dict(safe, mode='0775'), False),
            ('regular-file', dict(safe, isdir=False), False),
        ]
        with tempfile.TemporaryDirectory(prefix='gw-target-metadata-fixture-') as tmp:
            plays = []
            for name, metadata, success in cases:
                destination = Path(tmp) / name / 'metadata'
                destination.parent.mkdir()
                if name == 'existing':
                    destination.mkdir(mode=0o755)
                    (destination / 'saveconfig-fixture').write_text('must remain unread and unchanged')
                rows = [{'item': p, 'stat': dict(safe)} for p in ('/', '/etc')]
                rows.append({'item': '/etc/rtslib-fb-target', 'stat': metadata})
                fixture_create = copy.deepcopy(create)
                fixture_create['ansible.builtin.file'].update(
                    path=str(destination), owner=str(os.getuid()), group=str(os.getgid()))
                plays.append({
                    'hosts': 'localhost', 'gather_facts': False,
                    'vars': {'greenwood_target_metadata_paths': {'results': rows}},
                    'tasks': [
                        {'ansible.builtin.set_fact': {'fixture_rejected': False}},
                        {'block': [guard, fixture_create],
                         'rescue': [{'ansible.builtin.set_fact': {'fixture_rejected': True}}]},
                        {'ansible.builtin.assert': {'that':
                            ['fixture_rejected == ' + ('false' if success else 'true')]}}
                    ],
                })
            play = Path(tmp) / 'test.yml'
            play.write_text(yaml.safe_dump(plays))
            result = subprocess.run(
                ['ansible-playbook', '-i', 'localhost,', '-c', 'local', str(play)],
                capture_output=True, text=True, timeout=60,
                env=dict(os.environ, ANSIBLE_LOCAL_TEMP=tmp + '/local', ANSIBLE_REMOTE_TEMP=tmp + '/remote'))
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertEqual((Path(tmp) / 'absent/metadata').stat().st_mode & 0o777, 0o700)
            self.assertEqual((Path(tmp) / 'existing/metadata').stat().st_mode & 0o777, 0o755)
            self.assertEqual((Path(tmp) / 'existing/metadata/saveconfig-fixture').read_text(),
                             'must remain unread and unchanged')
            for name, _, success in cases:
                if not success:
                    self.assertFalse((Path(tmp) / name / 'metadata').exists())


if __name__ == '__main__':
    unittest.main()
