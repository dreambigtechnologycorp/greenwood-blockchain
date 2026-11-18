#!/usr/bin/env python3
"""Collect read-only facts from explicitly pinned SSH hosts; never install/reset."""
import argparse
import concurrent.futures
import datetime
import hashlib
import ipaddress
import json
from pathlib import Path
import sys
sys.path.insert(0, str(Path(__file__).resolve().parent))
from cluster_topology import profile, validate_members
import re
import subprocess

# Fixed commands: inventory data cannot supply remote shell programs.
CHECKS = {
    'hostname': 'hostname',
    'machine_id': 'cat /etc/machine-id',
    'architecture': 'uname -m',
    'os': 'cat /etc/os-release',
    'root_mount': 'findmnt -J -o TARGET,SOURCE,FSTYPE --target /',
    'storage': 'df -B1 / /var/lib',
    'addresses': 'ip -json address show',
    'routes': 'ip -json route show',
    'controllers': 'cat /sys/fs/cgroup/cgroup.controllers',
    'containerd': 'containerd --version',
    'php_version': "php -r 'echo PHP_VERSION;'",
    'php_modules': 'php -m',
    'sudo': 'sudo -n true',
}


def validate_inventory(data):
    if not isinstance(data, dict) or ((data.get('version') == 1 and set(data) != {'version','hosts'}) or (data.get('version') == 2 and set(data) != {'version','hosts','topology_profile'}) or data.get('version') not in (1,2)):
        raise ValueError('Versioned explicit host inventory required')
    hosts = data['hosts']
    if not isinstance(hosts, list):
        raise ValueError('Explicit hosts required')
    validate_members(data, hosts)
    names, addresses, identities = set(), set(), set()
    for host in hosts:
        if not isinstance(host, dict) or set(host) != {'name', 'address', 'user', 'machine_id', 'architecture', 'host_key_sha256'}:
            raise ValueError('Exact host identity fields required')
        if not re.fullmatch(r'node-[1-9][0-9]*', host['name']) or not re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}', host['user']):
            raise ValueError('Invalid host name/user')
        ipaddress.IPv4Address(host['address'])
        if not re.fullmatch(r'[a-f0-9]{32}', host['machine_id']) or host['architecture'] not in ('aarch64', 'x86_64'):
            raise ValueError('Explicit machine ID and architecture required')
        if not re.fullmatch(r'SHA256:[A-Za-z0-9+/]{43}', host['host_key_sha256']):
            raise ValueError('Verified ED25519 fingerprint required')
        if host['name'] in names or host['address'] in addresses or host['machine_id'] in identities:
            raise ValueError('Duplicate host identity')
        names.add(host['name']); addresses.add(host['address']); identities.add(host['machine_id'])
    return hosts


def verify_host_keys(hosts, path):
    # Plain exact-address records only; no wildcard, CA or unverified TOFU acceptance.
    import base64
    records = {}
    for line in path.read_text().splitlines():
        if not line.strip() or line.startswith('#'):
            continue
        fields = line.split()
        if len(fields) < 3 or fields[1] != 'ssh-ed25519' or fields[0] in records:
            raise ValueError('Exact unique ED25519 host records required')
        blob = base64.b64decode(fields[2], validate=True)
        records[fields[0]] = 'SHA256:' + base64.b64encode(hashlib.sha256(blob).digest()).decode().rstrip('=')
    if set(records) != {h['address'] for h in hosts}:
        raise ValueError('Host key file must match exactly the confirmed addresses')
    for host in hosts:
        if records[host['address']] != host['host_key_sha256']:
            raise ValueError('Host fingerprint mismatch')


def inspect(host, key, known_hosts):
    base = ['ssh', '-i', str(key), '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' + str(known_hosts),
            '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'ConnectTimeout=5',
            '-o', 'ServerAliveInterval=5', '-o', 'ServerAliveCountMax=2',
            host['user'] + '@' + host['address']]
    results = {}
    for name, command in CHECKS.items():
        try:
            result = subprocess.run(base + [command], capture_output=True, text=True, timeout=20)
            results[name] = {'exit_code': result.returncode, 'stdout': result.stdout[:65536], 'stderr': result.stderr[:4096]}
        except subprocess.TimeoutExpired:
            results[name] = {'exit_code': 124, 'stdout': '', 'stderr': 'Timeout'}
        # Stop immediately on SSH failure or machine/architecture mismatch.
        if results[name]['exit_code'] == 255 or (name in ('machine_id', 'architecture') and
            (results[name]['exit_code'] or results[name]['stdout'].strip() != host[name])):
            break
    identity = all(name in results and results[name]['exit_code'] == 0 and
                   results[name]['stdout'].strip() == host[name] for name in ('machine_id', 'architecture'))
    return {'host': host['name'], 'identity_verified': identity, 'checks': results}


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--inventory', type=Path, required=True)
    p.add_argument('--known-hosts', type=Path, required=True)
    p.add_argument('--ssh-key', type=Path, required=True)
    p.add_argument('--output', type=Path, required=True)
    args = p.parse_args()
    hosts = validate_inventory(json.loads(args.inventory.read_text()))
    known = args.known_hosts.resolve(strict=True)
    verify_host_keys(hosts, known)
    key = args.ssh_key.resolve(strict=True)  # Never read private key bytes.
    # Reserve protected output before connecting; never overwrite previous evidence.
    import os
    fd = os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w') as output:
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            results = list(pool.map(lambda h: inspect(h, key, known), hosts))
        report = {'version': 1, 'observed_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                  'hosts': results, 'recovery_access_verified': False,
                  'destructive_installation_authorized': False, 'production_acceptance': False}
        json.dump(report, output, indent=2); output.write('\n')
    print('Identity checks:', sum(r['identity_verified'] for r in results), '/', len(hosts), '; recovery and production acceptance remain unverified')
    return 0 if all(r['identity_verified'] for r in results) else 1


if __name__ == '__main__':
    raise SystemExit(main())
