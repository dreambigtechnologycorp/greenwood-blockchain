#!/usr/bin/env python3
"""Render the selected shared topology; never assign identities or activate services."""
import ipaddress,json,re,sys
from cluster_topology import profile

def render(data):
    nodes=data['node_topology']['value']
    name=data.get('topology_profile',{}).get('value','four')
    spec=profile(name)
    if not isinstance(nodes,dict) or set(nodes)!=set(spec['nodes']):
        raise ValueError('Exact hosts for selected topology profile required')
    addresses=set()
    for host,node in nodes.items():
        ip=ipaddress.IPv4Address(node['address'])
        if str(ip) in addresses:raise ValueError('Duplicate address')
        addresses.add(str(ip))
        if not re.fullmatch('[a-z_][a-z0-9_-]*',node['ssh_user']):raise ValueError('Invalid SSH account')
        if node['architecture']!='aarch64':raise ValueError('ARM64 required')
        expected=spec['nodes'][host]
        roles=node.get('roles',[node['role']] if node.get('role') else [])
        if not isinstance(roles,list) or sorted(roles)!=sorted(expected['roles']):raise ValueError('Protocol role mismatch: '+host)
        for key in ['validator_label','provider_label']:
            if node.get(key) != expected[key]:raise ValueError('Identity label mismatch: '+host)
        for key in ['controller','gateway']:
            if node.get(key,False) is not expected[key]:raise ValueError('Operations role mismatch: '+host)
        if node.get('gateway_address'):ipaddress.IPv4Address(node['gateway_address'])
    lines=['# Generated explicit topology; secrets and activation approvals are supplied separately.','[all]']
    for host,node in nodes.items():
        fields=[host,'ansible_host='+node['address'],'ansible_user='+node['ssh_user'],'architecture=aarch64']
        for key in ['validator_label','provider_label']:
            if node.get(key):fields.append(key+'='+node[key])
        lines.append(' '.join(fields))
    lines+=['','[all:vars]','greenwood_topology_profile='+name,'ansible_python_interpreter=/usr/bin/python3','ansible_ssh_common_args=-o StrictHostKeyChecking=yes']
    for group,hosts in spec['groups'].items():
        lines+=['','['+group+']',*hosts]
    return '\n'.join(lines)+'\n'
if __name__=='__main__':
    try:print(render(json.load(sys.stdin)),end='')
    except (ValueError,KeyError,TypeError) as e:print('ERROR: '+str(e),file=sys.stderr);sys.exit(3)
