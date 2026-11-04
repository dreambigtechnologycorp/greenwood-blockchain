#!/usr/bin/env python3
"""Check publishable working-tree files; this does not scan old Git history."""
import argparse
import hashlib
import importlib.util
from pathlib import Path
import re
import subprocess


SPEC = importlib.util.spec_from_file_location('source_package', Path(__file__).with_name('package-source.py'))
PACKAGE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PACKAGE)
PATTERNS = {
    'private PEM key': rb'-----BEGIN (?:RSA |EC |OPENSSH |DSA |ENCRYPTED )?PRIVATE KEY-----',
    'AWS access key': rb'\b(?:AKIA|ASIA)[A-Z0-9]{16}\b',
    'GitHub token': rb'(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,})',
    'service token': rb'(?:sk_live_[A-Za-z0-9]{16,}|xox[baprs]-[A-Za-z0-9-]{15,}|AIza[A-Za-z0-9_-]{35})',
    'hex signing secret': rb'''(?i)(?:private_key|secret_key|seed)\s*["']?\s*(?::|=)\s*["']([a-f0-9]{64,128})["']''',
}
# Exact frozen public protocol-vector material, not a blanket test-directory exemption.
PUBLIC_VECTOR_PATH = 'tests/Fixtures/ComputeFixture.php'
PUBLIC_VECTOR_SHA256 = '5be4ade80e8850df5f2bae9359879addca0ec121f932d5bfe73fa5a69f9f0b81'


def issues(name, data):
    found = []
    if PACKAGE.forbidden(name):
        found.append('private/generated filename')
    if len(data) == 96 and data[:32] == data[64:]:
        found.append('binary Ed25519 key-pair layout')
    for label, pattern in PATTERNS.items():
        for match in re.finditer(pattern, data):
            if (label == 'hex signing secret' and name == PUBLIC_VECTOR_PATH
                    and hashlib.sha256(bytes.fromhex(match[1].decode())).hexdigest() == PUBLIC_VECTOR_SHA256):
                continue
            found.append(label)
    return sorted(set(found))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    args = parser.parse_args()
    result = subprocess.run(['git', '-C', str(args.root), 'ls-files', '-z',
                             '--cached', '--others', '--exclude-standard'],
                            capture_output=True, check=True)
    failures, checked = [], 0
    for raw in sorted(set(result.stdout.split(b'\0')) - {b''}):
        name = raw.decode('utf-8')
        path = args.root / name
        if path.is_symlink():
            failures.append((name, ['symlink requires manual publication review']))
        elif path.is_file():
            checked += 1
            problems = issues(name, path.read_bytes())
            if problems:
                failures.append((name, problems))
    for name, problems in failures:
        print(name + ': ' + ', '.join(problems))  # Never print matched values.
    print(f'Checked {checked} source files; {len(failures)} flagged files.')
    raise SystemExit(bool(failures))


if __name__ == '__main__':
    main()
