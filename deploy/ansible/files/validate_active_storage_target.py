#!/usr/bin/env python3
"""Read-only audit of the exact running state targets before client continuation."""
import argparse
import ipaddress
import json
import os
from pathlib import Path
import re
import stat

import prepare_state_storage as storage
import validate_prepared_storage_target as prepared
from storage_plan import BACKING, ISCSI, LUN_BYTES, PlanError, require, validate


CONFIGFS_TARGET = Path('/sys/kernel/config/target')


def entries(path, *, links=False):
    require(stat.S_ISDIR(path.lstat().st_mode), 'ACTIVE_TARGET_DIRECTORY_REQUIRED')
    directories, symlinks = {}, []
    for child in path.iterdir():
        mode = child.lstat().st_mode
        if stat.S_ISDIR(mode):
            directories[child.name] = child
        elif stat.S_ISLNK(mode):
            require(links, 'ACTIVE_TARGET_UNEXPECTED_LINK')
            symlinks.append(child)
        else:
            require(stat.S_ISREG(mode), 'ACTIVE_TARGET_UNEXPECTED_METADATA_TYPE')
    return directories, symlinks


def exact_directories(path, expected, *, optional=()):
    directories, _ = entries(path)
    require(set(directories) - set(optional) == set(expected), 'ACTIVE_TARGET_LAYOUT_MISMATCH')
    return directories


def public_text(path):
    # Only explicit public scalar attributes are read. Never traverse auth or
    # discovery_auth, even if a future caller accidentally supplies such a path.
    require(not {'auth', 'discovery_auth'} & set(path.parts), 'AUTH_CONTENT_READ_FORBIDDEN')
    require(stat.S_ISREG(path.lstat().st_mode), 'ACTIVE_TARGET_ATTRIBUTE_REQUIRED')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'r') as stream:
        text = stream.read(8193)
    require(len(text) <= 8192, 'ACTIVE_TARGET_ATTRIBUTE_TOO_LARGE')
    return text.strip()


def info_field(text, name):
    values = re.findall(r'(?:^|\s)' + re.escape(name) + r':\s*(\S+)', text)
    require(len(values) == 1, 'ACTIVE_BACKSTORE_INFO_MISMATCH')
    return values[0]


def exact_link(path, destination):
    directories, links = entries(path, links=True)
    require(set(directories) <= {'statistics'} and len(links) == 1, 'ACTIVE_LUN_LINK_MISMATCH')
    target = Path(os.readlink(links[0]))
    if not target.is_absolute():
        target = links[0].parent / target
    require(os.path.normpath(str(target)) == str(destination), 'ACTIVE_LUN_LINK_MISMATCH')


def check_listener(address):
    lines = storage.run(['ss', '-H', '-ltn', 'sport', '=', ':3260']).splitlines()
    require(lines, 'ACTIVE_TARGET_LISTENER_MISSING')
    for line in lines:
        fields = line.split()
        # ss versions may include a Netid column before the socket state.
        offset = 1 if fields and fields[0] in ('tcp', 'tcp6') else 0
        require(len(fields) == offset + 5 and fields[offset] == 'LISTEN', 'ACTIVE_TARGET_LISTENER_MISMATCH')
        endpoint = fields[offset + 3]
        host, separator, port = endpoint.rpartition(':')
        require(separator and port == '3260', 'ACTIVE_TARGET_LISTENER_MISMATCH')
        try:
            parsed = ipaddress.ip_address(host.strip('[]'))
        except ValueError:
            raise PlanError('ACTIVE_TARGET_LISTENER_MISMATCH') from None
        if isinstance(parsed, ipaddress.IPv6Address) and parsed.ipv4_mapped:
            parsed = parsed.ipv4_mapped
        require(str(parsed) == address, 'ACTIVE_TARGET_LISTENER_MISMATCH')


def check_configfs(plan):
    root, _ = entries(CONFIGFS_TARGET)
    require({'core', 'iscsi'} <= set(root), 'ACTIVE_TARGET_LAYOUT_MISMATCH')
    for name, fabric in root.items():
        if name not in ('core', 'iscsi'):
            exact_directories(fabric, (), optional=('discovery_auth',))

    expected_backstores = {'greenwood-' + node: node for node in ISCSI}
    backstores = {}
    core, _ = entries(root['core'])
    for name, hba in core.items():
        if name == 'alua':
            continue
        require(re.fullmatch(r'fileio_[0-9]+', name), 'ACTIVE_BACKSTORE_LAYOUT_MISMATCH')
        objects, _ = entries(hba)
        require(len(objects) == 1, 'ACTIVE_BACKSTORE_LAYOUT_MISMATCH')
        object_name, object_path = next(iter(objects.items()))
        require(object_name in expected_backstores and object_name not in backstores,
                'ACTIVE_BACKSTORE_LAYOUT_MISMATCH')
        backstores[object_name] = object_path
    require(set(backstores) == set(expected_backstores), 'ACTIVE_BACKSTORE_LAYOUT_MISMATCH')

    target_names = {plan['clients'][node]['target_iqn'] for node in ISCSI}
    targets = exact_directories(root['iscsi'], target_names, optional=('discovery_auth',))
    for node in ISCSI:
        client = plan['clients'][node]
        backstore = backstores['greenwood-' + node]
        image = BACKING + '/' + node + '.img'
        require(public_text(backstore / 'udev_path') == image, 'ACTIVE_BACKSTORE_PATH_MISMATCH')
        require(public_text(backstore / 'wwn/vpd_unit_serial') ==
                'T10 VPD Unit Serial Number: ' + client['filesystem_uuid'], 'ACTIVE_BACKSTORE_SERIAL_MISMATCH')
        require(public_text(backstore / 'attrib/emulate_write_cache') == '0', 'ACTIVE_BACKSTORE_CACHE_MISMATCH')
        info = public_text(backstore / 'info')
        expected_info = {'File': image, 'Size': str(LUN_BYTES), 'Mode': 'O_DSYNC', 'Async': '0',
                         'Status': 'ACTIVATED'}
        require(all(info_field(info, key) == value for key, value in expected_info.items()),
                'ACTIVE_BACKSTORE_INFO_MISMATCH')

        target = targets[client['target_iqn']]
        groups = exact_directories(target, ('tpgt_1',), optional=('fabric_statistics', 'param'))
        tpg = groups['tpgt_1']
        exact_directories(tpg, ('lun', 'np', 'acls', 'attrib', 'auth', 'param'))
        require(public_text(tpg / 'enable') == '1', 'ACTIVE_TARGET_DISABLED')
        for attribute, value in (('generate_node_acls', '0'), ('cache_dynamic_acls', '0'),
                                 ('demo_mode_write_protect', '1'), ('authentication', '1')):
            require(public_text(tpg / 'attrib' / attribute) == value, 'ACTIVE_TARGET_POLICY_MISMATCH')
        exact_directories(tpg / 'np', (plan['target']['address'] + ':3260',))
        luns = exact_directories(tpg / 'lun', ('lun_0',))
        exact_link(luns['lun_0'], backstore)
        acls = exact_directories(tpg / 'acls', (client['initiator_iqn'],))
        acl = acls[client['initiator_iqn']]
        exact_directories(acl, ('lun_0', 'attrib', 'auth', 'param'), optional=('fabric_statistics',))
        # Noble's per-ACL override must inherit the authenticated TPG policy.
        require(public_text(acl / 'attrib/authentication') == '-1', 'ACTIVE_ACL_AUTHENTICATION_MISMATCH')
        require(public_text(acl / 'lun_0/write_protect') == '0', 'ACTIVE_LUN_WRITE_PROTECTED')
        exact_link(acl / 'lun_0', luns['lun_0'])


def check_active_target(plan, approved):
    storage.check_target(plan, new=False)
    prepared.check_backing_files(plan, approved)
    state = storage.run(['systemctl', 'show', 'greenwood-state-target.service',
                         '-p', 'ActiveState', '-p', 'SubState', '-p', 'MainPID'])
    require(set(state.splitlines()) == {'ActiveState=active', 'SubState=exited', 'MainPID=0'},
            'ACTIVE_TARGET_SERVICE_REQUIRED')
    check_configfs(plan)
    check_listener(plan['target']['address'])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--plan-json'); parser.add_argument('--plan-file')
    parser.add_argument('--approved-sha256', required=True)
    parser.add_argument('--node', required=True)
    parser.add_argument('--action', choices=('check',), required=True)
    args = parser.parse_args()
    try:
        require(os.geteuid() == 0, 'ROOT_REQUIRED')
        require(args.node == 'node-4', 'TARGET_REQUIRED')
        require(bool(args.plan_json) != bool(args.plan_file), 'ONE_PUBLIC_PLAN_REQUIRED')
        if args.plan_file:
            storage.protected(args.plan_file)
        plan = validate(json.loads(args.plan_json or Path(args.plan_file).read_text()), args.approved_sha256)
        storage.protected('/etc/greenwood-storage', directory=True)
        for name in ('storage_plan.py', 'prepare_state_storage.py', 'prepare_durable_state.py',
                     'migrate_sqlite_rollback.py', 'plan.json'):
            storage.protected('/etc/greenwood-storage/' + name)
        check_active_target(plan, args.approved_sha256)
    except Exception as error:
        message = str(error) if isinstance(error, PlanError) else type(error).__name__
        parser.exit(1, 'STORAGE STOP: ' + message + '; preserve existing media and review locally.\n')
    print(json.dumps({'status': 'PASS', 'node': args.node, 'action': args.action,
                      'active_target_resume': True, 'plan_sha256': args.approved_sha256}))


if __name__ == '__main__':
    main()
