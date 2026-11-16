#!/usr/bin/env python3
"""Verify local OCI archives by descriptor digest; never extract, upload or execute them."""
import argparse
import hashlib
import json
import re
import tarfile
from pathlib import Path

SERVICES = ('registry', 'registry-auth', 'metering', 'attestor', 'metering-coordinator')

def inspect(path, source, platform):
    os_name, architecture = platform.split('/')
    with tarfile.open(path, 'r:*') as archive:
        members = {}
        for member in archive:
            name = member.name.removeprefix('./')
            if member.isdir() and name in ('blobs', 'blobs/sha256'):
                continue
            if not member.isfile() or name in members or not (name in ('oci-layout', 'index.json') or re.fullmatch(r'blobs/sha256/[a-f0-9]{64}', name)):
                raise ValueError('Unexpected/duplicate OCI archive entry')
            members[name] = member
        def raw(name, json_limit=None):
            member = members.get(name)
            if member is None or (json_limit and member.size > json_limit):
                raise ValueError('Missing or oversized OCI document')
            stream = archive.extractfile(member)
            return stream.read()
        def document(name):
            return json.loads(raw(name, 4194304))
        if document('oci-layout') != {'imageLayoutVersion': '1.0.0'}:
            raise ValueError('Unsupported OCI layout')
        verified = set()
        def descriptor(d):
            digest = d.get('digest', '')
            if not re.fullmatch(r'sha256:[a-f0-9]{64}', digest) or type(d.get('size')) is not int or d['size'] < 0:
                raise ValueError('Invalid descriptor')
            name = 'blobs/sha256/' + digest[7:]
            member = members.get(name)
            if member is None or member.size != d['size']:
                raise ValueError('OCI descriptor size mismatch')
            if digest not in verified:
                h = hashlib.sha256()
                with archive.extractfile(member) as stream:
                    for block in iter(lambda: stream.read(1048576), b''):
                        h.update(block)
                if h.hexdigest() != digest[7:]:
                    raise ValueError('OCI blob digest mismatch')
                verified.add(digest)
            return name
        candidates = []
        attestations = []
        seen = set()
        def walk(d, depth=0):
            if depth > 8:
                raise ValueError('OCI index nesting exceeds bound')
            name = descriptor(d)
            if d['digest'] in seen:
                return
            seen.add(d['digest'])
            body = document(name)
            if 'manifests' in body:
                for child in body['manifests']:
                    walk(child, depth + 1)
            elif 'config' in body and 'layers' in body:
                config = document(descriptor(body['config']))
                for layer in body['layers']:
                    descriptor(layer)
                    if layer.get('mediaType') == 'application/vnd.in-toto+json':
                        statement = json.loads(raw(descriptor(layer), 33554432))
                        attestations.append((d.get('annotations', {}).get('vnd.docker.reference.digest'), statement))
                if config.get('os') == os_name and config.get('architecture') == architecture:
                    if config.get('config', {}).get('Labels', {}).get('org.opencontainers.image.revision') != source:
                        raise ValueError('OCI source revision label mismatch')
                    candidates.append(d['digest'])
            else:
                raise ValueError('Unsupported OCI manifest')
        for entry in document('index.json').get('manifests', []):
            walk(entry)
        if len(candidates) != 1:
            raise ValueError('Exactly one target-platform runtime image required')
        found = {statement.get('predicateType') for reference, statement in attestations
                 if reference == candidates[0] or any(
                     subject.get('digest', {}).get('sha256') == candidates[0][7:]
                     for subject in statement.get('subject', []))}
        if 'https://spdx.dev/Document' not in found or not found.intersection({
                'https://slsa.dev/provenance/v0.2', 'https://slsa.dev/provenance/v1'}):
            raise ValueError('Image-bound SPDX SBOM and SLSA provenance required')
        index_hash = 'sha256:' + hashlib.sha256(raw('index.json')).hexdigest()
    h = hashlib.sha256()
    with path.open('rb') as f:
        for block in iter(lambda: f.read(1048576), b''):
            h.update(block)
    return {'archive': path.name, 'archive_sha256': h.hexdigest(), 'image_manifest_digest': candidates[0],
            'layout_index_digest': index_hash, 'platform': platform, 'source_commit': source,
            'attestation_types': sorted(found), 'verified_blobs': len(verified), 'physical_qualification': False}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    parser.add_argument('--source', required=True)
    parser.add_argument('--platform', choices=['linux/arm64', 'linux/amd64'], required=True)
    args = parser.parse_args()
    if not re.fullmatch('[a-f0-9]{40}', args.source):
        parser.error('Full source commit required')
    images = {service: inspect(args.directory / (service + '.oci.tar'), args.source, args.platform) for service in SERVICES}
    result = {'version': 1, 'source_commit': args.source, 'images': images, 'physical_qualification': False}
    (args.directory / 'image-digests.json').write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result, indent=2))

if __name__ == '__main__':
    main()
