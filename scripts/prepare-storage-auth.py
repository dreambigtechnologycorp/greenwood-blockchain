#!/usr/bin/env python3
"""Human-run preparation of separate iSCSI credentials; never prints secrets."""
import argparse
import json
import os
from pathlib import Path
import re
import secrets
import stat
import string

NODES = ('node-1', 'node-2', 'node-3')
ALPHABET = string.ascii_letters + string.digits + '_-'


class AuthError(Exception):
    pass


def require(value, code):
    if not value:
        raise AuthError(code)


def directory_guard(directory):
    require(directory.is_absolute() and str(directory) == os.path.normpath(str(directory)), 'ABSOLUTE_DIRECTORY_REQUIRED')
    for parent in directory.parents:
        require(not parent.is_symlink(), 'SYMLINK_ANCESTRY_REJECTED')
    if not directory.exists():
        require(directory.parent.is_dir(), 'CREATE_PROTECTED_PARENT_FIRST')
        directory.mkdir(mode=0o700)
    s = directory.lstat()
    require(stat.S_ISDIR(s.st_mode) and s.st_uid == os.geteuid() and
            stat.S_IMODE(s.st_mode) == 0o700, 'DIRECTORY_MUST_BE_OWNED_MODE_0700')


def read_existing(path, node):
    s = path.lstat()
    require(stat.S_ISREG(s.st_mode) and s.st_uid == os.geteuid() and
            stat.S_IMODE(s.st_mode) == 0o600 and s.st_nlink == 1 and s.st_size <= 2048,
            'CREDENTIAL_FILE_MUST_BE_OWNED_MODE_0600')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'r') as stream:
        value = json.load(stream)
    require(isinstance(value, dict) and set(value) == {'username', 'password'} and
            value['username'] == 'greenwood-prod-v1-' + node and
            isinstance(value['password'], str) and
            re.fullmatch(r'[A-Za-z0-9_-]{16,64}', value['password']), 'EXISTING_CREDENTIAL_REQUIRES_REVIEW')
    return value


def prepare(directory, *, generate=False, choose=secrets.choice):
    directory = Path(directory)
    require(generate or directory.exists(), 'CREDENTIAL_DIRECTORY_MISSING')
    directory_guard(directory)
    existing = {}
    for node in NODES:
        path = directory / (node + '.json')
        if os.path.lexists(path):
            existing[node] = read_existing(path, node)
    require(len({v['password'] for v in existing.values()}) == len(existing), 'CREDENTIALS_MUST_BE_DISTINCT')
    if not generate:
        require(set(existing) == set(NODES), 'CREDENTIAL_FILES_MISSING')
        return {'status': 'CHECKED', 'files': len(existing)}
    used = {value['password'] for value in existing.values()}
    for node in NODES:
        if node in existing:
            continue  # Never rotate or overwrite existing credentials implicitly.
        password = ''.join(choose(ALPHABET) for _ in range(16))
        require(password not in used, 'RANDOM_CREDENTIAL_COLLISION')
        value = {'username': 'greenwood-prod-v1-' + node, 'password': password}
        fd = os.open(directory / (node + '.json'), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'w') as stream:
            json.dump(value, stream, separators=(',', ':'))
            stream.write('\n')
            stream.flush()
            os.fsync(stream.fileno())
        used.add(password)
    fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)
    return {'status': 'PREPARED', 'files': len(NODES), 'existing_preserved': len(existing)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--directory', required=True)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--generate', action='store_true')
    parser.add_argument('--approve-storage-credential-generation', action='store_true')
    args = parser.parse_args()
    try:
        require(not args.generate or args.approve_storage_credential_generation,
                'STORAGE_CREDENTIAL_GENERATION_APPROVAL_REQUIRED')
        result = prepare(args.directory, generate=args.generate)
    except (AuthError, OSError, ValueError) as error:
        code = str(error) if isinstance(error, AuthError) else 'AUTH_PREPARATION_FAILED'
        parser.exit(1, 'STOP: ' + code + '; existing files retained; no credentials printed.\n')
    print(json.dumps(result, separators=(',', ':')))


if __name__ == '__main__':
    main()
