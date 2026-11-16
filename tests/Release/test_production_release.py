import importlib.util
import json
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[2]
def module(name, file):
    spec=importlib.util.spec_from_file_location(name,ROOT/'scripts'/file);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
release=module('release','prepare-production-release.py');acceptance=module('acceptance','production-acceptance.py')
class ProductionReleaseTest(unittest.TestCase):
    def test_pending_configuration_never_activates(self):
        config=json.loads((ROOT/'deploy/production/release.template.json').read_text());errors=release.assess(config,'a'*40)
        self.assertGreater(len(errors),20);self.assertIn('Permanent production activation not authorized',errors)
        config['payable_profiles']=[{'resource_id':'compute.cpu.consumed','meter_version':'cgroup.cpu.v1','billing_basis':'consumed','qualification_status':'QUALIFIED','evidence_sha256':'b'*64,'authorization_sha256':'c'*64,'trust_profile':'INDEPENDENT_ATTESTED','independently_qualified':False}]
        self.assertTrue(any('Independent source' in e for e in release.assess(config,'a'*40)))
    def test_actual_endpoint_assertions_are_not_certificate_replay(self):
        keys={k:c*64 for k,c in zip('ABC','abc')};config={'chain_id':'fixture','genesis_hash':'d'*64,'validator_public_keys':keys,'hosts':[{'name':'node-'+str(n),'rpc_origin':'https://node-'+str(n)+'.invalid'} for n in range(1,5)]}
        def read(origin,path):
            if path.endswith('/status'):return {'chain_id':'fixture','genesis_hash':'d'*64,'syncing':False,'finalized_height':10}
            if path.endswith('/validators'):return {'validators':[{'validator_id':k,'public_key':v,'voting_power':1,'active':True} for k,v in keys.items()]}
            return {'height':10,'block_hash':'e'*64,'state_root':'f'*64,'finality_certificate_present':True}
        result=acceptance.check(config,read);self.assertEqual(result['status'],'PASS');self.assertFalse(result['full_physical_acceptance']);self.assertFalse(result['independent_certificate_replay'])
        def wrong(origin,path):
            result=read(origin,path)
            if 'node-4' in origin and '/blocks/' in path:result['state_root']='0'*64
            return result
        with self.assertRaises(ValueError):acceptance.check(config,wrong)
