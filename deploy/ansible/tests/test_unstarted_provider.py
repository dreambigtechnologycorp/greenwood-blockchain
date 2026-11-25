"""Only synthetic stores/public identities; never reads production inventories."""
import fcntl
import errno
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import sqlite3
import stat
import struct
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

FILE = Path(__file__).resolve().parents[1] / 'files/validate_unstarted_provider.py'
SPEC = importlib.util.spec_from_file_location('unstarted_provider', FILE)
V = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(V)
CHAIN = 'fixture-chain'
PUBLIC = 'ab' * 32
DOMAIN = b'GREENWOOD_PROVIDER_V1'
PROVIDER = hashlib.blake2b(struct.pack('>H', len(DOMAIN)) + DOMAIN + bytes.fromhex(PUBLIC), digest_size=32).digest()


class UnstartedProviderTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='gw-unused-provider-')
        self.base = Path(self.tmp.name)
        self.root = self.base / 'provider'
        self.root.mkdir(mode=0o700)
        self.connections = []

    def tearDown(self):
        for db in self.connections:
            db.close()
        self.tmp.cleanup()

    def guard(self, path, *, directory=False):
        path = Path(path)
        self.assertTrue(path == self.base or self.base in path.parents)
        for current in (path, *path.parents):
            if current == self.base.parent:
                break
            meta = current.lstat()
            expected = stat.S_ISDIR if directory or current != path else stat.S_ISREG
            V.require(expected(meta.st_mode) and meta.st_uid == os.geteuid() and not meta.st_mode & 0o022,
                      'UNPROTECTED_PATH')
            if current == path and not directory:
                V.require(meta.st_nlink == 1, 'LINKED_STATE_FILE')
        return path

    def database(self, relative):
        path = self.root / relative
        path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        db = sqlite3.connect(path)
        for name, columns in V.SCHEMAS[relative].items():
            if name == 'metadata':
                db.execute('CREATE TABLE metadata (key TEXT PRIMARY KEY, value BLOB NOT NULL)')
            else:
                db.execute('CREATE TABLE "' + name + '" (' + ','.join('"' + col + '" BLOB' for col in sorted(columns)) + ')')
        db.commit()
        self.connections.append(db)
        path.chmod(0o600)
        return db

    def provider(self):
        db = self.database('provider.sqlite')
        db.executemany('INSERT INTO metadata VALUES (?,?)',
                       [('chain_id', CHAIN), ('provider_id', PROVIDER), ('drain', '0')])
        db.commit()
        (self.root / 'supervisor.lock').write_bytes(b'')
        (self.root / 'supervisor.lock').chmod(0o600)
        return db

    def policy(self):
        policy = {'providerAddress': '192.168.9.6', 'edgeAddress': '192.168.9.5',
                  'subnet': '10.100.0.0/24', 'firstPort': 10000, 'lastPort': 11000,
                  'forwardTable': 'greenwood_app_ingress', 'forwardChain': 'forward', 'protectedServices': []}
        path = self.base / 'policy.json'
        path.write_text(json.dumps(policy)); path.chmod(0o600)
        bindings = self.database('application-network/bindings.sqlite')
        for name, data in [('binding_policy', [policy[k] for k in ('subnet','firstPort','lastPort')]),
                           ('platform_network_policy', policy)]:
            bindings.execute(f'INSERT INTO "{name}" VALUES (?,?)', (hashlib.sha256(V.php_json(data)).hexdigest(), 1))
        # Fixture columns are alphabetically ordered: fingerprint, id.
        bindings.commit()
        self.database('application-network/network.sqlite')
        return path

    def validate(self, **kw):
        return V.validate(self.root, CHAIN, PUBLIC, guard=self.guard, scratch_root=self.base, **kw)

    def snapshot(self):
        return {str(p.relative_to(self.base)): (p.read_bytes(), p.stat().st_mtime_ns, p.stat().st_mode)
                for p in self.base.rglob('*') if p.is_file()}

    def test_absent_and_initialized_unused_stores_preserved(self):
        V.validate(self.base / 'absent', CHAIN, PUBLIC, guard=self.guard)
        self.validate()
        self.provider()
        self.database('applications/applications.sqlite')
        policy = self.policy()
        for name in ('archive', 'workers', 'output'):
            (self.root / name).mkdir(mode=0o700)
        before = self.snapshot()
        self.validate(network_policy=policy)
        self.assertEqual(before, self.snapshot())
    def test_php_public_identity_and_policy_vectors(self):
        # Verified against ProviderId::fromPublicKey and the two PHP registry
        # fingerprint constructions, without generating any key material.
        self.assertEqual(PROVIDER.hex(), '4b82ca7daf80288dea37d61f6aeb1ee06ef05b3a792364a4d8ce97dd6172b404')
        policy = json.loads(self.policy().read_text())
        self.assertEqual(hashlib.sha256(V.php_json(policy)).hexdigest(),
                         '0423775e323c8e7d00fc37ee6989d08781b4235ef92cde20f03baf7c1471537d')
        self.assertEqual(hashlib.sha256(V.php_json([policy[k] for k in ('subnet', 'firstPort', 'lastPort')])).hexdigest(),
                         '5c9fa24aec4fdb24c487aef13aeed4135e625905a647f35a11c51f007a5049f0')

    @unittest.skipIf(os.geteuid() == 0, 'PHP registry would create a host runtime identity lock as root')
    @unittest.skipUnless(shutil.which('php'), 'PHP runtime is required for native schema fixture')
    def test_actual_php_unused_startup_schemas_and_bindings(self):
        # Constructors initialize only fixture storage. In particular no key is
        # generated and createNetwork does not execute its host-effect closures.
        policy = self.base / 'php-policy.json'
        code = r'''
require $argv[1] . '/scripts/economics-bootstrap.php';
umask(0077);
$identity = Greenwood\Compute\Protocol\ProviderId::fromPublicKey(hex2bin($argv[4]));
$jobs = new Greenwood\Provider\ProviderJobRegistry($argv[2], $argv[3], $identity->toBytes());
$jobs->setDrain(false);
$apps = new Greenwood\Provider\ProviderApplicationRegistry($argv[2] . '/applications');
$policy = new Greenwood\Provider\ProviderApplicationNetworkPolicy(
    '192.168.9.6', '192.168.9.5', '10.100.0.0/24', 10000, 11000,
    'greenwood_app_ingress', 'forward', []);
$network = $policy->createNetwork($argv[2] . '/application-network');
file_put_contents($argv[5], json_encode(get_object_vars($policy), JSON_THROW_ON_ERROR));
echo bin2hex($identity->toBytes());
'''
        result = subprocess.run(['php', '-r', code, str(FILE.parents[3]), str(self.root), CHAIN, PUBLIC, str(policy)],
                                capture_output=True, text=True, timeout=15)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, PROVIDER.hex())
        before = self.snapshot()
        self.validate(network_policy=policy)
        self.assertEqual(before, self.snapshot())
        with sqlite3.connect(self.root / 'provider.sqlite') as db:
            db.execute("INSERT INTO metadata VALUES ('finalized_height', '1')")
            db.commit()
            with self.assertRaisesRegex(V.StateError, 'FINALIZED_HISTORY'):
                self.validate(network_policy=policy)
            db.execute("DELETE FROM metadata WHERE key='finalized_height'")
            db.execute('''INSERT INTO provider_jobs
                (request_id,generation,provider_id,state,received_at,updated_at)
                VALUES (?,1,?,'DISCOVERED',1,1)''', (b'r' * 32, PROVIDER))
            db.commit()
            with self.assertRaisesRegex(V.StateError, 'WORKLOAD_HISTORY'):
                self.validate(network_policy=policy)

    def test_wrong_identity_and_finalized_history_rejected(self):
        db = self.provider()
        for field, bad in [('chain_id', 'other-chain'), ('provider_id', b'z' * 32), ('finalized_height', '1')]:
            with self.subTest(field=field):
                previous = db.execute('SELECT value FROM metadata WHERE key=?', (field,)).fetchone()
                db.execute('INSERT OR REPLACE INTO metadata VALUES (?,?)', (field, bad)); db.commit()
                with self.assertRaises(V.StateError):
                    self.validate()
                db.execute('DELETE FROM metadata WHERE key=?', (field,))
                if previous: db.execute('INSERT INTO metadata VALUES (?,?)', (field, previous[0]))
                db.commit()

    def test_jobs_in_wal_are_not_ignored_and_originals_are_unchanged(self):
        db = self.provider()
        db.execute('PRAGMA journal_mode=WAL')
        db.execute('PRAGMA wal_autocheckpoint=0')
        db.execute('INSERT INTO provider_jobs (position) VALUES (1)'); db.commit()
        self.assertTrue(Path(str(self.root/'provider.sqlite') + '-wal').exists())
        before = self.snapshot()
        with self.assertRaisesRegex(V.StateError, 'WORKLOAD_HISTORY'):
            self.validate()
        self.assertEqual(before, self.snapshot())

    def test_nfs_whole_file_lock_semantics_preserve_read_only_inspection(self):
        self.provider()
        before = self.snapshot()
        # Linux NFS implements flock with whole-file fcntl locks. Exercise
        # the kernel's descriptor-access checks without mounting live NFS.
        with (self.root / 'supervisor.lock').open('rb') as lock:
            with self.assertRaises(OSError) as previous_failure:
                fcntl.lockf(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertEqual(previous_failure.exception.errno, errno.EBADF)
        modes = []
        def nfs_lock(fd, operation):
            modes.append(fcntl.fcntl(fd, fcntl.F_GETFL) & os.O_ACCMODE)
            self.assertEqual(operation, fcntl.LOCK_SH | fcntl.LOCK_NB)
            fcntl.lockf(fd, operation)
        with patch.object(V.fcntl, 'flock', side_effect=nfs_lock):
            self.validate()
        self.assertEqual(modes, [os.O_RDONLY])
        self.assertEqual(before, self.snapshot())

    def test_nfs_running_writer_blocks_shared_inspection(self):
        self.provider()
        # fcntl locks are process scoped: hold the writer lock in a separate
        # process, as the provider does, then emulate NFS reader locking here.
        code = '''
import fcntl,sys
with open(sys.argv[1], 'r+b') as lock:
    fcntl.lockf(lock, fcntl.LOCK_EX)
    print('LOCKED', flush=True)
    sys.stdin.read()
'''
        writer = subprocess.Popen([sys.executable, '-c', code,
                                   str(self.root / 'supervisor.lock')],
                                  stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
        try:
            self.assertEqual(writer.stdout.readline().strip(), 'LOCKED')
            with patch.object(V.fcntl, 'flock', side_effect=fcntl.lockf):
                with self.assertRaisesRegex(V.StateError, 'SUPERVISOR_STILL_RUNNING'):
                    self.validate()
        finally:
            writer.communicate(timeout=5)

    def test_scratch_requires_protected_local_tmpfs(self):
        with patch.object(V, 'protected', return_value=Path('/run')) as guard:
            for filesystem, expected in [('tmpfs', True), ('nfs', False), ('nfs4', False), ('', False)]:
                with self.subTest(filesystem=filesystem):
                    with patch.object(V.subprocess, 'run', return_value=subprocess.CompletedProcess(
                            [], 0, filesystem + '\n', '')) as probe:
                        if expected:
                            self.assertEqual(V.runtime_scratch_root(), Path('/run'))
                        else:
                            with self.assertRaisesRegex(V.StateError, 'LOCAL_TMPFS_SCRATCH_REQUIRED'):
                                V.runtime_scratch_root()
                        self.assertEqual(probe.call_args.args[0][-1], '/run')
            guard.assert_called_with(Path('/run'), directory=True)
        self.provider()
        with patch.object(V, 'runtime_scratch_root', return_value=self.base) as scratch:
            V.validate(self.root, CHAIN, PUBLIC, guard=self.guard)
            scratch.assert_called_once_with()

    def test_diagnostics_expose_only_stage_and_bounded_error_codes(self):
        errors = [
            (OSError(errno.EBADF, 'private-value must not escape'), 'OS_EBADF'),
            (sqlite3.OperationalError('private-value must not escape'), 'SQLITE_ERROR'),
            (json.JSONDecodeError('private-value', 'private-document', 0), 'INVALID_JSON'),
        ]
        for error, expected in errors:
            with self.subTest(expected=expected):
                with self.assertRaises(V.StateError) as failure:
                    with V.inspection_step('FIXTURE_STAGE'):
                        raise error
                self.assertEqual(str(failure.exception), 'FIXTURE_STAGE:' + expected)
                self.assertNotIn('private', str(failure.exception))

    def test_workload_and_network_rows_rejected(self):
        self.provider()
        apps = self.database('applications/applications.sqlite')
        apps.execute('INSERT INTO provider_applications (application_id) VALUES (1)'); apps.commit()
        with self.assertRaisesRegex(V.StateError, 'WORKLOAD_HISTORY'): self.validate()
        apps.execute('DELETE FROM provider_applications'); apps.commit()
        policy = self.policy()
        net = self.connections[-1]
        net.execute('INSERT INTO network_events (id) VALUES (1)'); net.commit()
        with self.assertRaisesRegex(V.StateError, 'WORKLOAD_HISTORY'):
            self.validate(network_policy=policy)

    def test_network_policy_mismatch_rejected(self):
        policy = self.policy()
        data = json.loads(policy.read_text()); data['edgeAddress'] = '192.168.9.7'
        policy.write_text(json.dumps(data))
        with self.assertRaisesRegex(V.StateError, 'POLICY_MISMATCH'):
            self.validate(network_policy=policy)

    def test_running_supervisor_unknown_metadata_schema_and_work_files_rejected(self):
        db = self.provider()
        with (self.root / 'supervisor.lock').open('rb') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaisesRegex(V.StateError, 'STILL_RUNNING'): self.validate()
        db.execute('INSERT INTO metadata VALUES (?,?)', ('unknown', 'x')); db.commit()
        with self.assertRaisesRegex(V.StateError, 'METADATA'): self.validate()
        db.execute('DELETE FROM metadata WHERE key="unknown"'); db.commit()
        db.execute('CREATE TABLE unknown (value TEXT)'); db.commit()
        with self.assertRaisesRegex(V.StateError, 'SCHEMA'): self.validate()
        db.execute('DROP TABLE unknown'); db.commit()
        (self.root/'workers').mkdir(mode=0o700)
        (self.root/'workers/job').write_text('fixture')
        with self.assertRaisesRegex(V.StateError, 'WORKLOAD_FILES'): self.validate()

    def test_symlink_and_orphan_wal_rejected(self):
        (self.root/'provider.sqlite').symlink_to(self.base/'absent')
        with self.assertRaises(V.StateError): self.validate()
        (self.root/'provider.sqlite').unlink()
        (self.root/'provider.sqlite-wal').write_text('fixture')
        with self.assertRaisesRegex(V.StateError, 'ORPHAN'): self.validate()


if __name__ == '__main__':
    unittest.main()
