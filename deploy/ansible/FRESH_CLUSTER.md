# Fresh Cluster Installation

For the existing operator's staged seven-node reference installation, begin with
[project status](../../docs/PROJECT_STATUS.md) and the
[state-storage continuation](STATE_STORAGE.md). Existing production identities
are already operator-provisioned; use the bounded continuation after its gates
pass. The fresh workflow below is for genuinely uninitialized installations.

`playbooks/fresh_cluster.yml` is the one-shot installation entrypoint for the
explicit `four` or `seven` profile on already booted Ubuntu 24.04 ARM64 hosts
with working SSH, Python 3 and sudo.
It is not a bare-metal imaging tool, an upgrade playbook, or proof of physical
acceptance. Do not run it against an existing Greenwood cluster.

## Profile selection

The selected seven-node layout keeps validators/providers on nodes 1–3, adds
providers and colocated application controllers on 6–7, uses node-5 for the
edge/RPC relay and registry/auth, and preserves node-4 gateway/NFS/netboot,
Cloudflare and shared SSD/iSCSI infrastructure. Node-1 is the sole artifact
build controller and also retains its application controller. See the
[seven-node placement table](../../docs/operations/SEVEN_NODE_INSTALLATION.md).

The following table describes the legacy `four` profile only. Select `seven`
explicitly in its reviewed inventory; do not copy the legacy service placement.

### Legacy four-node layout

| Host | Roles |
| --- | --- |
| node-1 | Validator A, compute provider A, build controller, application provider, receiver, observer, deployment controller, registry/issuer |
| node-2 | Validator B, compute provider B |
| node-3 | Validator C, compute provider C |
| node-4 | Gateway/DNS/DHCP/NAT, application edge and admission, receiver; no consensus or compute role |

Terraform describes this layout and produces inventory; it does not create
machines or install their OS. Its `ssh_user`, `node_addresses` and
`gateway_address` variables are consumed. Architecture and role assignments are
fixed. Networking must initially permit SSH to the final management addresses.
Ensure recovery access before authorizing gateway network changes.

## Protected Installation Inputs

Keep the installation manifest and host variables outside Git. Supply actual
approved artifacts, not placeholder hashes, replacement trust keys, or a source
revision copied from an old guide. Permissions on private source credentials
must be 0600; no secrets should be passed in command-line arguments or `--diff`.

| Input | Required content |
| --- | --- |
| `greenwood_source_sha` | Full reviewed commit containing these provisioning changes and hosting runtime sources |
| `greenwood_containerd_submodule_sha` | Full approved protocol-source commit |
| `provider_chain_id` | The new chain's exact ID, shared by every host and genesis |
| `greenwood_genesis_src`, `greenwood_genesis_sha256` | Approved genesis JSON with validators A/B/C, their unique public keys and reachable peer endpoints |
| `greenwood_chain_config_src`, `greenwood_chain_config_sha256` | Approved chain rules JSON and SHA-256 |
| `greenwood_validator_key_src` | Per-validator canonical 96-byte public+secret Ed25519 key file matching genesis |
| `greenwood_provider_key_src`, `greenwood_provider_public_key` | Per-provider canonical 96-byte key and expected public key |
| `greenwood_operator_token_src` | Per-node private operator token, at least 32 characters |
| `greenwood_php_repository_key_src`, `greenwood_php_repository_key_sha256` | Approved armored APT signing public key and SHA-256 |
| `greenwood_php_apt_repository` | Distribution-appropriate `deb [signed-by=/etc/apt/keyrings/greenwood-php.asc] https://HOST/PATH noble main` line providing PHP 8.5.12 ARM64 packages |
| `containerd_version`, `containerd_sha256`, `runc_version`, `runc_sha256` | Exact approved versions and artifact SHA-256 values |
| `erofs_utils_version`, `greenwood_containerd_config` | Exact distribution package version and compatible containerd TOML |
| `greenwood_hosting_release` | `/opt/greenwood` for this profile |

The operator defines genesis and supplies identities; the installer never creates
signing authority. It verifies checksums, key-pair consistency and validator
membership before starting any validator. Every validator receives identical
genesis and chain configuration. Runtime/source pins have no permissive fallback.

For a new network configuration, node 4 additionally requires `greenwood_netplan_src`, `greenwood_netplan_sha256`,
`greenwood_dnsmasq_src`, `greenwood_dnsmasq_sha256`, `greenwood_lan_interface`,
`greenwood_wan_interface` and `greenwood_lan_cidr`. The approved netplan must retain
the management address and configure WAN/LAN routes. The DNS/DHCP file must bind
only intended interfaces and provide the reviewed pool/reservations. The installer
validates netplan and dnsmasq, applies networking, reconnects, enables forwarding
and installs a dedicated outbound NAT table without flushing unrelated nftables.
Existing OS firewall policy must permit the approved SSH, consensus and private
control-plane traffic; NAT is not a replacement for firewall policy. The current
seven-node profile selects `greenwood_preserve_gateway: true` to preserve the
existing node-4 network/boot services; it does not reapply this network setup.

For hosting, provide the complete per-host `greenwood_control_configs`,
`greenwood_control_files`, protected directory definitions, policy renewal/SFTP
configuration, HTTPS frontend definitions, `greenwood_system_services` and
application network policy documented in [Control Plane Operations](CONTROL_PLANE_HANDOFF.md).
For `four`, node-1 selects `controller`, `observer`, `receiver` and node-4 selects
`edge`, `receiver`. For the selected `seven` composition, nodes 1/6/7 select
`controller`, `observer`, `receiver`; nodes 2/3 select `observer`, `receiver`;
node-5 selects `edge`, `receiver`. Provision certificates, separate signing keys, current signed policy
snapshots and issuer verification material through the protected file inputs.
Service ownership and TLS checks remain enforced by the existing staging role.

The selected `registry_hosts` node (node-5 for `seven`, node-1 for `four`) requires
`greenwood_seed_images` with exactly two entries:

```yaml
greenwood_seed_images:
  - id: greenwood-registry
    src: /secure/artifacts/registry.oci.tar
    sha256: APPROVED_ARCHIVE_SHA256
    image_ref: REGISTRY_REPOSITORY@sha256:APPROVED_MANIFEST_DIGEST
  - id: greenwood-registry-auth
    src: /secure/artifacts/issuer.oci.tar
    sha256: APPROVED_ARCHIVE_SHA256
    image_ref: ISSUER_REPOSITORY@sha256:APPROVED_MANIFEST_DIGEST
```

Replace every placeholder. Each reference must match its system-service
definition and be preserved in the OCI archive. Imports are offline, scoped to
the `greenwood` namespace and EROFS snapshotter. No pull from an uninitialized
registry is attempted. The supervisor independently verifies image identity.

## Run

From the repository root, prepare the Terraform outputs and generated inventory:

```sh
terraform -chdir=deploy/terraform init
terraform -chdir=deploy/terraform validate
terraform -chdir=deploy/terraform plan
terraform -chdir=deploy/terraform apply
terraform -chdir=deploy/terraform output -json |
  php deploy/ansible/generate_inventory.php > deploy/ansible/inventory/generated.ini
export ANSIBLE_CONFIG="$PWD/deploy/ansible/ansible.cfg"
ansible-playbook -i deploy/ansible/inventory/generated.ini \
  deploy/ansible/playbooks/fresh_cluster.yml --syntax-check
```

Store per-host variables in a protected inventory directory supplied as an
additional `-i` source, or Ansible `host_vars` beside the generated inventory.
Use a protected global manifest for common values. It must explicitly contain
`greenwood_fresh_cluster_approved: true`, `greenwood_network_apply_approved: true`,
`greenwood_activate_protocol: true` and `greenwood_activate_hosting: true`.
These are operator decisions, not defaults. The controller build account defaults
to `ansible_user`; provision its approved Git access before running the workflow.

```sh
ansible-playbook -i deploy/ansible/inventory/generated.ini -i /secure/inventory \
  deploy/ansible/playbooks/fresh_cluster.yml -e @/secure/install.yml --list-hosts
ansible-playbook -i deploy/ansible/inventory/generated.ini -i /secure/inventory \
  deploy/ansible/playbooks/fresh_cluster.yml -e @/secure/install.yml
```

Do not use check mode to simulate activation: this workflow explicitly rejects it.
No broad tag skipping is supported. It preflights all hosts, provisions the gateway,
prepares PHP/Wasmtime before the build controller, distributes a single release,
installs/validates identities and stages edge/application/control-plane configuration.
It starts validators, verifies chain-specific finalized height, starts validator
HTTPS origins before RPC relays, installs provider-only public CA trust and checks
provider chain/genesis/finality before starting providers. Seed import and
authenticated policy/service activation follow. The edge starts with the runtime-rendered deny-all
loopback listener on port 8081; the distribution placeholder site is disabled.
Application routes are not published by this sequence.

## Completion and Recovery

A fresh installation refuses existing chain/configuration state. After an
interruption, preserve installed files and reconcile the failing stage rather than
rerunning or deleting state to pass preflight. Maintenance uses the component
playbooks, not the fresh-install path. Never restart a second initialization over
an existing genesis or signing journal.

Deployment writes and managed edge publication remain disabled. A new chain still
requires authorized on-chain registry deployment and finalized application/release
authority before the developer lifecycle can run. This installer does not submit
blockchain transactions, issue CA certificates, grant allocations, configure an
external DNS provider, or install Cloudflare credentials. Those inputs must be
genuine; they cannot be inferred from service startup.

Physical readiness requires target-host validation of units, PHP 8.5.12, EROFS,
SFTP/mTLS, policy renewal, actual registry authorization, and reboot recovery.
Maintain the resulting acceptance scripts and evidence only in ignored
`docs/acceptance/`. Local source/syntax tests do not establish those outcomes.

### Release metadata lookup

The controller build and artifact-fetch plays must use the same inventory source
revision and directory overrides. Both fresh deployment and runtime maintenance
load release metadata through the controller role, preserving the lower precedence
of role defaults. Do not load `roles/controller/defaults/main.yml` through
`vars_files`: its empty source revision would override the approved inventory pin
and produce a path such as `greenwood-releases//build/release/artifacts/latest-release.json`.
The metadata reader rejects missing source pins and artifacts from another commit.

If an older installer stops at this malformed-path lookup, after controller build
but before runtime distribution, apply the deployment-playbook correction and
rerun the complete fresh workflow with the same approved inventory. That specific
failure precedes protocol installation and activation; retain the existing build,
credentials and runtime prerequisites. Do not create a placeholder metadata file,
copy artifacts into the malformed path, skip checks or delete initialized state.
Failures later in installation still require the stage-specific reconciliation
described above.

### Continue after runtime and identity staging

If the first control-plane validation fails after runtime distribution and
`fresh_identity`, genesis is already installed. Do not rerun the fresh installer,
delete genesis or use `--start-at-task`. The human-only
`playbooks/resume_control_plane.yml` covers this specific pre-validator-start
boundary. It repeats all shared approvals, exact topology/recovery, runtime-pin,
artifact and credential-input checks, verifies installed source and canonical
tree digests against the build controller, and checks installed genesis/chain
hashes and cryptographic identity before executing the same remaining staging
and activation plays as fresh installation. It never rebuilds, redistributes,
replaces keys or resets state.

All validators must remain stopped with no initialization marker, ledger, signing
journal or SQLite sidecar. Provider and recovery services must also be stopped.
An earlier installer started the application-recovery timer during staging; that
timer could start providers on nodes 6–7 before RPC was ready. Inspect service
state and explicitly stop/disable those premature services under operator control.
Retain every provider database and lock/status file. The continuation accepts only
an identity-bound empty provider store, with no finalized history, jobs,
applications, reservations, network events or containerd workloads. Anything else
requires explicit reconciliation and is refused. A failed but stopped recovery
service may retain its failure evidence.

On an NFS-root host, the inspection helper takes a shared, nonblocking lock on
the existing supervisor lock through a read-only descriptor. This still excludes
the provider's exclusive writer lock; requesting an exclusive inspection lock
through that descriptor fails with `EBADF` on NFS. Database/WAL inspection uses
protected temporary copies under a verified `/run` tmpfs, never the default
`/tmp` (which can also be NFS-backed). Originals are preserved, including WAL
contents; failures report bounded phase/error codes without database contents.

This inspection compatibility does **not** qualify NFS for live database storage.
Greenwood writable state needs a supported durable filesystem; a former WAL
release does not make NFS suitable. Check the actual
mounts for protocol, provider and control-plane state before activation; an
NFS-backed path must be relocated under an explicitly reviewed storage procedure.
Keep the OS netboot arrangement if desired. `/run` is only for disposable
inspection copies, never the production ledger or provider state. See the
[Linux NFS lock semantics](https://man7.org/linux/man-pages/man2/flock.2.html)
and [SQLite WAL limitations](https://sqlite.org/wal.html).

The shared first-start tail now derives durable paths from the selected roles,
public service configuration, seed-image writable bind mounts and approved
containerd TOML. A metadata-only check rejects unsafe ancestry, read-only mounts,
network filesystems and volatile storage before any control-plane staging or
activation. Currently accepted filesystem types are ext4, XFS, Btrfs and ZFS.
An ext4 filesystem on a dedicated iSCSI block device is distinct from directly
placing a database on NFS. Passing the metadata check does not qualify the
underlying disk, transport, flush behavior or reboot recovery.

#### Durable storage before continuation

The [state-storage procedure](STATE_STORAGE.md) describes three exclusive 64 GiB
ext4 iSCSI volumes on node-4's existing SSD for nodes 1–3, and the reviewed existing
ext4 SD partitions for nodes 5–7. OS netboot stays in place. Preserve original
state, complete the verified copy/bind/offline conversion, and retain copy and
migration receipts. Never place production state in `/run` or directly on NFS.
See [project status](../../docs/PROJECT_STATUS.md) for the last reported phase;
a procedure's existence does not mean its live run completed.

The pending runtime uses SQLite `DELETE` journals with `synchronous=EXTRA` and
refuses a WAL database until explicit offline migration. This changes the runtime
artifact: review the complete source, rebuild and qualify it, then replace source
and image pins. Mount checks and local regressions do not establish SSD flush,
transport-loss, reboot or shared-node-4 recovery acceptance.

From the repository root, with the same private inventory and scoped approval
file used for the interrupted installation, **only after durable storage and
offline conversion are complete, the newly rebuilt rollback-journal release is
approved and distributed, and `verify_state_runtime.yml` has restored containerd
readiness**. Do not resume the older WAL-based runtime revision:

```sh
ansible-playbook -i "$GW_INVENTORY" deploy/ansible/playbooks/resume_control_plane.yml \
  -e "@$GW_APPROVALS" -e '{"greenwood_resume_control_plane_approved":true}' --syntax-check
ansible-playbook -i "$GW_INVENTORY" deploy/ansible/playbooks/resume_control_plane.yml \
  -e "@$GW_APPROVALS" -e '{"greenwood_resume_control_plane_approved":true}'
```

These commands are for the operator because identity validation accesses private
keys. The continuation refuses active or uncertain services and never stops them
implicitly. Application recovery units are now staged without starting their
timer during fresh installation; initial network activation leaves it disabled.
Before enabling hosted workloads and recovery, finish the recovery unit's public
provider-identity and approved hosted-funding configuration. Existing standalone
maintenance operations retain their explicit recovery behavior.

## Expanded topology

The `seven` profile and its private-input requirements are documented in [the installation guide](../../docs/operations/SEVEN_NODE_INSTALLATION.md). The default `four` profile is retained. Inventory generation alone does not authorize installation or service activation.
