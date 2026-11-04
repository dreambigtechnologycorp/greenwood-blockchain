#!/usr/bin/env python3
"""Package committed source without reading local credentials or runtime state."""
import argparse
import gzip
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import subprocess
import tarfile


ROOT = Path(__file__).resolve().parents[1]
REQUIRED = {'LICENSE', 'NOTICE', 'TRADEMARKS.md', 'SECURITY.md', 'CONTRIBUTING.md', 'CHANGELOG.md',
            'THIRD_PARTY_NOTICES.md', 'LICENSES/Apache-2.0.txt',
            'LICENSES/containerd-NOTICE.txt', 'composer.json', 'composer.lock'}


def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])


def forbidden(name):
    path = PurePosixPath(name)
    parts = path.parts
    return (any(p in {'vendor', 'node_modules', 'storage', 'writable', '.git', '.terraform', 'local-state',
                     'acceptance', 'acceptance-4def', '__pycache__'} for p in parts)
            or any(p.startswith('._') or p == '.DS_Store' for p in parts)
            or path.name in {'id_rsa', 'id_ed25519', '.netrc', 'auth.json'}
            or path.name == '.env' or path.name.startswith('.env.') and path.name != '.env.example'
            or '.tfstate' in path.name.lower() or '.bak' in path.name.lower()
            or path.name.endswith(('.tfvars', '.tfvars.json', '.orig'))
            or name == 'deploy/ansible/inventory/controller.ini'
            or path.suffix.lower() in {'.key', '.pem', '.p12', '.pfx', '.sqlite',
                                       '.db', '.log', '.tfstate', '.bak', '.pyc'}
            or path.name.endswith(('.sqlite-wal', '.sqlite-shm', '.db-wal', '.db-shm'))
            or any(p in {'build', 'dist', 'tmp'} for p in parts[:1]))


def package(revision, destination):
    commit = git('rev-parse', '--verify', '--end-of-options', revision + '^{commit}').decode().strip()
    entries = git('ls-tree', '-rz', '--full-tree', commit).split(b'\0')
    files, submodules = [], {}
    for entry in filter(None, entries):
        metadata, raw_path = entry.split(b'\t', 1)
        mode, kind, oid = metadata.decode().split()
        path = raw_path.decode('utf-8')
        if forbidden(path):
            raise ValueError('Refusing sensitive or generated path: ' + path)
        if kind == 'commit':
            submodules[path] = oid
        else:
            files.append(path)
    missing = REQUIRED - set(files)
    if missing:
        raise ValueError('Missing release files: ' + ', '.join(sorted(missing)))
    prefix = 'greenwood-' + commit + '/'
    archive = git('archive', '--format=tar', '--prefix=' + prefix, commit)
    # Validate archive attributes did not silently omit required license files.
    with tarfile.open(fileobj=io.BytesIO(archive), mode='r:') as tar:
        names = {m.name.removeprefix(prefix) for m in tar.getmembers() if m.isfile()}
    if not REQUIRED <= names:
        raise ValueError('Archive omitted required release files')
    compressed = gzip.compress(archive, mtime=0)
    filename = 'greenwood-' + commit + '.tar.gz'
    manifest = {
        'format': 1, 'kind': 'experimental-source', 'source_commit': commit,
        'archive': filename, 'archive_sha256': hashlib.sha256(compressed).hexdigest(),
        'composer_lock_sha256': hashlib.sha256(git('show', commit + ':composer.lock')).hexdigest(),
        'submodules_not_bundled': submodules,
    }
    encoded = (json.dumps(manifest, indent=2, sort_keys=True) + '\n').encode()
    sums = (manifest['archive_sha256'] + '  ' + filename + '\n'
            + hashlib.sha256(encoded).hexdigest() + '  manifest.json\n')
    destination.mkdir(parents=True, exist_ok=False)
    (destination / filename).write_bytes(compressed)
    (destination / 'manifest.json').write_bytes(encoded)
    (destination / 'SHA256SUMS').write_text(sums)
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('revision', help='Reviewed tag or commit; no working-tree files are packaged')
    parser.add_argument('--output-dir', type=Path, required=True, help='New output directory')
    args = parser.parse_args()
    try:
        result = package(args.revision, args.output_dir)
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        parser.exit(1, 'Source packaging failed: ' + str(error) + '\n')
    print('SOURCE_COMMIT=' + result['source_commit'])
    print('ARCHIVE=' + str(args.output_dir / result['archive']))
    print('SHA256=' + result['archive_sha256'])


if __name__ == '__main__':
    main()
