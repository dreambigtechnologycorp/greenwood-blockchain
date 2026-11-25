"""Execute the real release-fetch plays locally; never build or contact nodes."""
import copy
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
SOURCE = 'a' * 40


def run_fetch(work, playbook, *, scope='group', custom_build=False,
              missing_pin=False, wrong_source=False, check=False):
    source_dir = work / 'sources' / SOURCE
    build_dir = work / 'custom-build' if custom_build else source_dir / 'build/release'
    artifact_dir = build_dir / 'artifacts'
    artifact_dir.mkdir(parents=True)
    artifact = artifact_dir / 'release.tar.gz'
    source = 'b' * 40 if wrong_source else SOURCE
    with tarfile.open(artifact, 'w:gz') as archive:
        payload = json.dumps({'source_sha': source}).encode()
        entry = tarfile.TarInfo('./release-source.json')
        entry.size = len(payload)
        archive.addfile(entry, io.BytesIO(payload))
    release = {
        'source_sha': source, 'artifact': str(artifact),
        'sha256': hashlib.sha256(artifact.read_bytes()).hexdigest(),
        'tree_digest': 'c' * 64,
    }
    (artifact_dir / 'latest-release.json').write_text(json.dumps(release))

    bindings = {
        'greenwood_source_dir': str(work / 'sources') + '/{{ greenwood_source_sha }}',
    }
    if not missing_pin:
        bindings['greenwood_source_sha'] = SOURCE
    if custom_build:
        bindings['greenwood_build_dir'] = str(build_dir)
    hosts = {f'node-{n}': {
        'ansible_connection': 'local', 'ansible_python_interpreter': sys.executable,
        'ansible_user': 'fixture',
    } for n in (1, 2, 3)}
    inventory = {'all': {'hosts': hosts, 'children': {
        'controller': {'hosts': {'node-1': {}}},
        'validators': {'hosts': {name: {} for name in hosts}},
        'providers': {'hosts': {}}, 'control_plane': {'hosts': {}},
        'system_runtime_hosts': {'hosts': {}},
    }}}
    if scope == 'group':
        inventory['all']['vars'] = bindings
    else:
        for host in hosts.values():
            host.update(bindings)

    original = yaml.safe_load((ROOT / 'playbooks' / playbook).read_text())
    # Use the production fetch play, excluding the build and all service actions.
    fetch = copy.deepcopy(original[1])
    fetch['become'] = False
    plays = [fetch]
    if playbook == 'release_runtime.yml':
        plays.append(copy.deepcopy(original[2]))
    plays.append({
        'name': 'Verify the artifact reached the local cache',
        'hosts': 'controller', 'gather_facts': False,
        'tags': ['runtime_release', 'runtime_release_preflight'],
        'tasks': [
            {'ansible.builtin.stat': {
                'path': str(work / 'artifacts' / artifact.name),
                'checksum_algorithm': 'sha256',
            }, 'register': 'cached_release'},
            {'ansible.builtin.assert': {
                'that': [
                    'cached_release.stat.checksum == release_facts.sha256',
                    'release_facts.source_sha == greenwood_source_sha',
                ],
                'success_msg': 'PINNED_RELEASE_FETCHED',
            }},
        ],
    })
    (work / 'playbooks').mkdir()
    # Preserve the relative paths used by the actual plays.
    (work / 'roles').symlink_to(ROOT / 'roles', target_is_directory=True)
    (work / 'tasks').symlink_to(ROOT / 'tasks', target_is_directory=True)
    inv = work / 'inventory.yml'
    inv.write_text(yaml.safe_dump(inventory))
    play = work / 'playbooks' / 'test.yml'
    play.write_text(yaml.safe_dump(plays, sort_keys=False))
    command = ['ansible-playbook', '-i', str(inv), str(play)]
    if playbook == 'release_runtime.yml':
        command += ['--tags', 'runtime_release_preflight' if check else 'runtime_release']
    if check:
        # A tagged check-mode run validates the previously fetched cache.
        (work / 'artifacts').mkdir()
        (work / 'artifacts' / artifact.name).write_bytes(artifact.read_bytes())
        command.append('--check')
    env = dict(os.environ, ANSIBLE_LOCAL_TEMP=str(work / 'ansible-tmp'),
               ANSIBLE_REMOTE_TEMP=str(work / 'remote-tmp'),
               ANSIBLE_NOCOLOR='1', ANSIBLE_ROLES_PATH=str(ROOT / 'roles'))
    return subprocess.run(command, capture_output=True, text=True, env=env, timeout=90)


class ControllerReleaseFactsTest(unittest.TestCase):
    def test_inventory_pins_and_paths_survive_fetch_and_target_preflight(self):
        for playbook in ('deploy_greenwood.yml', 'release_runtime.yml'):
            for scope, custom_build in (('group', False), ('host', True)):
                with self.subTest(playbook=playbook, scope=scope), \
                        tempfile.TemporaryDirectory(prefix='gw-controller-facts-') as tmp:
                    result = run_fetch(Path(tmp), playbook, scope=scope,
                                       custom_build=custom_build)
                    self.assertEqual(0, result.returncode, result.stdout + result.stderr)
                    self.assertIn('PINNED_RELEASE_FETCHED', result.stdout)

    def test_runtime_tagged_check_mode_keeps_inventory_source_pin(self):
        with tempfile.TemporaryDirectory(prefix='gw-controller-facts-') as tmp:
            result = run_fetch(Path(tmp), 'release_runtime.yml', check=True)
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
            self.assertIn('PINNED_RELEASE_FETCHED', result.stdout)

    def test_missing_and_mismatched_source_pins_stop_before_fetch(self):
        for playbook in ('deploy_greenwood.yml', 'release_runtime.yml'):
            for missing_pin in (True, False):
                with self.subTest(playbook=playbook, missing_pin=missing_pin), \
                        tempfile.TemporaryDirectory(prefix='gw-controller-facts-') as tmp:
                    work = Path(tmp)
                    result = run_fetch(work, playbook, missing_pin=missing_pin,
                                       wrong_source=not missing_pin)
                    self.assertNotEqual(0, result.returncode)
                    message = ('An approved source revision is required' if missing_pin
                               else 'Controller release facts do not identify')
                    self.assertIn(message, result.stdout)
                    self.assertFalse((work / 'artifacts' / 'release.tar.gz').exists())
                    self.assertNotIn('PINNED_RELEASE_FETCHED', result.stdout)


if __name__ == '__main__':
    unittest.main()
