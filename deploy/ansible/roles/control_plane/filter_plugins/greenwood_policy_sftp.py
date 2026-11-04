"""Reject unsafe dedicated policy-transport inventory before provisioning."""
import base64
import ipaddress
import re
import struct

from ansible.errors import AnsibleFilterError


def validate_sftp(config, mode, environment):
    def require(condition):
        if not condition:
            raise AnsibleFilterError('Invalid dedicated Greenwood policy SFTP inventory')

    require(isinstance(config, dict))
    if not config:
        return True
    require(mode == 'renew' and isinstance(environment, dict))
    require(isinstance(config.get('user'), str) and re.fullmatch(r'gw-policy-[a-z0-9-]{1,22}', config['user']))
    require(isinstance(config.get('port'), int) and not isinstance(config['port'], bool)
            and 1024 <= config['port'] <= 65535)
    try:
        require(isinstance(config.get('address'), str))
        address = ipaddress.IPv4Address(config['address'])
        require(not (address.is_unspecified or address.is_multicast or address.is_reserved))
        key = config.get('client_public_key')
        require(isinstance(key, str) and re.fullmatch(r'ssh-ed25519 [A-Za-z0-9+/]+={0,2}', key))
        wire = base64.b64decode(key.split()[1], validate=True)
        prefix = struct.pack('>I', 11) + b'ssh-ed25519' + struct.pack('>I', 32)
        require(wire.startswith(prefix) and len(wire) == len(prefix) + 32)
    except (ValueError, TypeError) as exc:
        raise AnsibleFilterError('Invalid policy SFTP address or public key') from exc
    host_key = config.get('host_key')
    require(isinstance(host_key, str) and re.fullmatch(r'/etc/greenwood/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*', host_key))
    public = environment.get('GREENWOOD_POLICY_PUBLIC_DIRECTORY')
    publisher = environment.get('GREENWOOD_POLICY_PUBLISHER_DIRECTORY')
    require(isinstance(public, str) and re.fullmatch(r'/var/lib/greenwood-control-plane/[A-Za-z0-9_-]+', public))
    require(isinstance(publisher, str) and public != publisher and not publisher.startswith(public + '/'))
    return True


class FilterModule:
    def filters(self):
        return {'greenwood_policy_sftp_valid': validate_sftp}
