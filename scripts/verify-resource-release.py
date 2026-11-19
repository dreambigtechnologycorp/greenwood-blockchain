#!/usr/bin/env python3
"""Reproduce local resource-release evidence; never contacts deployment inventories."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import time

ROOT = Path(__file__).resolve().parents[1]
SHARED = ['resource-adapters', 'governance-recovery', 'zero-reserve-budgets', 'customer-funded-programs', 'meter-retention', 'resource-telemetry', 'production-allocation', 'production-preparation', 'local-resource-evidence', 'meter-ipc', 'resource-worker-ipc',
          'system-service-journal', 'network-metering', 'resources', 'metering',
          'meter-checkpoints', 'network-policy', 'head-watchdog', 'settlement-outbox',
          'infrastructure-cost', 'hosted-boundaries', 'deployment-identity']
PER_ALLOCATION = ['bootstrap-operator-settlement', 'run', 'settlement', 'hosted',
                  'resource-settlement', 'certified-chain', 'independent-resource-settlement']
ALLOCATIONS = ['15-25-30-25-5', '40-20-15-24-1', '10-25-30-34-1']


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output-dir', type=Path, required=True)
    parser.add_argument('--certified-history', action='store_true')
    parser.add_argument('--require-clean', action='store_true', help='Require committed source; ignores pre-existing submodule workspace dirt')
    args = parser.parse_args()
    if args.require_clean:
        dirty = subprocess.check_output(['git','status','--porcelain','--untracked-files=all','--ignore-submodules=dirty'],cwd=ROOT,text=True)
        if dirty.strip():
            parser.error('Release qualification requires clean tracked and untracked source inputs')
    args.output_dir.mkdir(parents=True, exist_ok=False)
    env = os.environ.copy()
    php = [env.get('GREENWOOD_PHP_BINARY', 'php'), '-d', 'ffi.enable=true']
    extension = env.get('GREENWOOD_SQLITE_EXTENSION')
    if extension:
        default = subprocess.run([php[0], '-r', "exit(extension_loaded('pdo_sqlite')?0:1);"], capture_output=True)
        if default.returncode:
            php += ['-d', 'extension=' + extension]
    runs = []
    report = {'status': 'RUNNING', 'runs': runs, 'production_touched': False,
              'physical_qualification': False, 'source_commit': subprocess.check_output(
                  ['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
              'working_tree_may_be_dirty': not args.require_clean}

    def persist():
        (args.output_dir / 'verification.json').write_text(json.dumps(report, indent=2) + '\n')

    def run(command, extra=None, timeout=180):
        started = time.monotonic()
        try:
            result = subprocess.run(command, cwd=ROOT, env=env | (extra or {}),
                                    capture_output=True, text=True, timeout=timeout)
            try:
                output = json.loads(result.stdout)
            except ValueError:
                output = result.stdout[-12000:]
            runs.append({'command': command, 'environment_overrides': extra or {},
                         'exit_code': result.returncode, 'seconds': round(time.monotonic()-started, 3),
                         'result': output, 'stderr': result.stderr[-12000:]})
        except subprocess.TimeoutExpired:
            runs.append({'command': command, 'environment_overrides': extra or {},
                         'exit_code': 124, 'result': 'TIMEOUT'})
        persist()
        print(command[-1], extra or {}, runs[-1]['exit_code'], flush=True)

    for name in SHARED:
        run(php + [f'tests/Economics/{name}.php'])
    run(php + ['tests/Economics/bootstrap-operator-settlement.php'], {'GWD_STORAGE_IO': '1', 'GWD_ALLOCATION_CANDIDATE': ALLOCATIONS[0]})
    for allocation in ALLOCATIONS:
        for name in PER_ALLOCATION:
            run(php + [f'tests/Economics/{name}.php'], {'GWD_ALLOCATION_CANDIDATE': allocation})
    for directory, names in [
        ('deployment-controller', ['distribution', 'registry_authority', 'registry_identity',
          'signed_policy', 'allocation_approval', 'policy_install', 'orchestration', 'http_gate',
          'edge_admission', 'release_content', 'write_enablement', 'distribution_mtls']), ('registry-auth', ['authority', 'database', 'system-publisher'])]:
        for name in names:
            run(php + [f'system-services/{directory}/tests/{name}.php'])
    run(['node', 'tests/Economics/signing.mjs'], timeout=120)
    run(['node', 'tests/Economics/resource-client.mjs'], timeout=120)
    if args.certified_history:
        run(php + ['tests/Economics/hosted-certified-history.php'],
            {'GWD_ALLOCATION_CANDIDATE': ALLOCATIONS[0], 'GWD_RESOURCE_V2': '1',
             'GWD_NETWORK_POLICY_V1': '1', 'GWD_ARCHIVE_V1': '1', 'GWD_BOOTSTRAP_TRUST': '1'}, 1200)
    files = sorted({p for scope in ['src', 'tests/Economics', 'system-services/metering',
                                   'system-services/runtime'] for p in (ROOT/scope).rglob('*.php')})
    lint = []
    for file in files:
        result = subprocess.run([php[0], '-l', str(file)], capture_output=True, text=True)
        lint.append({'file': str(file.relative_to(ROOT)), 'exit_code': result.returncode,
                     'error': result.stderr if result.returncode else None})
    report['lint'] = lint
    report['source_sha256'] = {str(p.relative_to(ROOT)): hashlib.sha256(p.read_bytes()).hexdigest() for p in files}
    diff = subprocess.run(['git', 'diff', '--check'], cwd=ROOT, capture_output=True, text=True)
    report['diff_check'] = {'exit_code': diff.returncode, 'output': diff.stdout + diff.stderr}
    report['status'] = 'PASS' if all(r['exit_code'] == 0 for r in runs + lint) and diff.returncode == 0 else 'FAIL'
    persist()
    print(report['status'], len(runs), 'suites', len(lint), 'syntax checks', flush=True)
    return int(report['status'] != 'PASS')


if __name__ == '__main__':
    raise SystemExit(main())
