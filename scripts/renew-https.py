#!/usr/bin/python3
"""Operator-only HTTPS import/renewal. Never issues certificates or starts services."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import tempfile
import uuid

SERVICES = ('rpc', 'controller', 'registry', 'registry-auth')
FILES = ('cert.pem', 'chain.pem', 'fullchain.pem', 'privkey.pem')


class RecoveryRequired(RuntimeError):
    pass


def require(ok, code):
    if not ok:
        raise ValueError(code)


def command(args, data=None):
    stream = {'stdin': subprocess.DEVNULL} if data is None else {'input': data}
    result = subprocess.run(args, **stream, capture_output=True,
                            timeout=30, env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C'})
    require(result.returncode == 0, 'CERTIFICATE_COMMAND_FAILED')
    return result.stdout


def protected(path, directory=False, private=False):
    path = Path(path)
    require(path.is_absolute() and str(path) == os.path.normpath(str(path)), 'CANONICAL_PATH_REQUIRED')
    for item in (path, *path.parents):
        s = item.lstat()
        require(not stat.S_ISLNK(s.st_mode) and s.st_uid == 0 and not s.st_mode & 0o022,
                'PROTECTED_ROOT_OWNED_PATH_REQUIRED')
    s = path.stat()
    require(stat.S_ISDIR(s.st_mode) if directory else stat.S_ISREG(s.st_mode), 'WRONG_FILE_TYPE')
    if private:
        require(not s.st_mode & 0o077, 'PRIVATE_KEY_PERMISSIONS_REQUIRED')
    return path


def validate_config(c):
    require(isinstance(c, dict) and set(c) == {'service', 'tls_name', 'ca', 'ca_sha256',
            'source_dir', 'minimum_validity_seconds'}, 'INVALID_RENEWAL_CONFIG')
    require(c['service'] in SERVICES, 'UNSUPPORTED_SERVICE')
    name = c['tls_name']
    require(isinstance(name, str) and len(name) <= 253 and
            all(re.fullmatch(r'[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?', x)
                for x in name.split('.')) and not re.fullmatch(r'[0-9.]+', name), 'DNS_NAME_REQUIRED')
    require(isinstance(c['ca'], str) and re.fullmatch(r'/etc/greenwood/[A-Za-z0-9_/-]+\.pem', c['ca'])
            and not c['ca'].startswith('/etc/greenwood/tls-managed/'), 'EXTERNAL_TRUST_FILE_REQUIRED')
    require(isinstance(c['ca_sha256'], str) and re.fullmatch(r'[a-f0-9]{64}', c['ca_sha256']), 'CA_PIN_REQUIRED')
    require(c['source_dir'] in ('/etc/letsencrypt/live/' + name,
            '/etc/greenwood/acme-incoming/' + c['service']), 'APPROVED_SOURCE_REQUIRED')
    require(type(c['minimum_validity_seconds']) is int and
            3600 <= c['minimum_validity_seconds'] <= 2592000, 'INVALID_VALIDITY_WINDOW')
    return c


def certificates(data):
    blocks = re.findall(rb'-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+/=\r\n]+-----END CERTIFICATE-----', data)
    require(blocks and not re.sub(rb'-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+/=\r\n]+-----END CERTIFICATE-----', b'', data).strip(),
            'CERTIFICATES_ONLY_REQUIRED')
    return [re.sub(rb'\s+', b'', b) for b in blocks]


def validate_bundle(folder, ca, pin, hostname, minimum):
    """Run only on a private staging copy, never on changing Certbot symlinks."""
    ca = protected(ca)
    require(len(certificates(ca.read_bytes())) == 1, 'SINGLE_PINNED_ROOT_REQUIRED')
    der = command(['/usr/bin/openssl', 'x509', '-in', str(ca), '-outform', 'DER'])
    require(hashlib.sha256(der).hexdigest() == pin, 'CA_PIN_MISMATCH')
    command(['/usr/bin/openssl', 'verify', '-no-CApath', '-no-CAstore', '-check_ss_sig',
             '-CAfile', str(ca), str(ca)])
    leaf = (folder / 'cert.pem').read_bytes()
    chain = (folder / 'chain.pem').read_bytes()
    full = (folder / 'fullchain.pem').read_bytes()
    require(len(certificates(leaf)) == 1 and certificates(full) == certificates(leaf) + certificates(chain),
            'FULLCHAIN_MISMATCH')
    command(['/usr/bin/openssl', 'verify', '-no-CApath', '-no-CAstore', '-CAfile', str(ca),
             '-untrusted', str(folder/'chain.pem'), '-purpose', 'sslserver', '-verify_hostname', hostname,
             str(folder/'cert.pem')])
    command(['/usr/bin/openssl', 'x509', '-in', str(folder/'cert.pem'), '-checkend', str(minimum), '-noout'])
    public = command(['/usr/bin/openssl', 'x509', '-in', str(folder/'cert.pem'), '-pubkey', '-noout'])
    expected = command(['/usr/bin/openssl', 'pkey', '-pubin', '-outform', 'DER'], public)
    actual = command(['/usr/bin/openssl', 'pkey', '-in', str(folder/'privkey.pem'), '-passin', 'pass:', '-pubout', '-outform', 'DER'])
    require(actual == expected, 'CERTIFICATE_KEY_MISMATCH')


def snapshot(c, stage):
    source = protected(c['source_dir'], directory=True)
    versions = set()
    for name in FILES:
        path = source/name
        if str(source).startswith('/etc/letsencrypt/live/'):
            require(path.is_symlink(), 'CERTBOT_ARCHIVE_LINK_REQUIRED')
            path = path.resolve(strict=True)
            require(path.parent == Path('/etc/letsencrypt/archive')/c['tls_name'], 'CERTBOT_ARCHIVE_ESCAPE')
            match = re.fullmatch(re.escape(name[:-4]) + r'([1-9][0-9]*)\.pem', path.name)
            require(match is not None, 'INVALID_CERTBOT_VERSION')
            versions.add(match[1])
        protected(path, private=name == 'privkey.pem')
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb') as f:
            data = f.read(1048577)
        require(len(data) <= 1048576, 'OVERSIZED_CERTIFICATE_INPUT')
        target = stage/name
        with target.open('xb') as out:
            os.chmod(target, 0o600)
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
    require(len(versions) <= 1, 'MIXED_CERTBOT_GENERATIONS')


def sync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def active_generation(parent):
    active = protected(parent/'active.conf')
    lines = active.read_text().splitlines()
    require(len(lines) == 2, 'INVALID_ACTIVE_GENERATION')
    match = re.fullmatch(r'SSLCertificateFile (.+)/fullchain.pem', lines[0])
    require(match is not None, 'INVALID_ACTIVE_GENERATION')
    generation = Path(match[1])
    require(generation.parent == parent and re.fullmatch(r'generation-[a-f0-9]{32}', generation.name),
            'INVALID_ACTIVE_GENERATION')
    require(lines[1] == 'SSLCertificateKeyFile ' + str(generation/'privkey.pem'), 'INVALID_ACTIVE_GENERATION')
    protected(generation, directory=True)
    for name in FILES:
        protected(generation/name, private=name == 'privkey.pem')
    return generation


def write_atomic(path, data):
    fd, name = tempfile.mkstemp(prefix='.pointer-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.replace(name, path)
        sync_directory(path.parent)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def publish(stage, parent, check, reload_service):
    """Atomic regular-file pointer publication works on local and NFS roots.

    Retain immutable generations for rollback, concurrent Apache reads and audits.
    The include contains only two fixed directives, never caller-provided text.
    """
    active = parent/'active.conf'
    old = None
    if active.exists() or active.is_symlink():
        active_generation(parent)
        old = active.read_bytes()
    generation = parent/('generation-' + uuid.uuid4().hex)
    os.rename(stage, generation)
    sync_directory(parent)
    data = ('SSLCertificateFile ' + str(generation/'fullchain.pem') + '\n'
            + 'SSLCertificateKeyFile ' + str(generation/'privkey.pem') + '\n').encode()
    try:
        write_atomic(active, data)
        check()
        reload_service()
    except BaseException:
        try:
            if old is None:
                active.unlink(missing_ok=True)
                sync_directory(parent)
            else:
                write_atomic(active, old)
        except BaseException as error:
            raise RecoveryRequired('PRESERVE_GENERATIONS_FOR_OPERATOR_RECOVERY') from error
        raise


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    parser.add_argument('--approve-private-key-access', action='store_true')
    parser.add_argument('--install', action='store_true')
    parser.add_argument('--check-installed', action='store_true')
    parser.add_argument('--reload-active', action='store_true')
    args = parser.parse_args()
    # Must precede all config/credential access.
    require(args.approve_private_key_access, 'HUMAN_OPERATOR_APPROVAL_REQUIRED')
    require(os.geteuid() == 0, 'ROOT_REQUIRED')
    require(not args.reload_active or args.install, 'INSTALL_REQUIRED_FOR_RELOAD')
    require(not args.check_installed or not args.install, 'CHECK_CANNOT_INSTALL')
    c = validate_config(json.loads(protected(args.config).read_text()))
    unit = 'greenwood-' + c['service'] + '-https.service'
    config = '/etc/greenwood/control-plane/' + c['service'] + '-https.conf'
    if args.reload_active:
        command(['/usr/bin/systemctl', 'is-active', '--quiet', unit])
        protected(config)
    parent = Path('/etc/greenwood/tls-managed')/c['service']
    if args.check_installed:
        folder = active_generation(parent)
        validate_bundle(folder, c['ca'], c['ca_sha256'], c['tls_name'], c['minimum_validity_seconds'])
        print(json.dumps({'service': c['service'], 'installed_certificate_valid': True, 'activated': False}))
        return
    # Create only bounded local staging paths; no service operation in validation mode.
    protected('/etc/greenwood', directory=True)
    for p in (parent.parent, parent):
        p.mkdir(mode=0o700, exist_ok=True)
        protected(p, directory=True)
    lockfd = os.open(parent/'renewal.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(lockfd, 'a') as lock:
        ls = os.fstat(lock.fileno())
        require(stat.S_ISREG(ls.st_mode) and ls.st_uid == 0 and not ls.st_mode & 0o077, 'PRIVATE_LOCK_REQUIRED')
        fcntl.flock(lock, fcntl.LOCK_EX)
        require(not list(parent.glob('.staging-*')), 'INTERRUPTED_RENEWAL_REQUIRES_OPERATOR_REVIEW')
        stage = Path(tempfile.mkdtemp(prefix='.staging-', dir=parent))
        preserve = False
        try:
            snapshot(c, stage)
            validate_bundle(stage, c['ca'], c['ca_sha256'], c['tls_name'], c['minimum_validity_seconds'])
            sync_directory(stage)
            if args.install:
                def check():
                    if Path(config).exists():
                        protected(config)
                        text = Path(config).read_text()
                        includes = re.findall(r'^\s*Include\s+(\S+)\s*$', text, re.MULTILINE)
                        require(includes.count(str(parent/'active.conf')) == 1 and
                                not re.search(r'^\s*SSLCertificate(?:Key)?File\s+', text, re.MULTILINE),
                                'APACHE_GENERATION_PATH_MISMATCH')
                        command(['/usr/sbin/apache2', '-t', '-f', config])
                def reload_service():
                    if args.reload_active:
                        command(['/usr/bin/systemctl', 'is-active', '--quiet', unit])
                        command(['/usr/bin/systemctl', 'reload', unit])
                publish(stage, parent, check, reload_service)
            print(json.dumps({'service': c['service'], 'validated': True, 'installed': args.install,
                              'reloaded_active_service': args.reload_active, 'activated': False}))
        except RecoveryRequired:
            preserve = True
            raise
        finally:
            if not preserve and stage.exists():
                shutil.rmtree(stage)


if __name__ == '__main__':
    try:
        main()
    except Exception:
        # Never print command output, key contents, passphrases or config secrets.
        print('HTTPS renewal rejected; no service activation performed.', file=__import__('sys').stderr)
        raise SystemExit(1)
