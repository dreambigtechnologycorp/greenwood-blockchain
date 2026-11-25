"""Select durable directories from public deployment settings, never key files."""
import posixpath
import re
import tomllib

from ansible.errors import AnsibleFilterError


def require(condition, message):
    if not condition:
        raise AnsibleFilterError('Invalid durable storage inventory: ' + message)


def path(value):
    require(isinstance(value, str) and re.fullmatch(r'/[A-Za-z0-9_./-]+', value)
            and posixpath.normpath(value) == value and value != '/'
            and '..' not in value.split('/'), 'explicit normalized absolute state path required')
    return value


def greenwood_durable_storage_paths(settings):
    require(isinstance(settings, dict), 'public storage settings required')
    groups = settings.get('groups', [])
    require(isinstance(groups, list), 'host roles required')
    paths = set()

    def directory(value):
        paths.add(path(value))

    def database(value):
        directory(posixpath.dirname(path(value)))

    if 'validators' in groups:
        directory('/var/lib/greenwood')
        directory(settings.get('validator_data', '/var/lib/greenwood'))
    if 'providers' in groups:
        base = path(settings.get('provider_data', '/var/lib/greenwood-provider'))
        directory('/var/lib/greenwood-provider')
        directory(base)
        for suffix in ('applications', 'application-network', 'workers'):
            directory(base + '/' + suffix)
        if settings.get('provider_workers'):
            directory(settings['provider_workers'])

    services = settings.get('control_services', [])
    configs = settings.get('control_configs', {})
    require(isinstance(services, list) and isinstance(configs, dict), 'control-plane storage settings required')
    require(set(services) <= {'controller', 'observer', 'receiver', 'edge'}, 'unknown control service')
    if 'control_plane' in groups:
        directory('/var/lib/greenwood-control-plane')
    required = {
        'controller': ('database_path', 'policy_ledger'),
        'observer': ('policy_ledger',),
        'receiver': ('policy_ledger', 'receiver_ledger'),
        'edge': ('policy_ledger', 'distribution_ledger', 'edge_directory'),
    }
    db_keys = ('database_path', 'policy_ledger', 'receiver_ledger', 'distribution_ledger')
    for service in services:
        config = configs.get(service)
        require(isinstance(config, dict), 'selected service lacks explicit storage settings')
        require(all(key in config for key in required[service]), 'selected service lacks required durable paths')
        for key in db_keys:
            if key in config:
                database(config[key])
        if service == 'edge':
            directory(path(config['edge_directory']) + '/route-state')
    # JSON-only publication/assignment/admission directories use different
    # locking and atomic-file semantics. They are not SQLite WAL roots, and
    # this gate must not force the publisher's colocated keys to be relocated.

    systems = settings.get('system_services', [])
    require(isinstance(systems, list), 'system-service definitions required')
    for service in systems:
        require(isinstance(service, dict) and service.get('id') in
                ('greenwood-registry', 'greenwood-registry-auth'), 'unsupported seed service storage')
        mounts = service.get('mounts', [])
        require(isinstance(mounts, list), 'explicit seed-service bind mounts required')
        writable = []
        for mount in mounts:
            require(isinstance(mount, dict) and isinstance(mount.get('options'), list), 'invalid service mount')
            options = mount['options']
            require(not ('ro' in options and 'rw' in options), 'contradictory service mount mode')
            if 'ro' in options:
                continue
            require(mount.get('type') == 'bind', 'writable seed storage must use an explicit bind mount')
            source, destination = path(mount.get('source')), path(mount.get('destination'))
            directory(source)
            writable.append((destination, source))

        def mounted_directory(container_path):
            container_path = path(container_path)
            matches = [(dest, src) for dest, src in writable
                       if container_path == dest or container_path.startswith(dest + '/')]
            require(bool(matches), 'seed state must resolve to an explicit writable host bind mount')
            dest, src = max(matches, key=lambda pair: len(pair[0]))
            directory(src + container_path[len(dest):])

        if service['id'] == 'greenwood-registry-auth':
            env = service.get('environment', {})
            require(isinstance(env, dict), 'registry-auth environment must be explicit data')
            # This is the runtime's documented default, not an invented host path.
            db = env.get('GREENWOOD_REGISTRY_AUTH_DB', '/var/lib/greenwood/registry-auth/auth.sqlite')
            mounted_directory(posixpath.dirname(path(db)))
        else:
            # A reviewed custom Zot root must also be represented publicly here.
            mounted_directory(settings.get('registry_container_storage', '/var/lib/registry'))

    if settings.get('governed_agent_stage', False):
        agent = settings.get('governed_agent_config', {})
        require(isinstance(agent, dict) and 'data_directory' in agent, 'governed service data directory required')
        directory(agent['data_directory'])

    runtime_roles = {'validators', 'providers', 'control_plane', 'system_runtime_hosts'}
    if runtime_roles.intersection(groups):
        raw = settings.get('containerd_config')
        require(isinstance(raw, str) and bool(raw.strip()), 'approved public containerd configuration required')
        try:
            config = tomllib.loads(raw)
        except (ValueError, TypeError):
            raise AnsibleFilterError('Invalid durable storage inventory: cannot parse approved containerd configuration') from None
        require(not config.get('imports'), 'containerd imports need separately reviewed storage resolution')
        directory('/var/lib/containerd')
        directory(config.get('root', '/var/lib/containerd'))
        plugins = config.get('plugins', {})
        require(isinstance(plugins, dict), 'invalid containerd plugin configuration')
        for name, plugin in plugins.items():
            require(isinstance(plugin, dict), 'invalid containerd plugin settings')
            if name.startswith('io.containerd.snapshotter.v1.') and plugin.get('root_path'):
                directory(plugin['root_path'])
        # External snapshotters may keep durable state outside the declared root.
        require(not config.get('proxy_plugins'), 'external snapshotter storage needs separate qualification')
    require(bool(paths), 'no applicable durable state paths selected')
    return sorted(paths)


class FilterModule:
    def filters(self):
        return {'greenwood_durable_storage_paths': greenwood_durable_storage_paths}
