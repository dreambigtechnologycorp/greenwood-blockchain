"""Only disposable fixture identities; never supply production vaults to tests."""
from pathlib import Path
import subprocess
import unittest

class OperatorKeyVaultTest(unittest.TestCase):
    def test_crypto_and_storage_boundaries(self):
        root = Path(__file__).resolve().parents[2]
        result = subprocess.run(['php', str(root / 'tests/Release/operator-key-vault.php')],
                                capture_output=True, text=True, timeout=90)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('PASS:', result.stdout)

    def test_interactive_create_hides_password_and_writes_only_encrypted_secrets(self):
        import errno, json, os, pty, select, signal, tempfile, time
        root = Path(__file__).resolve().parents[2]
        password = b'disposable interactive fixture password'
        with tempfile.TemporaryDirectory(prefix='greenwood-vault-cli-fixture-') as directory:
            work = Path(directory)
            plan = work / 'plan.json'
            plan.write_text(json.dumps({'version': 1, 'entries': [{'name': 'fixture', 'format': 'token'}]}))
            pid, fd = pty.fork()
            if pid == 0:
                os.execvp('php', ['php', str(root / 'scripts/operator-key-vault.php'), 'create', str(plan), str(work / 'vault')])
            output = b''; sent = 0; status = None
            try:
                deadline = time.monotonic() + 45
                while time.monotonic() < deadline:
                    ready, _, _ = select.select([fd], [], [], 0.2)
                    if ready:
                        try: data = os.read(fd, 65536)
                        except OSError as error:
                            if error.errno != errno.EIO: raise
                            data = b''
                        if not data:
                            _, status = os.waitpid(pid, 0)
                            break
                        output += data
                        if sent == 0 and b'characters): ' in output:
                            os.write(fd, password + b'\n'); sent = 1
                        if sent == 1 and b'Confirm passphrase: ' in output:
                            os.write(fd, password + b'\n'); sent = 2
                    done, value = os.waitpid(pid, os.WNOHANG)
                    if done: status = value; break
                if status is None:
                    done, value = os.waitpid(pid, os.WNOHANG)
                    if done: status = value
                    else:
                        os.kill(pid, signal.SIGKILL); os.waitpid(pid, 0)
                        self.fail('Fixture command did not exit promptly')
                self.assertEqual(os.waitstatus_to_exitcode(status), 0, output.decode(errors='replace'))
                self.assertNotIn(password, output)
                self.assertEqual({f.name for f in (work / 'vault').iterdir()}, {'keys.gwkv', 'public-identities.json'})
                self.assertEqual(json.loads((work / 'vault/public-identities.json').read_text())['entries']['fixture'], {'format': 'token'})
                self.assertTrue((work / 'vault/keys.gwkv').read_bytes().startswith(b'GWKV1\0'))
            finally:
                os.close(fd)
