"""Expose only the source-owned public storage plan validator."""
import importlib.util
from pathlib import Path
from ansible.errors import AnsibleFilterError

spec = importlib.util.spec_from_file_location('greenwood_storage_plan', Path(__file__).resolve().parents[3] / 'files/storage_plan.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def checked(plan, sha):
    # Round-trip removes Ansible scalar wrappers without accepting bool as int.
    try:
        import json
        return module.validate(json.loads(json.dumps(plan)), str(sha))
    except (ValueError, TypeError, KeyError):
        raise AnsibleFilterError('Storage plan is incomplete, mismatched or not approved; validate the public example and exact hash') from None


class FilterModule:
    def filters(self):
        return {'greenwood_state_plan': checked, 'greenwood_state_copy_manifest': module.copy_manifest,
                'greenwood_state_bindings': bindings}


def bindings(node):
    result = []
    for destination in module.sources(node):
        name = destination.removeprefix('/').replace('-', r'\x2d').replace('/', '-') + '.mount'
        result.append({'destination': destination, 'source': module.MOUNT + '/' + destination.rsplit('/', 1)[1], 'unit': name})
    return result
