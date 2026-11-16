#!/usr/bin/env python3
"""Read-only post-install RPC checks. Never install, fund, sign or restart services."""
import argparse
import hashlib
import json
import re
import ssl
import urllib.parse
import urllib.request
from pathlib import Path
import sys
sys.path.insert(0, str(Path(__file__).resolve().parent))
from cluster_topology import profile, validate_members


def fetch(origin, path):
    url = urllib.parse.urlsplit(origin)
    if url.scheme != 'https' or not url.hostname or url.username or url.password or url.query or url.fragment or url.path not in ('', '/'):
        raise ValueError('Verified HTTPS RPC origin required')
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            raise ValueError('Acceptance endpoints must not redirect')
    opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))
    with opener.open(origin.rstrip('/') + path, timeout=15) as reply:
        raw = reply.read(1048577)
    if len(raw) > 1048576:
        raise ValueError('RPC response exceeds bound')
    return json.loads(raw)


def check(config, read=fetch):
    if not re.fullmatch('[a-f0-9]{64}', config.get('genesis_hash') or '') or not config.get('chain_id'):
        raise ValueError('Exact approved genesis and chain required')
    hosts = config.get('hosts', [])
    validate_members(config, hosts)
    rpc_names=set(profile(config.get('topology_profile','four'))['groups']['rpc_hosts'])
    rpc_hosts=[h for h in hosts if h['name'] in rpc_names]
    if len({h.get('rpc_origin') for h in rpc_hosts})!=len(rpc_hosts) or any(not h.get('rpc_origin') for h in rpc_hosts):
        raise ValueError('Distinct pinned RPC endpoints required')
    for host in hosts:
        if host['name'] not in rpc_names and not re.fullmatch('[a-f0-9]{64}', host.get('service_acceptance_sha256','')):
            raise ValueError('Non-RPC host requires separate service acceptance evidence')
    keys = config.get('validator_public_keys', {})
    if set(keys) != {'A','B','C'} or len(set(keys.values())) != 3 or any(not re.fullmatch('[a-f0-9]{64}', k) for k in keys.values()):
        raise ValueError('Three pinned fresh validator identities required')
    heads = []
    for host in rpc_hosts:
        head = read(host['rpc_origin'], '/v1/chain/status')
        if head['chain_id'] != config['chain_id'] or head['genesis_hash'] != config['genesis_hash'] or head.get('syncing') is not False or type(head.get('finalized_height')) is not int or head['finalized_height'] < 1:
            raise ValueError('Wrong chain/genesis or unfinalized endpoint: '+host['name'])
        validators = read(host['rpc_origin'], '/v1/chain/validators')['validators']
        if {v['validator_id']:v['public_key'] for v in validators} != keys or any(v.get('active') is not True or v.get('voting_power') != 1 for v in validators):
            raise ValueError('Validator identity/power mismatch')
        heads.append(head)
    height = min(h['finalized_height'] for h in heads)
    blocks = [read(host['rpc_origin'], '/v1/blocks/'+str(height)) for host in rpc_hosts]
    pins = [(b['height'], b['block_hash'], b['state_root']) for b in blocks]
    if len(set(pins)) != 1 or pins[0][0] != height or any(b.get('finality_certificate_present') is not True for b in blocks):
        raise ValueError('Cross-node finalized state mismatch')
    if any(not re.fullmatch('[a-f0-9]{64}', value) for value in pins[0][1:]):
        raise ValueError('Malformed finalized hashes')
    return {'status':'PASS','common_finalized_height':height,'block_hash':pins[0][1],'state_root':pins[0][2],
            'verification_source':'TLS_AUTHENTICATED_NODE_ASSERTIONS','independent_certificate_replay':False,
            'full_physical_acceptance':False,'note':'Run workload, service recovery and certified replay procedures separately.'}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('config',type=Path);parser.add_argument('--output',type=Path,required=True)
    args=parser.parse_args()
    if args.output.exists():parser.error('New evidence output required')
    try:result=check(json.loads(args.config.read_text()))
    except Exception as error:result={'status':'FAIL','error':str(error),'full_physical_acceptance':False}
    result['configuration_sha256']=hashlib.sha256(args.config.read_bytes()).hexdigest()
    args.output.write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result,indent=2))
    return int(result['status']!='PASS')

if __name__=='__main__':raise SystemExit(main())
