#!/usr/bin/env python3
"""Build a pinned offline Greenwood ARM64 runtime using an explicitly selected Buildx builder."""
import argparse
import hashlib
import io
import json
from pathlib import Path
import subprocess
import tarfile
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
def git(*args):return subprocess.check_output(['git','-C',str(ROOT),*args])
def fetch(url,path,sha):
    if not url.startswith('https://'):raise ValueError('HTTPS upstream required')
    urllib.request.urlretrieve(url,path)
    if hashlib.sha256(path.read_bytes()).hexdigest()!=sha:raise ValueError('Upstream checksum mismatch: '+path.name)

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--output',type=Path,required=True);p.add_argument('--buildx',type=Path,required=True);p.add_argument('--builder',required=True);a=p.parse_args()
    source=git('rev-parse','HEAD').decode().strip()
    subprocess.run(['git','-C',str(ROOT),'diff','--exit-code','HEAD','--','system-services/php-runtime','scripts/build-php-runtime.py'],check=True,stdout=subprocess.DEVNULL)
    if git('ls-files','--others','--exclude-standard','--','system-services/php-runtime','scripts/build-php-runtime.py').strip():p.error('Commit runtime inputs before building')
    a.output.mkdir(parents=True,exist_ok=False);context=a.output/'context';inputs=context/'inputs';inputs.mkdir(parents=True)
    lock=json.loads(git('show',source+':system-services/php-runtime/inputs.lock.json'))
    for name in ['Dockerfile','verify.php']:(context/name).write_bytes(git('show',source+':system-services/php-runtime/'+name))
    checks=[]
    for item in lock['debian_packages']:
        fetch(item['url'],inputs/item['file'],item['sha256']);checks.append(item['sha256']+'  '+item['file'])
    archive=a.output/'wasmtime.tar.xz';fetch(lock['wasmtime']['url'],archive,lock['wasmtime']['archive_sha256'])
    with tarfile.open(archive) as tar:
        member=tar.getmember('wasmtime-v48.0.1-aarch64-linux-c-api/lib/libwasmtime.so')
        if not member.isfile():raise ValueError('Expected Wasmtime library')
        (inputs/'libwasmtime.so').write_bytes(tar.extractfile(member).read())
        (inputs/'WASMTIME-LICENSE').write_bytes(tar.extractfile('wasmtime-v48.0.1-aarch64-linux-c-api/LICENSE').read())
    checks.append(lock['wasmtime']['license_sha256']+'  WASMTIME-LICENSE')
    checks.append(lock['wasmtime']['library_sha256']+'  libwasmtime.so');(inputs/'SHA256SUMS').write_text('\n'.join(checks)+'\n')
    command=[str(a.buildx.resolve()),'build','--builder',a.builder,'--platform','linux/arm64','--build-arg','GREENWOOD_PHP_BASE='+lock['php_base'],'--build-arg','SOURCE_DATE_EPOCH='+git('log','-1','--format=%ct').decode().strip(),'--label','org.opencontainers.image.revision='+source,'--provenance=mode=max','--attest','type=sbom,generator='+lock['sbom_generator'],'--metadata-file',str(a.output/'runtime.metadata.json'),'--output','type=oci,rewrite-timestamp=true,dest='+str(a.output/'runtime.oci.tar'),str(context)]
    with (a.output/'build.log').open('w') as log:subprocess.run(command,cwd=ROOT,check=True,stdout=log,stderr=subprocess.STDOUT)
    provenance={'source_commit':source,'inputs':lock,'command':command,'platform':'linux/arm64','execution':'BuildKit QEMU when builder is not native ARM64','physical_qualification':False}
    (a.output/'build-inputs.json').write_text(json.dumps(provenance,indent=2)+'\n');(a.output/'SOURCE_COMMIT').write_text(source+'\n')
    print(json.dumps(provenance,indent=2))
if __name__=='__main__':main()
