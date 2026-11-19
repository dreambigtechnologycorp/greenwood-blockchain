#!/usr/bin/env python3
"""Assemble verified public release inputs without keys, publication or activation."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[1]

def sha(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()

def qualification(path, source):
    q = json.loads(path.read_text())
    if q.get('status') != 'PASS' or q.get('source_commit') != source or q.get('working_tree_may_be_dirty') is not False:
        raise ValueError('Passing clean-source qualification for this revision required')
    return q

def main():
    p = argparse.ArgumentParser(description=__doc__)
    for name in ['source', 'services', 'runtime', 'qualification', 'config', 'output']:
        p.add_argument('--' + name, type=Path, required=True)
    a = p.parse_args()
    if a.output.exists():
        p.error('New output directory required')
    source = json.loads((a.source / 'manifest.json').read_text())
    commit = source['source_commit']
    archive = source['archive']
    if Path(archive).name != archive or sha(a.source / archive) != source['archive_sha256']:
        raise ValueError('Source archive checksum/name mismatch')
    qualification(a.qualification, commit)
    spec = importlib.util.spec_from_file_location('oci', ROOT / 'scripts/verify-oci-release.py')
    oci = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(oci)
    images = {name: oci.inspect(a.services / (name + '.oci.tar'), commit, 'linux/arm64') for name in oci.SERVICES}
    expected = json.loads((a.runtime / 'runtime-digest.json').read_text())
    runtime = oci.inspect(a.runtime / 'runtime.oci.tar', expected['source_commit'], 'linux/arm64')
    if runtime != expected:
        raise ValueError('Runtime evidence mismatch')
    config = json.loads(a.config.read_text())
    if config.get('source_commit') not in [None, commit] or config.get('runtime_digest') not in [None, runtime['image_manifest_digest']]:
        raise ValueError('Configuration source/runtime mismatch')
    config.update(source_commit=commit, runtime_digest=runtime['image_manifest_digest'],
                  image_manifest_file='configuration/image-digests.json',
                  configuration_manifest_file='configuration/configuration-manifest.json')
    a.output.mkdir(parents=True)
    def copy(src, dest):
        target = a.output / dest
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(src, target)
    def write(dest, value):
        target = a.output / dest
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(value, indent=2) + '\n')
    copy(a.source / archive, 'source/' + archive)
    copy(a.source / 'manifest.json', 'source/manifest.json')
    copy(a.qualification, 'configuration/verification.json')
    write('configuration/release.json', config)
    write('configuration/image-digests.json', dict(version=1, source_commit=commit, images=images, physical_qualification=False))
    for name in oci.SERVICES:
        copy(a.services / (name + '.oci.tar'), 'services/' + name + '.oci.tar')
        for suffix in ['metadata.json', 'provenance.json', 'sbom.json']:
            src = a.services / (name + '.' + suffix)
            if src.exists(): copy(src, 'services/' + src.name)
    for name in ['runtime.oci.tar', 'runtime-digest.json', 'runtime-verification.json', 'build-inputs.json', 'build-toolchain.lock.json', 'SOURCE_COMMIT']:
        copy(a.runtime / name, 'runtime/' + name)
    for name in ['OPERATOR_KEY_CUSTODY.md', 'PHYSICAL_ACCEPTANCE.md']:
        copy(ROOT / 'deploy/production' / name, name)
    subprocess.run(['python3', str(ROOT / 'scripts/prepare-production-release.py'),
                    str(a.output / 'configuration/release.json'), '--source', commit,
                    '--images', str(a.output / 'configuration/image-digests.json'),
                    '--qualification', str(a.output / 'configuration/verification.json'),
                    '--output', str(a.output / 'production-release-manifest.json')], check=True, stdout=subprocess.DEVNULL)
    manifest = json.loads((a.output / 'production-release-manifest.json').read_text())
    # Keep evidence at its manifest basename under configuration; fail on lost/renamed evidence.
    for name, digest in manifest['configuration_manifest'].items():
        if sha(a.output / 'configuration' / name) != digest:
            raise ValueError('Packaged configuration/evidence mismatch: ' + name)
    write('configuration/configuration-manifest.json', manifest['configuration_manifest'])
    files = sorted(f for f in a.output.rglob('*') if f.is_file())
    (a.output / 'SHA256SUMS').write_text(''.join(sha(f) + '  ' + str(f.relative_to(a.output)) + '\n' for f in files))
    print(json.dumps({'source_commit': commit, 'output': str(a.output), 'files': len(files),
                      'activation_status': manifest['activation_status'], 'activation_blockers': manifest['activation_blockers']}, indent=2))

if __name__ == '__main__':
    main()
