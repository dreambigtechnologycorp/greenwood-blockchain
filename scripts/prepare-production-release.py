#!/usr/bin/env python3
"""Prepare a public release manifest and explicit gates; never installs or activates."""
import argparse
import hashlib
import json
from pathlib import Path
import sys
sys.path.insert(0, str(Path(__file__).resolve().parent))
from cluster_topology import profile, validate_members
import re

SERVICES=('registry','registry-auth','metering','attestor','metering-coordinator')
SUPPORTED={'cgroup.cpu.v1','cgroup.io.v1','allocation.interval.v1','network.workload.v1','nas.workload.v1','object.workload.v1','backup.workload.v1'}

def digest(value):return isinstance(value,str) and re.fullmatch('[a-f0-9]{64}',value) is not None and value!='0'*64

def assess(config, source, images=None):
    blockers=[]
    if config.get('version')!=1:raise ValueError('Versioned production configuration required')
    if config.get('source_commit')!=source:blockers.append('Configuration does not pin the release source revision')
    if not digest(config.get('genesis_hash')):blockers.append('Fresh genesis not finalized')
    if not isinstance(config.get('runtime_digest'),str) or not re.fullmatch('sha256:[a-f0-9]{64}',config['runtime_digest']):blockers.append('Qualified runtime digest missing')
    if not images or images.get('source_commit')!=source or set(images.get('images',{}))!=set(SERVICES):blockers.append('Verified complete OCI image manifest missing')
    elif any(i.get('platform')!='linux/arm64' or i.get('source_commit')!=source for i in images['images'].values()):blockers.append('OCI platform/source mismatch')
    registry=config.get('registry',{})
    for field in ['endpoint','tls_identity','issuer_public_key_sha256','system_publisher_authorization_sha256']:
        if not registry.get(field):blockers.append('Registry '+field+' remains unverified')
    if registry.get('bootstrap')!='offline_verified_artifacts':blockers.append('Registry/auth must bootstrap offline')
    governance=config.get('governance',{})
    for field in ['allocation_ratification_sha256','spending_limits_ratification_sha256','bootstrap_funding_ratification_sha256','fee_policy_ratification_sha256','signer_custody_review_sha256','recovery_policy_ratification_sha256','related_party_policy_ratification_sha256']:
        if not digest(governance.get(field)):blockers.append('Governance: '+field)
    hosts=config.get('hosts',[])
    try:validate_members(config, hosts)
    except ValueError as error:blockers.append(str(error))
    if any(not re.fullmatch('[a-f0-9]{32}',h.get('machine_id') or '') for h in hosts) or len({h.get('machine_id') for h in hosts})!=len(hosts):blockers.append('Freshly verified unique machine identities missing')
    definitions={e['resource_id']:e for e in json.loads((Path(__file__).resolve().parents[1]/'docs/economics/RESOURCE_CATALOG_V2.disabled.json').read_text())['entries']}
    seen=set()
    for profile in config.get('payable_profiles',[]):
        resource=profile.get('resource_id')
        if resource in seen or not resource:raise ValueError('Duplicate/unknown payable resource profile')
        seen.add(resource)
        definition=definitions.get(resource)
        if not definition or profile.get('meter_version')!=definition['meter_version'] or profile.get('billing_basis')!=definition['measurement_type']:
            blockers.append('Unsupported resource/profile pairing: '+resource)
        if profile.get('meter_version') not in SUPPORTED or profile.get('billing_basis') not in ['reserved','consumed'] or profile.get('qualification_status')!='QUALIFIED' or not digest(profile.get('evidence_sha256')) or not digest(profile.get('authorization_sha256')):blockers.append('Unqualified payable resource: '+resource)
        trust=profile.get('trust_profile')
        if trust=='INDEPENDENT_ATTESTED' and profile.get('independently_qualified') is not True:blockers.append('Independent source qualification missing: '+resource)
        elif trust=='BOOTSTRAP_OPERATOR_ATTESTED' and (profile.get('independently_qualified') is not False or profile.get('subsidy_percent')!=0):blockers.append('Bootstrap trust/subsidy misrepresentation: '+resource)
        elif trust not in ['INDEPENDENT_ATTESTED','BOOTSTRAP_OPERATOR_ATTESTED']:blockers.append('Unsupported production trust profile: '+resource)
    if config.get('recovery_access_verified') is not True:blockers.append('Physical recovery readiness unverified')
    if not digest(config.get('destructive_installation_authorization_sha256')):blockers.append('Explicit destructive-installation authorization absent')
    acceptance=config.get('physical_acceptance',{})
    for field in ['consensus','fresh_identities','registry_authorization','system_service_lifecycle','funded_compute','hosted_application','resource_measurements','settlement_conservation','refund_dispute_expiry','restart_renewal_recovery','governance_registration']:
        if not digest(acceptance.get(field)):blockers.append('Physical acceptance evidence: '+field)
    if config.get('production_activation_authorized') is not True:blockers.append('Permanent production activation not authorized')
    return blockers

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('config',type=Path);p.add_argument('--source',required=True);p.add_argument('--images',type=Path);p.add_argument('--qualification',type=Path);p.add_argument('--output',type=Path,required=True);args=p.parse_args()
    if not re.fullmatch('[a-f0-9]{40}',args.source):p.error('Full source commit required')
    if args.output.exists():p.error('New release manifest path required')
    config=json.loads(args.config.read_text());images=json.loads(args.images.read_text()) if args.images else None
    blockers=assess(config,args.source,images);qualified=False
    if args.qualification:
        q=json.loads(args.qualification.read_text());qualified=q.get('status')=='PASS' and q.get('source_commit')==args.source and q.get('working_tree_may_be_dirty') is False
    if not qualified:blockers.append('Passing clean-revision qualification evidence missing')
    files=[args.config]+([args.images] if args.images else [])+([args.qualification] if args.qualification else [])
    result={'version':1,'source_commit':args.source,'configuration_manifest':{f.name:hashlib.sha256(f.read_bytes()).hexdigest() for f in files},'images':images,'activation_status':'BLOCKED' if blockers else 'REQUIRES_FINAL_OPERATOR_REVIEW','activation_blockers':blockers,'source_qualification_passed':qualified,'physical_qualification_claimed':False,'authority_note':'Hashes bind supplied approval/evidence documents; this tool cannot confer governance authority.'}
    args.output.write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result,indent=2))

if __name__=='__main__':main()
