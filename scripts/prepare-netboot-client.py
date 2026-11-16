#!/usr/bin/env python3
"""Prepare new netboot directories and reviewable fragments; never reload services."""
import argparse, hashlib, ipaddress, json, os, re, tarfile
from pathlib import Path

def protected(path):
    path=Path(path)
    if not path.is_absolute() or '..' in path.parts:raise ValueError('Canonical absolute path required')
    for p in [path,*path.parents]:
        if p.is_symlink():raise ValueError('Symlink ancestry refused')
        if p.exists() and (p.stat().st_uid!=0 or p.stat().st_mode & 0o022):raise ValueError('Root-owned protected ancestry required')
    return path

def validate(c):
    if c.get('version')!=1 or not re.fullmatch(r'node-[5-7]',c.get('name','')):raise ValueError('Explicit new Pi name required')
    if not re.fullmatch(r'(?:[0-9a-f]{2}:){5}[0-9a-f]{2}',c.get('mac','')):raise ValueError('Verified lowercase MAC required')
    if not re.fullmatch('[0-9a-f]{8}',c.get('boot_id','')):raise ValueError('Verified Pi boot serial directory required')
    for k in ['address','nfs_server']:ipaddress.IPv4Address(c[k])
    for k in ['rootfs','boot']:
        archive=protected(c[k+'_archive'])
        if not archive.is_file() or not re.fullmatch('[a-f0-9]{64}',c[k+'_sha256']):raise ValueError('Pinned archive required')
        with archive.open('rb') as f:
            if hashlib.file_digest(f,'sha256').hexdigest()!=c[k+'_sha256']:raise ValueError('Archive digest mismatch')
    root=protected(c['root_parent'])/c['name']/'rootfs';boot=protected(c['tftp_parent'])/c['boot_id']
    if not root.parent.parent.is_dir() or not boot.parent.is_dir():raise ValueError('Existing approved root/TFTP parents required')
    if root.parent.exists() or boot.exists():raise ValueError('Existing client state must never be overwritten')
    return root,boot

def archive_filter(member,dest):
    # data_filter rejects devices, traversal and unsafe link targets. Restore the
    # reviewed image's numeric ownership/modes, not Python's permissive defaults.
    checked=tarfile.data_filter(member,dest)
    if checked is None:return None
    name=os.path.normpath(checked.name).rstrip('/')
    if (name.startswith(('etc/greenwood/','var/lib/greenwood','var/lib/containerd/','root/.ssh/'))
        or '/.ssh/' in name or name.startswith('etc/ssh/ssh_host_')) and not member.isdir():
        raise ValueError('Template contains identity or runtime state')
    if name in ['etc/machine-id','var/lib/dbus/machine-id'] and (member.size or member.issym() or member.islnk()):raise ValueError('Template machine-id must be empty')
    if member.uid<0 or member.gid<0:raise ValueError('Numeric image ownership required')
    return checked.replace(uid=member.uid,gid=member.gid,uname=None,gname=None,mode=member.mode)

def prepare(c,output):
    root,boot=validate(c);output=protected(output)
    if output.exists():raise ValueError('New evidence directory required')
    # Inspect BOTH archives before creating final client directories.
    for kind,target in [('rootfs',root),('boot',boot)]:
        with tarfile.open(c[kind+'_archive']) as t:
            for m in t:archive_filter(m,str(target))
    output.mkdir(mode=0o700)
    root.mkdir(parents=True,mode=0o755);boot.mkdir(mode=0o755)
    # Failure leaves isolated partial directories; never auto-delete an NFS root.
    (output/'declaration.json').write_text(json.dumps(c,indent=2)+'\n')
    (output/'status.json').write_text(json.dumps({'state':'PREPARING','name':c['name']}))
    for kind,target in [('rootfs',root),('boot',boot)]:
        with tarfile.open(c[kind+'_archive']) as t:t.extractall(target,filter=archive_filter)
    protected(root);protected(boot)
    for directory in [root/'etc',root/'var/lib/dbus']:
        directory.mkdir(parents=True,exist_ok=True)
    protected(root/'etc');protected(root/'var/lib/dbus')
    for target in [root/'etc/machine-id',root/'var/lib/dbus/machine-id',root/'etc/hostname']:
        if target.is_symlink():raise ValueError('Template identity path may not be a symlink')
    (root/'etc/machine-id').write_text('');(root/'var/lib/dbus/machine-id').write_text('')
    (root/'etc/hostname').write_text(c['name']+'\n')
    # Approved boot archive must supply reviewed firmware/config/cmdline. Do not
    # guess kernel names, EEPROM settings, NFS flags or rewrite a boot commandline.
    if not (boot/'cmdline.txt').is_file() or (boot/'cmdline.txt').is_symlink():raise ValueError('Reviewed boot cmdline required')
    cmd=(boot/'cmdline.txt').read_text()
    if 'root=/dev/nfs' not in cmd.split() or f'nfsroot={c["nfs_server"]}:{root}' not in [x.split(',')[0] for x in cmd.split()]:raise ValueError('Boot archive does not pin this root export')
    (output/'exports').write_text(f'{root} {c["address"]}(rw,sync,no_subtree_check,no_root_squash)\n')
    (output/'dnsmasq.conf').write_text(f'dhcp-host={c["mac"]},{c["address"]},{c["name"]},infinite\n')
    (output/'status.json').write_text(json.dumps({'state':'PREPARED','name':c['name'],'rootfs':str(root),'boot':str(boot),'activated':False},indent=2)+'\n')
    return {'state':'PREPARED','activated':False}
if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('config',type=Path);p.add_argument('--output',type=Path,required=True);p.add_argument('--prepare',action='store_true');a=p.parse_args()
    if os.geteuid()!=0:p.error('Run on the approved boot server as root')
    c=json.loads(protected(a.config).read_text());validate(c)
    if a.prepare:print(json.dumps(prepare(c,a.output)))
    else:print(json.dumps({'state':'VALIDATED','activated':False}))
