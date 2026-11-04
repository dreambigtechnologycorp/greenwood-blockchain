"""Validate inventory data before it becomes Apache or systemd configuration."""
import ipaddress
import re
from pathlib import PurePosixPath
from urllib.parse import urlsplit

from ansible.errors import AnsibleFilterError


def validate_frontends(frontends):
    def require(condition, reason):
        if not condition:
            raise AnsibleFilterError('Invalid Greenwood HTTPS inventory: ' + reason)

    def port(value):
        return isinstance(value, int) and not isinstance(value, bool) and 1024 <= value <= 65535

    require(isinstance(frontends, list), 'expected a list')
    names, listeners = set(), set()
    for entry in frontends:
        require(isinstance(entry, dict), 'expected frontend map')
        name = entry.get('name')
        require(isinstance(name, str) and name in ('controller', 'registry', 'registry-auth', 'rpc'), 'unknown service')
        require(name not in names, 'duplicate service')
        names.add(name)
        address = entry.get('address')
        try:
            require(isinstance(address, str), 'address must be text')
            ip = ipaddress.IPv4Address(address)
        except ipaddress.AddressValueError as exc:
            raise AnsibleFilterError('Invalid Greenwood HTTPS inventory: IPv4 address required') from exc
        require(not (ip.is_unspecified or ip.is_multicast or ip.is_reserved), 'explicit unicast listener required')
        require(port(entry.get('port')), 'integer listener port required')
        listener = (address, entry['port'])
        require(listener not in listeners, 'duplicate listener')
        listeners.add(listener)
        identity = entry.get('tls_name')
        require(isinstance(identity, str) and len(identity) <= 253, 'TLS identity required')
        if re.fullmatch(r'[0-9.]+', identity):
            try:
                ipaddress.IPv4Address(identity)
            except ipaddress.AddressValueError as exc:
                raise AnsibleFilterError('Invalid Greenwood HTTPS inventory: invalid TLS IP') from exc
        else:
            require(all(re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?', label)
                        for label in identity.split('.')), 'invalid TLS hostname')
        backend = entry.get('backend')
        require(isinstance(backend, str) and re.fullmatch(r'http://127\.0\.0\.1:[0-9]+', backend), 'loopback HTTP backend required')
        try:
            backend_port = urlsplit(backend).port
        except ValueError as exc:
            raise AnsibleFilterError('Invalid Greenwood HTTPS inventory: backend port') from exc
        require(port(backend_port), 'backend port outside supported range')
        require(isinstance(entry.get('request_limit'), int) and not isinstance(entry['request_limit'], bool)
                and 0 <= entry['request_limit'] <= 2147483647,
                'integer request limit required')
        for field in ('certificate', 'private_key', 'ca'):
            path = entry.get(field)
            require(isinstance(path, str) and re.fullmatch(r'/etc/greenwood/[A-Za-z0-9_./-]+', path), 'protected TLS path required')
            require(str(PurePosixPath(path)) == path and '..' not in PurePosixPath(path).parts,
                    'canonical TLS path required')
        require(isinstance(entry.get('ca_sha256'), str) and re.fullmatch(r'[a-f0-9]{64}', entry['ca_sha256']), 'CA fingerprint required')
    return True


class FilterModule:
    def filters(self):
        return {'greenwood_https_valid': validate_frontends}
