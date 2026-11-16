import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('oci', Path(__file__).resolve().parents[2] / 'scripts/verify-oci-release.py')
oci = importlib.util.module_from_spec(spec)
spec.loader.exec_module(oci)

class OciReleaseTest(unittest.TestCase):
    def make(self, path, corrupt=False, architecture='arm64', source='a'*40):
        entries = {'oci-layout': b'{"imageLayoutVersion":"1.0.0"}'}
        def blob(body):
            raw = json.dumps(body).encode()
            digest = hashlib.sha256(raw).hexdigest()
            entries['blobs/sha256/'+digest] = raw
            return {'digest': 'sha256:'+digest, 'size': len(raw)}
        config = blob({'os': 'linux', 'architecture': architecture, 'config': {'Labels': {'org.opencontainers.image.revision': source}}})
        layer = blob({'fixture': 'not executable'})
        manifest = blob({'schemaVersion': 2, 'config': config, 'layers': [layer]})
        attestations = [blob({'predicateType': kind, 'subject': [{'digest': {'sha256': manifest['digest'][7:]}}]}) for kind in ['https://spdx.dev/Document', 'https://slsa.dev/provenance/v0.2']]
        for item in attestations: item['mediaType'] = 'application/vnd.in-toto+json'
        attestation = blob({'schemaVersion': 2, 'config': blob({}), 'layers': attestations})
        entries['index.json'] = json.dumps({'schemaVersion': 2, 'manifests': [manifest, attestation]}).encode()
        if corrupt:
            entries['blobs/sha256/'+layer['digest'][7:]] = b'x'*layer['size']
        with tarfile.open(path, 'w') as tar:
            for name, raw in entries.items():
                entry = tarfile.TarInfo(name); entry.size = len(raw)
                tar.addfile(entry, io.BytesIO(raw))
        return manifest['digest']

    def test_descriptors_platform_and_source(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d)/'fixture.tar'
            digest = self.make(p)
            self.assertEqual(oci.inspect(p, 'a'*40, 'linux/arm64')['image_manifest_digest'], digest)
            with self.assertRaises(ValueError): oci.inspect(p, 'b'*40, 'linux/arm64')
            with self.assertRaises(ValueError): oci.inspect(p, 'a'*40, 'linux/amd64')
            self.make(p, corrupt=True)
            with self.assertRaises(ValueError): oci.inspect(p, 'a'*40, 'linux/arm64')

    def test_symlink_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d)/'fixture.tar'; self.make(p)
            with tarfile.open(p, 'a') as tar:
                item = tarfile.TarInfo('escape'); item.type = tarfile.SYMTYPE; item.linkname = '/etc/passwd'; tar.addfile(item)
            with self.assertRaises(ValueError): oci.inspect(p, 'a'*40, 'linux/arm64')
