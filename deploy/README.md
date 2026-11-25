# Greenwood Deployment

Start with [role-based onboarding](../docs/operations/ROLE_BASED_ONBOARDING.md)
for your own environment, then [operator runbooks](../docs/operations/README.md).
The turnkey Ansible workflows validate explicit four- and seven-node reference
profiles; they do not discover arbitrary homelab roles. [Project status](../docs/PROJECT_STATUS.md)
separates current source/release state from the reference installation's progress.
Reuse existing matching credentials and select a stage-specific continuation
when initialization has already begun.

Use [Control Plane Operations](ansible/CONTROL_PLANE_HANDOFF.md) for protected
configuration, trust material, policy transport, service activation and recovery.
This guide covers the current source entrypoints. Applying a playbook is a
maintenance operation and requires an approved inventory and operation scope.

## Select the Entry Point

For a new cluster on already booted Ubuntu 24.04 ARM64 hosts, follow
[Fresh Cluster Installation](ansible/FRESH_CLUSTER.md). That workflow includes
the network, runtime, identity, protocol startup, seed import and hosting stages.
It refuses existing Greenwood state and must not be used as an upgrade command.

| Playbook under deploy/ansible/playbooks | Behavior |
| --- | --- |
| fresh_cluster.yml | Explicit one-shot initialization with all-host preflight, protected inputs, protocol startup and hosting activation. Requires a complete approved installation manifest. |
| activate_hosting.yml | Explicitly approved offline seed import and authenticated service activation; does not enable deployment writes or publish applications. |
| deploy_greenwood.yml | Builds a pinned release on the controller, fetches it, stops installed protocol services, converges common/validator/provider roles. Not a rolling or read-only operation. |
| release_runtime.yml | Build with `--tags controller`; distribute with `--tags runtime_release`. Preflight covers validators, providers, control-plane and system-runtime hosts before stopping consumers; restore only previously active consumers. |
| prepare_state_storage.yml | Guarded initial durable-storage preparation; not a general rerun or active-cluster mover. |
| resume_mounted_state_copy.yml | Bounded continuation after node-4 targets are active and only node-1 has its approved state volume mounted, before copying or bind mounts. |
| verify_state_runtime.yml | After migration and rebuilt-release distribution, verify release/storage evidence and start only containerd. |
| resume_control_plane.yml | Operator-only continuation after runtime/identity staging, with no validator initialization; preserves identities/state and resumes guarded first-start staging. |
| control_plane.yml | Stages protected configuration, units, HTTPS frontends and policy timers; does not activate them. |
| application_hosting.yml | Starts forwarding prerequisites and containerd as needed, stages the provider drop-in, and can publish/reload an explicitly approved Apache route. Does not restart the provider to adopt its drop-in. |
| gateway.yml | Validates selected network interfaces. |
| gateway_teardown.yml | Separately authorized destructive maintenance; not an installation dependency. |

bootstrap_greenwood.yml prepares runtime dependencies on protocol/control-plane
hosts. bootstrap_local.yml contains Linux APT operations
against localhost; it is not a macOS setup process. Use Composer and the
[local quickstart](../docs/getting-started/quickstart.md) for development.

## Inventory and Release Inputs

Supply `ssh_user`, `node_addresses`, and `gateway_address` explicitly; Terraform
has no personal account or network defaults. Copy
`terraform/topology.tfvars.example` to an ignored local `.tfvars` file and replace
the documentation addresses before planning. For a separate controller inventory,
copy `ansible/inventory/controller.example.ini` to ignored `controller.ini` and
replace its host and account. Never commit populated inventories or state files.

The inventory generator consumes Terraform node_topology JSON on stdin.
Its validation uses [versioned topology profiles](topology/profiles.json): validators
A/B/C stay on nodes 1–3, the sole artifact build controller is node-1 and the
gateway is node-4. `seven` adds providers D/E on nodes 6–7 and places edge/RPC
relay/registry services on node-5. It assigns control-plane hosts 1/2/3/5/6/7 and
application providers 6/7. `four` retains the original groups. Select the profile
explicitly; changing Terraform roles alone does not authorize arbitrary topology.

The existing operator's seven-node reference composition includes application controllers on
nodes 1, 6 and 7, each using its colocated provider. The `controller` inventory
group still contains only node-1 for artifact builds. The public example is a
minimal template and omits controllers on 6/7: complete their per-host service,
HTTPS and peer configuration from reviewed public inputs. See the
[placement and installation guide](../docs/operations/SEVEN_NODE_INSTALLATION.md).

Pin greenwood_source_sha and greenwood_containerd_submodule_sha to approved full
commits, and review repository URL, source/build paths and artifact provenance.
Do not assume role defaults select the version being documented. Runtime release
facts must match the selected source SHA, archive checksum and tree digest.
release_runtime preflight requires the local artifact cache as well as controller
metadata; check mode does not build a missing release.

Provider installation additionally requires containerd_version, containerd_sha256,
runc_version, runc_sha256, greenwood_containerd_config and erofs_utils_version.
See [Linux requirements](../docs/linux-execution-manifest.md). Secrets, approvals,
certificate keys and genesis identities are external protected inputs, not
generated by documentation examples.

## Review Before Applying

From the repository root, syntax inspection uses no target convergence:

```sh
export ANSIBLE_CONFIG="$PWD/deploy/ansible/ansible.cfg"
ANSIBLE_LOCAL_TEMP=/tmp/greenwood-ansible-local ansible-playbook \
  -i localhost, deploy/ansible/playbooks/control_plane.yml --syntax-check
```

For an approved target inventory, inspect --list-hosts and --list-tasks before
--check. Check mode can execute explicit read-only validation commands and requires
target access; it is not proof that an apply will succeed. Do not use --diff with
secrets or interpret a zero changed count as independent idempotency evidence.

Use the [control-plane sequence](ansible/CONTROL_PLANE_HANDOFF.md#activation)
after dependencies are provisioned. Keep installation, authorization and physical
acceptance separate. Preserve chain history, signing journals, provider ownership,
policy ledgers, registry storage and unrelated routes during all maintenance.

## Acceptance

Record exact component revisions and runtime artifacts, validate rendered units
and Apache configuration on Linux, then demonstrate two-pass convergence and
physical positive/negative checks on the intended installation profile.
Source tests alone do not establish a reproducible installation. Application READY
requires authenticated assignment ACKs, runtime identity, effective routing and
verified public content.
