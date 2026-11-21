"""Startup dependency and fail-closed regression checks; never connect to hosts."""
import pathlib
import os
import json
import subprocess
import tempfile
import unittest
import yaml

ROOT = pathlib.Path(__file__).resolve().parents[1]

class RpcStartupTest(unittest.TestCase):
    def test_fresh_dependency_order(self):
        plays = yaml.safe_load((ROOT/'playbooks/fresh_cluster.yml').read_text())
        names = [p.get('name', p.get('ansible.builtin.import_playbook')) for p in plays]
        order = ['Provision and validate all protocol identities before starting any node',
                 'Install runtime release on the non-validator edge',
                 'Install application hosting without publishing an application route',
                 'control_plane.yml', 'Start the approved validator set', 'activate_rpc.yml',
                 'Establish provider-only HTTPS client trust',
                 'Start approved provider identities independently of validator placement',
                 'activate_hosting.yml']
        self.assertEqual(sorted(names.index(x) for x in order), [names.index(x) for x in order])
        # No restart/start may bypass HTTPS readiness during the earlier hosting stage.
        stage = plays[names.index(order[2])]
        self.assertFalse(any('ansible.builtin.systemd_service' in t for t in stage['tasks']))

    def test_origins_before_relays_with_all_host_approval_gate(self):
        plays = yaml.safe_load((ROOT/'playbooks/activate_rpc.yml').read_text())
        self.assertEqual(['rpc_hosts', 'validators', 'rpc_hosts:!validators'], [p['hosts'] for p in plays])
        self.assertTrue(all(p['any_errors_fatal'] for p in plays))
        guards = plays[0]['tasks'][0]['ansible.builtin.assert']['that']
        for flag in ['greenwood_operator_secret_access_authorized', 'greenwood_activate_protocol', 'greenwood_activate_hosting']:
            self.assertTrue(any(flag in g for g in guards))
        self.assertIn('not ansible_check_mode', guards)

    def test_probe_bypasses_public_dns_without_disabling_tls(self):
        tasks = yaml.safe_load((ROOT/'tasks/start_rpc.yml').read_text())
        probe = next(t for t in tasks if 'ansible.builtin.command' in t)
        argv = probe['ansible.builtin.command']['argv']
        self.assertIn('--resolve', argv)
        self.assertIn('--cacert', argv)
        self.assertIn('--noproxy', argv)
        self.assertNotIn('-k', argv)
        checks = tasks[-1]['ansible.builtin.assert']['that']
        self.assertTrue(any('genesis_hash' in x for x in checks))
        self.assertTrue(any('chain_id' in x for x in checks))
        self.assertTrue(any('finalized_height' in x for x in checks))

    def test_actual_readiness_assertions_reject_wrong_or_unfinalized_chain(self):
        # Execute the production assert task on localhost with public response fixtures.
        # No systemd/SSH/certificate/private-key tasks execute.
        gate = yaml.safe_load((ROOT/'tasks/start_rpc.yml').read_text())[-1]
        for fixture, passes in [({'chain_id':'prod','genesis_hash':'a'*64,'finalized_height':1}, True),
                                ({'chain_id':'other','genesis_hash':'a'*64,'finalized_height':1}, False),
                                ({'chain_id':'prod','genesis_hash':'b'*64,'finalized_height':1}, False),
                                ({'chain_id':'prod','genesis_hash':'a'*64,'finalized_height':0}, False),
                                ({}, False)]:
            with self.subTest(fixture=fixture), tempfile.TemporaryDirectory() as tmp:
                play = [{'hosts':'localhost','gather_facts':False,
                         'vars':{'provider_chain_id':'prod','greenwood_expected_genesis_hash':'a'*64,
                                 'greenwood_rpc_probe':{'stdout':json.dumps(fixture)}},
                         'tasks':[gate, {'name':'Provider startup sentinel', 'ansible.builtin.debug':{'msg':'PROVIDER_GATE_PASSED'}}]}]
                path = pathlib.Path(tmp)/'gate.yml';path.write_text(yaml.safe_dump(play))
                result = subprocess.run(['ansible-playbook','-i','localhost,','-c','local',str(path)],
                    env=dict(os.environ, ANSIBLE_LOCAL_TEMP=tmp+'/local'), capture_output=True,text=True,timeout=30)
                self.assertEqual(result.returncode==0, passes, result.stdout+result.stderr)
                self.assertEqual('PROVIDER_GATE_PASSED' in result.stdout, passes)

    def test_bootstrap_dependencies_exclude_cycles_and_public_tunnel(self):
        tasks = (ROOT/'tasks/rpc_dependencies.yml').read_text()
        self.assertIn("backend == 'http://127.0.0.1:9201'", tasks)
        self.assertIn('backend in greenwood_validator_rpc_origins', tasks)
        self.assertIn('provider_rpc_endpoint in greenwood_validator_rpc_origins', tasks)
        preflight = (ROOT/'tasks/fresh_preflight.yml').read_text()
        self.assertIn('import_tasks: rpc_dependencies.yml', preflight)

    def test_dependency_preflight_executes_for_seven_nodes_and_rejects_cycle(self):
        for cyclic in (False, True):
            with self.subTest(cyclic=cyclic), tempfile.TemporaryDirectory() as tmp:
                hosts = {}
                for n in [1,2,3,4,5,6,7]:
                    h = {'ansible_connection':'local'}
                    if n in [1,2,3,5]:
                        h['greenwood_https_frontends']=[{'name':'rpc','tls_name':f'rpc-node-{n}.example.test','port':8443,
                            'backend':('https://rpc-node-5.example.test:8443' if cyclic else 'https://rpc-node-1.example.test:8443') if n==5 else 'http://127.0.0.1:9201'}]
                    if n in [6,7]:h['provider_rpc_endpoint']='https://rpc-node-1.example.test:8443'
                    hosts[f'node-{n}']=h
                inventory={'all':{'hosts':hosts,'children':{g:{'hosts':{f'node-{n}':{} for n in ns}} for g,ns in
                    {'validators':[1,2,3],'providers':[1,2,3,6,7],'rpc_hosts':[1,2,3,5]}.items()}}}
                inv=pathlib.Path(tmp)/'inventory.yml';inv.write_text(yaml.safe_dump(inventory))
                play=pathlib.Path(tmp)/'play.yml';play.write_text(yaml.safe_dump([{'hosts':'all','gather_facts':False,
                    'any_errors_fatal':True,'tasks':[{'ansible.builtin.import_tasks':str(ROOT/'tasks/rpc_dependencies.yml')}]}]))
                result=subprocess.run(['ansible-playbook','-i',str(inv),str(play)],capture_output=True,text=True,
                    env=dict(os.environ,ANSIBLE_LOCAL_TEMP=tmp+'/local'),timeout=40)
                self.assertEqual(result.returncode==0,not cyclic,result.stdout+result.stderr)

    def test_ca_trust_is_pinned_and_refreshes_only_when_changed(self):
        tasks = yaml.safe_load((ROOT/'tasks/rpc_client_trust.yml').read_text())
        self.assertIn('rpc_ca_source.stat.checksum == greenwood_rpc_ca_file_sha256', tasks[1]['ansible.builtin.assert']['that'])
        self.assertEqual('rpc_ca_installed.changed', tasks[-1]['when'])
        preflight = (ROOT/'tasks/fresh_preflight.yml').read_text()
        self.assertIn('fresh_rpc_ca.stat.checksum == greenwood_rpc_ca_file_sha256', preflight)

if __name__ == '__main__':
    unittest.main()
