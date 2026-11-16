"""Shared explicit deployment profiles. Physical expansion never expands validators."""
import json
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
PROFILES = json.loads((ROOT/'deploy/topology/profiles.json').read_text())['profiles']
def profile(name='four'):
    if name not in PROFILES:
        raise ValueError('Unknown approved topology profile')
    return PROFILES[name]
def host_names(config):
    return set(profile(config.get('topology_profile','four'))['nodes'])
def validate_members(config, hosts):
    expected=host_names(config)
    if not isinstance(hosts,list) or any(not isinstance(h,dict) or not isinstance(h.get('name'),str) for h in hosts):
        raise ValueError('Explicit named hosts required')
    names=[h['name'] for h in hosts]
    if len(names)!=len(expected) or set(names)!=expected:
        raise ValueError('Installation hosts must exactly match the selected topology profile')
    return expected
