"""Disposable local certificate fixtures; never reads an inventory or production key."""
import hashlib
import importlib.util
import json
import os
import pathlib
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import jinja2
import yaml

ROOT = pathlib.Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location('renew', ROOT/'scripts/renew-https.py')
R = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(R)

class RenewalTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        cls.root = pathlib.Path(cls.tmp.name)
        def run(*args):
            subprocess.run(['openssl', *args], cwd=cls.root, check=True, capture_output=True)
        run('req','-x509','-newkey','rsa:2048','-nodes','-keyout','root.key','-out','root.pem',
            '-days','2','-subj','/CN=Disposable Root','-addext','basicConstraints=critical,CA:TRUE',
            '-addext','keyUsage=critical,keyCertSign,cRLSign')
        run('req','-new','-newkey','rsa:2048','-nodes','-keyout','inter.key','-out','inter.csr','-subj','/CN=Disposable Intermediate')
        (cls.root/'inter.ext').write_text('basicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\n')
        run('x509','-req','-in','inter.csr','-CA','root.pem','-CAkey','root.key','-set_serial','2','-days','2','-extfile','inter.ext','-out','inter.pem')
        run('req','-new','-newkey','rsa:2048','-nodes','-keyout','leaf.key','-out','leaf.csr','-subj','/CN=rpc.example.test')
        (cls.root/'leaf.ext').write_text('basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:rpc.example.test\n')
        run('x509','-req','-in','leaf.csr','-CA','inter.pem','-CAkey','inter.key','-set_serial','3','-days','2','-extfile','leaf.ext','-out','leaf.pem')
        run('x509','-req','-in','leaf.csr','-CA','root.pem','-CAkey','root.key','-set_serial','4','-days','2','-extfile','leaf.ext','-out','direct.pem')
        cls.pin = hashlib.sha256(subprocess.check_output(['openssl','x509','-in',str(cls.root/'root.pem'),'-outform','DER'])).hexdigest()

    @classmethod
    def tearDownClass(cls): cls.tmp.cleanup()

    def bundle(self, folder):
        for target,source in [('cert.pem','leaf.pem'),('chain.pem','inter.pem'),('privkey.pem','leaf.key')]:
            (folder/target).write_bytes((self.root/source).read_bytes())
        (folder/'fullchain.pem').write_bytes((folder/'cert.pem').read_bytes()+(folder/'chain.pem').read_bytes())

    def verify(self, folder, **kw):
        # The fixture uses non-root temporary storage; production path protections
        # are tested separately and are never disabled in the actual command.
        with patch.object(R,'protected', side_effect=lambda p,**unused:pathlib.Path(p)):
            R.validate_bundle(folder,self.root/'root.pem',kw.get('pin',self.pin),kw.get('host','rpc.example.test'),kw.get('minimum',3600))

    def test_real_intermediate_chain_and_fail_closed_cases(self):
        with tempfile.TemporaryDirectory() as d:
            p=pathlib.Path(d);self.bundle(p);self.verify(p)
            for kw in [{'host':'other.example.test'},{'pin':'0'*64},{'minimum':99999999}]:
                with self.assertRaises(ValueError): self.verify(p,**kw)
            (p/'privkey.pem').write_bytes((self.root/'root.key').read_bytes())
            with self.assertRaises(ValueError): self.verify(p)
            self.bundle(p);(p/'chain.pem').write_bytes((self.root/'root.pem').read_bytes())
            (p/'fullchain.pem').write_bytes((p/'cert.pem').read_bytes()+(p/'chain.pem').read_bytes())
            with self.assertRaises(ValueError): self.verify(p)
            self.bundle(p);(p/'fullchain.pem').write_bytes((p/'cert.pem').read_bytes())
            with self.assertRaises(ValueError): self.verify(p)

    def initial_generation(self, parent):
        old=parent/('generation-'+'0'*32);old.mkdir()
        for n in R.FILES: (old/n).write_text('old')
        (parent/'active.conf').write_text('SSLCertificateFile '+str(old/'fullchain.pem')+'\nSSLCertificateKeyFile '+str(old/'privkey.pem')+'\n')
        return old

    def test_atomic_pair_and_rollback(self):
        for failure in ('none','check','reload'):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as d:
                parent=pathlib.Path(d);self.initial_generation(parent)
                stage=parent/'stage';stage.mkdir()
                for name in R.FILES: (stage/name).write_text('new')
                calls=[]
                def check():
                    current=R.active_generation(parent)
                    self.assertEqual({(current/n).read_text() for n in R.FILES},{'new'})
                    calls.append('check')
                    if failure=='check': raise RuntimeError('fixture')
                def reload():
                    calls.append('reload')
                    if failure=='reload': raise RuntimeError('fixture')
                with patch.object(R,'protected',side_effect=lambda p,**kw:pathlib.Path(p)):
                    if failure=='none': R.publish(stage,parent,check,reload)
                    else:
                        with self.assertRaises(RuntimeError): R.publish(stage,parent,check,reload)
                    current=R.active_generation(parent)
                self.assertEqual({(current/n).read_text() for n in R.FILES},{'new' if failure=='none' else 'old'})
                self.assertEqual(calls,['check'] if failure=='check' else ['check','reload'])
                self.assertEqual(len(list(parent.glob('generation-*'))),2)
                self.assertFalse((parent/'active.conf').is_symlink())

    def test_failed_rollback_requires_preserving_generations(self):
        with tempfile.TemporaryDirectory() as d:
            parent=pathlib.Path(d);old=self.initial_generation(parent)
            stage=parent/'stage';stage.mkdir()
            for n in R.FILES: (stage/n).write_text('new')
            original=R.write_atomic
            calls=[]
            def write(a,b):
                calls.append(1)
                if len(calls)>1: raise OSError('fixture rollback failure')
                original(a,b)
            with patch.object(R,'protected',side_effect=lambda p,**kw:pathlib.Path(p)), patch.object(R,'write_atomic',side_effect=write):
                with self.assertRaises(R.RecoveryRequired):
                    R.publish(stage,parent,lambda: (_ for _ in ()).throw(ValueError('fixture')),lambda:None)
            self.assertEqual((old/'privkey.pem').read_text(),'old')
            self.assertEqual(len(list(parent.glob('generation-*'))),2)

    def test_initial_failed_publication_removes_pointer(self):
        with tempfile.TemporaryDirectory() as d:
            parent=pathlib.Path(d);stage=parent/'stage';stage.mkdir()
            for n in R.FILES: (stage/n).write_text('fixture')
            with self.assertRaises(ValueError):
                R.publish(stage,parent,lambda: (_ for _ in ()).throw(ValueError('fixture')),lambda:None)
            self.assertFalse((parent/'active.conf').exists())
            self.assertEqual(len(list(parent.glob('generation-*'))),1)

    def test_active_pointer_rejects_injected_directives(self):
        with tempfile.TemporaryDirectory() as d:
            parent=pathlib.Path(d);self.initial_generation(parent)
            with (parent/'active.conf').open('a') as f: f.write('Include /untrusted\n')
            with patch.object(R,'protected',side_effect=lambda p,**kw:pathlib.Path(p)):
                with self.assertRaises(ValueError): R.active_generation(parent)

    def test_certbot_archive_escape_before_read(self):
        # Resolve synthetic source symlinks without touching /etc/letsencrypt.
        with tempfile.TemporaryDirectory() as d:
            folder=pathlib.Path(d);source=folder/'source';source.mkdir();stage=folder/'stage';stage.mkdir()
            c={'source_dir':'/etc/letsencrypt/live/rpc.example.test','tls_name':'rpc.example.test'}
            for name in R.FILES: (source/name).symlink_to(folder/'unused')
            def protect(p,**kw):
                if str(p)==c['source_dir']: return pathlib.Path(c['source_dir'])
                return pathlib.Path(p)
            # A real test of path selection requires actual archive hierarchy;
            # here an out-of-lineage resolved target must be rejected before read.
            with patch.object(R,'protected',side_effect=protect), patch.object(pathlib.Path,'is_symlink',return_value=True), patch.object(pathlib.Path,'resolve',return_value=folder/'outside.pem'):
                with self.assertRaisesRegex(ValueError,'CERTBOT_ARCHIVE_ESCAPE'): R.snapshot(c,stage)
            self.assertEqual(list(stage.iterdir()),[])

    def test_mixed_certbot_generations_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            folder=pathlib.Path(d);stage=folder/'stage';stage.mkdir()
            fixture=folder/'fixture';fixture.write_bytes(b'disposable')
            original_open=os.open
            c={'source_dir':'/etc/letsencrypt/live/rpc.example.test','tls_name':'rpc.example.test'}
            def resolve(p, **kw):
                version='2' if p.name=='privkey.pem' else '1'
                return pathlib.Path('/etc/letsencrypt/archive/rpc.example.test')/(p.stem+version+'.pem')
            with patch.object(R,'protected',side_effect=lambda p,**kw:pathlib.Path(p)), patch.object(pathlib.Path,'is_symlink',return_value=True), patch.object(pathlib.Path,'resolve',resolve), patch.object(R.os,'open',side_effect=lambda p,flags:original_open(fixture,flags)):
                with self.assertRaisesRegex(ValueError,'MIXED_CERTBOT_GENERATIONS'): R.snapshot(c,stage)

    def test_no_approval_means_no_config_access(self):
        p=subprocess.run(['python3',str(ROOT/'scripts/renew-https.py'),'--config','/does-not-exist'],capture_output=True,text=True)
        self.assertNotEqual(p.returncode,0)
        self.assertNotIn('Traceback',p.stderr)

    def test_unsafe_paths_and_hook_scope(self):
        with tempfile.TemporaryDirectory() as d:
            target=pathlib.Path(d)/'target';target.write_text('public-fixture')
            link=pathlib.Path(d)/'link';link.symlink_to(target)
            with self.assertRaises(ValueError): R.protected(link)
        template=(ROOT/'deploy/ansible/roles/control_plane/templates/certbot-deploy-hook.sh.j2').read_text()
        hook=jinja2.Template(template).render(item={'name':'rpc','renewal':{'source_dir':'/etc/letsencrypt/live/rpc.example.test'}})
        subprocess.run(['sh','-n'],input=hook,text=True,check=True)
        self.assertIn('is-active --quiet greenwood-rpc-https.service',hook)
        self.assertIn('--reload-active',hook)
        self.assertNotIn('systemctl start',hook)
        self.assertIn('[ "${RENEWED_LINEAGE:-}" =',hook)

    def test_actual_ansible_chain_verification(self):
        tasks=yaml.safe_load((ROOT/'deploy/ansible/roles/control_plane/tasks/https.yml').read_text())
        task=next(t for t in tasks if t['name']=='Verify TLS chain expiry and intended identity')
        for mode in ('chain','missing','direct'):
            entry={'ca':str(self.root/'root.pem'), 'tls_name':'rpc.example.test',
                   'certificate':str(self.root/('direct.pem' if mode=='direct' else 'leaf.pem'))}
            if mode=='chain': entry['intermediates']=str(self.root/'inter.pem')
            with tempfile.TemporaryDirectory() as d:
                play=pathlib.Path(d)/'play.yml'
                play.write_text(yaml.safe_dump([{'hosts':'localhost','gather_facts':False,
                    'vars':{'greenwood_https_frontends':[entry]},'tasks':[task]}]))
                result=subprocess.run(['ansible-playbook','-i','localhost,','-c','local',str(play)],
                    capture_output=True,text=True,timeout=30,
                    env=dict(os.environ,ANSIBLE_LOCAL_TEMP=d+'/local',ANSIBLE_REMOTE_TEMP=d+'/remote'))
                self.assertEqual(result.returncode==0,mode!='missing',result.stdout+result.stderr)

    def test_installer_uses_untrusted_chain_and_server_purpose(self):
        tasks=yaml.safe_load((ROOT/'deploy/ansible/roles/control_plane/tasks/https.yml').read_text())
        task=next(t for t in tasks if t['name']=='Verify TLS chain expiry and intended identity')
        text=task['ansible.builtin.command']['argv']
        self.assertIn("'-untrusted', item.intermediates",text)
        self.assertIn("'-purpose', 'sslserver'",text)
        self.assertIn("'-no-CAstore'",text)
