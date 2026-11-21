"""Execute public-only admission guards locally; no inventory or credentials."""
import copy
import os
import pathlib
import subprocess
import tempfile
import unittest
import yaml

ROOT = pathlib.Path(__file__).resolve().parents[1]

class ReceiverTopologyTests(unittest.TestCase):
    def test_actual_inventory_guards(self):
        all_tasks = yaml.safe_load((ROOT/'roles/control_plane/tasks/main.yml').read_text())
        tasks = [t for t in all_tasks if t['name'] in (
            'Require the complete seven-node assignment receiver set',
            'Require the authenticated local provider listener for observers and controllers')]
        self.assertEqual(len(tasks), 2)
        peers = {f'node-{n}': {} for n in (1, 2, 3, 5, 6, 7)}
        good = dict(greenwood_topology_profile='seven',
                    greenwood_control_services=['receiver', 'observer'],
                    greenwood_control_configs={
                        'receiver': {'node_id': 'localhost', 'assignment_receivers': peers},
                        'observer': {'provider_http': 'unix:///run/greenwood-provider/operator.sock',
                                     'operator_token_file': '/etc/greenwood/operator.token'}})
        cases = [good]
        missing = copy.deepcopy(good)
        del missing['greenwood_control_configs']['receiver']['assignment_receivers']['node-7']
        cases.append(missing)
        legacy = copy.deepcopy(good)
        legacy['greenwood_control_configs']['receiver']['assignment_receivers'] = {'node-1': {}, 'node-4': {}}
        cases.append(legacy)
        wrong = copy.deepcopy(good)
        wrong['greenwood_control_configs']['receiver']['node_id'] = 'node-4'
        cases.append(wrong)
        remote = copy.deepcopy(good)
        remote['greenwood_control_configs']['observer']['provider_http'] = 'http://192.168.72.100:9201'
        cases.append(remote)
        for i, variables in enumerate(cases):
            with tempfile.TemporaryDirectory() as tmp:
                play = pathlib.Path(tmp)/'play.yml'
                play.write_text(yaml.safe_dump([dict(hosts='localhost', gather_facts=False,
                                                    vars=variables, tasks=tasks)]))
                result = subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local', str(play)],
                                        capture_output=True, text=True, timeout=30,
                                        env=dict(os.environ, ANSIBLE_LOCAL_TEMP=tmp+'/local'))
                self.assertEqual(result.returncode == 0, i == 0, result.stdout+result.stderr)
