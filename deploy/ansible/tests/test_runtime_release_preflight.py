"""Local-only Ansible check-mode tests; never connect to physical hosts."""
import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[1]
TASKS = ROOT / 'tasks/runtime_release_preflight.yml'


def run_preflight(work, release=None, absent_export=False, bad_target=False):
    source = 'a' * 40
    if release is None:
        artifact = work / 'release.tar.gz'
        with tarfile.open(artifact, 'w:gz') as tar:
            data = json.dumps({'source_sha': source}).encode()
            info = tarfile.TarInfo('./release-source.json'); info.size = len(data)
            tar.addfile(info, io.BytesIO(data))
        release = {'artifact': str(artifact), 'sha256': hashlib.sha256(artifact.read_bytes()).hexdigest(),
                   'tree_digest': 'b' * 64, 'source_sha': source}
    else:
        source = release['source_sha']
    inventory = {'all': {'children': {
        'controller': {'hosts': {'node-1': {}}},
        'validators': {'hosts': {f'node-{n}': {'ansible_connection': 'local',
            'ansible_python_interpreter': sys.executable} for n in (1, 2, 3)}}}}}
    seed = [] if absent_export else [{'ansible.builtin.set_fact': {'greenwood_controller_release': release}}]
    preflight = []
    if bad_target:
        preflight.append({'ansible.builtin.set_fact': {'greenwood_source_sha': 'c' * 40},
                          'when': "inventory_hostname == 'node-2'"})
    preflight.append({'ansible.builtin.import_tasks': str(TASKS)})
    plays = [
        {'name': 'Seed controller-owned export fixture', 'hosts': 'controller', 'gather_facts': False, 'tasks': seed},
        {'name': 'Actual canonical preflight tasks', 'hosts': 'validators', 'gather_facts': False,
         'any_errors_fatal': True, 'vars': {'greenwood_source_sha': source}, 'tasks': preflight},
        {'name': 'Harmless stand-in for cutover', 'hosts': 'validators', 'gather_facts': False,
         'tasks': [{'ansible.builtin.debug': {'msg': 'CUTOVER_BOUNDARY_REACHED'}}]},
    ]
    inv = work / 'inventory.yml'; inv.write_text(yaml.safe_dump(inventory))
    play = work / 'test.yml'; play.write_text(yaml.safe_dump(plays, sort_keys=False))
    env = dict(os.environ, ANSIBLE_LOCAL_TEMP=str(work / 'ansible-tmp'), ANSIBLE_NOCOLOR='1')
    result = subprocess.run(['ansible-playbook', '-i', str(inv), str(play), '--check'],
                            capture_output=True, text=True, env=env, timeout=60)
    return result, release


class RuntimeReleasePreflightTest(unittest.TestCase):
    def test_all_three_targets_resolve_controller_export(self):
        with tempfile.TemporaryDirectory(prefix='gw-release-test-') as tmp:
            result, release = run_preflight(Path(tmp))
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
            self.assertEqual(3, result.stdout.count('"sha256": "' + release['sha256'] + '"'))
            self.assertEqual(3, result.stdout.count('"artifact": "' + release['artifact'] + '"'))
            self.assertIn('CUTOVER_BOUNDARY_REACHED', result.stdout)

    def test_missing_export_cannot_reach_cutover(self):
        with tempfile.TemporaryDirectory(prefix='gw-release-test-') as tmp:
            result, _ = run_preflight(Path(tmp), absent_export=True)
            self.assertNotEqual(0, result.returncode)
            self.assertIn('Canonical controller artifact export is unavailable', result.stdout)
            self.assertNotIn('CUTOVER_BOUNDARY_REACHED', result.stdout)

    def test_bad_single_target_stops_all_targets_before_cutover(self):
        with tempfile.TemporaryDirectory(prefix='gw-release-test-') as tmp:
            result, _ = run_preflight(Path(tmp), bad_target=True)
            self.assertNotEqual(0, result.returncode)
            self.assertIn('fatal: [node-2]', result.stdout)
            self.assertIn('Invalid canonical release inputs', result.stdout)
            self.assertNotIn('CUTOVER_BOUNDARY_REACHED', result.stdout)

    def test_missing_and_corrupt_cache_cannot_reach_cutover(self):
        for present in (False, True):
            with self.subTest(present=present), tempfile.TemporaryDirectory(prefix='gw-release-test-') as tmp:
                work = Path(tmp); artifact = work / 'missing-or-corrupt.tar.gz'
                if present: artifact.write_bytes(b'not the expected artifact')
                release = {'artifact': str(artifact), 'sha256': 'd' * 64,
                           'tree_digest': 'b' * 64, 'source_sha': 'a' * 40}
                result, _ = run_preflight(work, release=release)
                self.assertNotEqual(0, result.returncode)
                self.assertIn('Canonical local artifact missing or corrupt', result.stdout)
                self.assertNotIn('CUTOVER_BOUNDARY_REACHED', result.stdout)

    def test_real_play_order_and_tags_preserve_preflight_barrier(self):
        plays = yaml.safe_load((ROOT / 'playbooks/release_runtime.yml').read_text())
        self.assertEqual(['controller'], plays[0]['roles'][0]['tags'])
        for play in plays[1:3]:
            self.assertIn('runtime_release', play['tags'])
            self.assertTrue(play['any_errors_fatal'])
        self.assertEqual(str(TASKS.name), Path(plays[2]['tasks'][0]['ansible.builtin.import_tasks']).name)
        self.assertEqual('stopped', plays[3]['tasks'][0]['ansible.builtin.systemd_service']['state'])
        self.assertNotIn('runtime_release_preflight', plays[3]['tags'])


if __name__ == '__main__':
    unittest.main()
