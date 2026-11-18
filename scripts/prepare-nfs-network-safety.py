#!/usr/bin/env python3
"""Validate a netplan retention overlay in disposable roots; never apply it."""
import argparse
import configparser
import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

import yaml


def retention_only(before, after):
    """Require the candidate to preserve every generated setting except retention."""
    def read(text):
        parser = configparser.ConfigParser(interpolation=None, strict=False)
        parser.optionxform = str
        parser.read_string(text)
        return {section: dict(parser[section]) for section in parser.sections()}
    old, new = read(before), read(after)
    value = new.get('Network', {}).pop('KeepConfiguration', None)
    old.get('Network', {}).pop('KeepConfiguration', None)
    if value not in ('true', 'yes') or old != new:
        raise ValueError('Candidate changes settings beyond KeepConfiguration or lacks retention')


def validate_identifier(value):
    if not re.fullmatch(r'[A-Za-z0-9_-]+', value):
        raise ValueError('Expected a simple netplan Ethernet ID')
    return value


def prepare(interface, expected_machine_id):
    interface = validate_identifier(interface)
    if Path('/etc/machine-id').read_text().strip() != expected_machine_id:
        raise ValueError('Machine identity does not match approved inventory')
    fs = subprocess.check_output(['findmnt', '-n', '-o', 'FSTYPE', '/'], text=True).strip()
    if fs not in ('nfs', 'nfs4'):
        raise ValueError('This procedure is only for NFS-root clients')
    current = Path(f'/run/systemd/network/10-netplan-{interface}.network').read_text()
    overlay = yaml.safe_dump({'network': {'version': 2, 'ethernets': {
        interface: {'critical': True}}}}, sort_keys=False)
    with tempfile.TemporaryDirectory(prefix='greenwood-netplan-review-') as tmp:
        root = Path(tmp)
        for relative in ('etc/netplan', 'run/netplan', 'lib/netplan'):
            dest = root / relative
            dest.mkdir(parents=True, mode=0o700)
            source = Path('/') / relative
            if source.exists():
                for file in source.glob('*.yaml'):
                    if file.is_symlink() or not file.is_file():
                        raise ValueError('Refusing non-regular netplan source')
                    shutil.copyfile(file, dest/file.name)
                    (dest/file.name).chmod(0o600)
        persistent = yaml.safe_load(subprocess.check_output(
            ['netplan', 'get', '--root-dir', str(root)], text=True))
        ethernet = persistent.get('network', {}).get('ethernets', {}).get(interface)
        if not ethernet or ethernet.get('dhcp4') is not True:
            raise ValueError('Expected an existing DHCP Ethernet definition')
        target = root/'etc/netplan/99-greenwood-nfs-retention.yaml'
        if target.exists():
            raise ValueError('Candidate overlay already exists; review it explicitly')
        target.write_text(overlay)
        target.chmod(0o600)
        outputs = {}
        for mode in ('merged_boot_and_persistent', 'persistent_only'):
            if mode == 'persistent_only':
                shutil.rmtree(root/'run/netplan')
            result = subprocess.run(['netplan', 'generate', '--root-dir', str(root)],
                                    capture_output=True, text=True, timeout=30)
            if result.returncode:
                raise ValueError('Isolated netplan generation failed: '+result.stderr)
            generated = root/f'run/systemd/network/10-netplan-{interface}.network'
            text = generated.read_text()
            if mode == 'merged_boot_and_persistent':
                retention_only(current, text)
            else:
                parser = configparser.ConfigParser(interpolation=None, strict=False)
                parser.read_string(text)
                if parser.get('Network', 'KeepConfiguration', fallback='') not in ('true','yes'):
                    raise ValueError('Persistent-only configuration lacks retention')
            outputs[mode] = {'retention': True}
        return {'status':'candidate-validated-not-applied', 'machine_id':expected_machine_id,
                'interface':interface, 'root_filesystem':fs, 'overlay':overlay,
                'validation':outputs, 'live_network_changed':False,
                'restart_qualified':False}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--interface', default='eth0')
    parser.add_argument('--expected-machine-id', required=True)
    args = parser.parse_args()
    print(json.dumps(prepare(args.interface, args.expected_machine_id), indent=2))
