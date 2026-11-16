"""Real generators, rendered service placement, and hostile netboot inputs; no SSH."""
import copy, hashlib, importlib.util, io, json, pathlib, subprocess, sys, tarfile, tempfile, unittest
from unittest.mock import patch
import yaml
ROOT=pathlib.Path(__file__).resolve().parents[3]
sys.path.insert(0,str(ROOT/'scripts'))
from cluster_topology import PROFILES, validate_members

def load(name,file):
    spec=importlib.util.spec_from_file_location(name,ROOT/file);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
NET=load('netboot','scripts/prepare-netboot-client.py')
PREFLIGHT=load('preflight_expanded','scripts/production-preflight.py')
ACCEPT=load('accept_expanded','scripts/production-acceptance.py')

class ExpandedTopologyTest(unittest.TestCase):
    def nodes(self,profile):
        return {h:{**copy.deepcopy(n),'address':f'192.0.2.{i+1}','ssh_user':'operator','architecture':'aarch64','gateway_address':'192.0.2.254' if n['gateway'] else None} for i,(h,n) in enumerate(PROFILES[profile]['nodes'].items())}
    def generate(self,p,nodes=None):
        return subprocess.run(['php',str(ROOT/'deploy/ansible/generate_inventory.php')],input=json.dumps({'topology_profile':{'value':p},'node_topology':{'value':nodes or self.nodes(p)}}),capture_output=True,text=True)
    def test_both_profiles_keep_three_validators(self):
        for profile in ['four','seven']:
            r=self.generate(profile);self.assertEqual(0,r.returncode,r.stderr)
            self.assertIn('[validators]\nnode-1\nnode-2\nnode-3\n',r.stdout)
        seven=self.generate('seven').stdout
        self.assertIn('[registry_hosts]\nnode-5\n',seven)
        self.assertIn('[application_providers]\nnode-6\nnode-7\n',seven)
        self.assertNotIn('[validators]\nnode-5',seven)
    def test_bad_role_duplicate_address_and_unknown_profile_rejected(self):
        for mutation in ['role','address','label','shell']:
            n=self.nodes('seven')
            if mutation=='role':n['node-5']['roles']=['validator']
            if mutation=='address':n['node-7']['address']=n['node-1']['address']
            if mutation=='label':n['node-6']['provider_label']='A'
            if mutation=='shell':n['node-5']['ssh_user']='root injected=yes'
            self.assertNotEqual(0,self.generate('seven',n).returncode)
        self.assertNotEqual(0,self.generate('invalid',self.nodes('seven')).returncode)
    def test_v2_inventory_identity_checks_cover_seven(self):
        hosts=[dict(name=h,address=n['address'],user='operator',machine_id=f'{i+1:032x}',architecture='aarch64',host_key_sha256='SHA256:'+'a'*43) for i,(h,n) in enumerate(self.nodes('seven').items())]
        c={'version':2,'topology_profile':'seven','hosts':hosts}
        self.assertEqual(7,len(PREFLIGHT.validate_inventory(c)))
        bad=copy.deepcopy(c);bad['hosts'][-1]['machine_id']=bad['hosts'][0]['machine_id']
        with self.assertRaises(ValueError):PREFLIGHT.validate_inventory(bad)
        with self.assertRaises(ValueError):validate_members(c,hosts[:-1])
    def test_activation_groups_and_preflight_cover_new_hosts(self):
        base=ROOT/'deploy/ansible'
        plays=yaml.safe_load((base/'playbooks/activate_hosting.yml').read_text())
        seed=next(p for p in plays if 'offline seed' in p['name'])
        self.assertEqual('registry_hosts',seed['hosts'])
        fresh=yaml.safe_load((base/'playbooks/fresh_cluster.yml').read_text())
        self.assertEqual('all',fresh[0]['hosts'])
        self.assertTrue(any(p.get('hosts')=='providers' for p in fresh))
        provider=(base/'roles/provider/tasks/main.yml').read_text()
        self.assertNotIn('account\n      - create',provider)
        identity=(base/'tasks/fresh_identity.yml').read_text()
        self.assertIn("when: inventory_hostname in groups['validators']",identity)
    def test_seven_rpc_acceptance_does_not_probe_storage_or_provider_hosts(self):
        keys={v:hex(i+1)[2:]*64 for i,v in enumerate('ABC')}
        hosts=[{'name':h,'rpc_origin':'https://'+h+'.example'} if h in PROFILES['seven']['groups']['rpc_hosts'] else {'name':h,'service_acceptance_sha256':'f'*64} for h in PROFILES['seven']['nodes']]
        calls=[]
        def read(origin,path):
            calls.append(origin)
            if path.endswith('/status'):return {'chain_id':'prod','genesis_hash':'a'*64,'syncing':False,'finalized_height':2}
            if path.endswith('/validators'):return {'validators':[{'validator_id':v,'public_key':k,'active':True,'voting_power':1} for v,k in keys.items()]}
            return {'height':2,'block_hash':'b'*64,'state_root':'c'*64,'finality_certificate_present':True}
        c={'topology_profile':'seven','hosts':hosts,'chain_id':'prod','genesis_hash':'a'*64,'validator_public_keys':keys}
        r=ACCEPT.check(c,read);self.assertEqual('PASS',r['status']);self.assertFalse(r['full_physical_acceptance'])
        self.assertNotIn('https://node-4.example',calls);self.assertNotIn('https://node-6.example',calls)
        c['hosts'][3].pop('service_acceptance_sha256')
        with self.assertRaises(ValueError):ACCEPT.check(c,read)
    def test_netboot_archive_rejects_escape_secrets_and_device_files(self):
        with tempfile.TemporaryDirectory() as d:
            for name in ['../../escape','etc/ssh/ssh_host_ed25519_key','/etc/greenwood/provider.key','./etc/greenwood/provider.key','etc/greenwood/provider.key','var/lib/containerd/metadata','etc/machine-id']:
                member=tarfile.TarInfo(name);member.size=10
                with self.subTest(name=name), self.assertRaises((ValueError,tarfile.FilterError)):NET.archive_filter(member,d)
            member=tarfile.TarInfo('dev/device');member.type=tarfile.CHRTYPE
            with self.assertRaises(tarfile.FilterError):NET.archive_filter(member,d)
            member=tarfile.TarInfo('usr/bin/tool');member.uid=0;member.gid=0;member.mode=0o755
            safe=NET.archive_filter(member,d);self.assertEqual((0,0,0o755),(safe.uid,safe.gid,safe.mode))
    def test_netboot_preparation_is_new_only_and_preserves_existing_clients(self):
        # Simulate protected ancestry in a non-root temporary filesystem. Archive
        # parsing/extraction/digests and overwrite rejection execute for real.
        with tempfile.TemporaryDirectory() as d:
            base=pathlib.Path(d);roots=base/'nodes';boots=base/'tftp';roots.mkdir();boots.mkdir()
            existing=roots/'node-1';existing.mkdir();(existing/'sentinel').write_text('keep')
            c={'version':1,'name':'node-6','mac':'02:00:00:00:00:06','boot_id':'12345678','address':'192.0.2.6','nfs_server':'192.0.2.4','root_parent':str(roots),'tftp_parent':str(boots)}
            members={'rootfs':{'etc/machine-id':b'', 'etc/hostname':b'template\n'},'boot':{'cmdline.txt':f'root=/dev/nfs nfsroot=192.0.2.4:{roots}/node-6/rootfs,vers=3\n'.encode()}}
            for kind,files in members.items():
                path=base/(kind+'.tar')
                with tarfile.open(path,'w') as t:
                    for name,content in files.items():
                        m=tarfile.TarInfo(name);m.size=len(content);m.mode=0o644;t.addfile(m,io.BytesIO(content))
                c[kind+'_archive']=str(path);c[kind+'_sha256']=hashlib.sha256(path.read_bytes()).hexdigest()
            with patch.object(NET,'protected',side_effect=pathlib.Path):
                bad=copy.deepcopy(c);bad['rootfs_sha256']='0'*64
                with self.assertRaises(ValueError):NET.prepare(bad,base/'bad-output')
                self.assertFalse((roots/'node-6').exists())
                result=NET.prepare(c,base/'evidence');self.assertFalse(result['activated'])
                with self.assertRaises(ValueError):NET.prepare(c,base/'again')
            self.assertEqual('keep',(existing/'sentinel').read_text())
            self.assertEqual('',(roots/'node-6/rootfs/etc/machine-id').read_text())
            self.assertEqual('node-6\n',(roots/'node-6/rootfs/etc/hostname').read_text())
            self.assertIn('192.0.2.6',(base/'evidence/exports').read_text())

    def test_templates_do_not_enable_independent_attestation_or_activation(self):
        c=yaml.safe_load((ROOT/'deploy/ansible/inventory/seven-node.example.yml').read_text())['all']
        self.assertFalse(c['vars']['greenwood_fresh_cluster_approved'])
        self.assertFalse(c['vars']['greenwood_governed_activation_approved'])
        self.assertTrue(c['hosts']['node-4']['greenwood_preserve_gateway'])
        self.assertNotIn('greenwood_validator_key_src',c['hosts']['node-6'])
if __name__=='__main__':unittest.main()
