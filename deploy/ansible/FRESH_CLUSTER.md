# Fresh Cluster Installation

`playbooks/fresh_cluster.yml` is the one-shot installation entrypoint for four
already booted Ubuntu 24.04 ARM64 hosts with working SSH, Python 3 and sudo.
It is not a bare-metal imaging tool, an upgrade playbook, or proof of physical
acceptance. Do not run it against an existing Greenwood cluster.

## Fixed Layout

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

Node 4 additionally requires `greenwood_netplan_src`, `greenwood_netplan_sha256`,
`greenwood_dnsmasq_src`, `greenwood_dnsmasq_sha256`, `greenwood_lan_interface`,
`greenwood_wan_interface` and `greenwood_lan_cidr`. The approved netplan must retain
the management address and configure WAN/LAN routes. The DNS/DHCP file must bind
only intended interfaces and provide the reviewed pool/reservations. The installer
validates netplan and dnsmasq, applies networking, reconnects, enables forwarding
and installs a dedicated outbound NAT table without flushing unrelated nftables.
Existing OS firewall policy must permit the approved SSH, consensus and private
control-plane traffic; NAT is not a replacement for firewall policy.

For hosting, provide the complete per-host `greenwood_control_configs`,
`greenwood_control_files`, protected directory definitions, policy renewal/SFTP
configuration, HTTPS frontend definitions, `greenwood_system_services` and
application network policy documented in [Control Plane Operations](CONTROL_PLANE_HANDOFF.md).
Node 1 selects `controller`, `observer`, `receiver`; Node 4 selects `edge`,
`receiver`. Provision certificates, separate signing keys, current signed policy
snapshots and issuer verification material through the protected file inputs.
Service ownership and TLS checks remain enforced by the existing staging role.

Node 1 requires `greenwood_seed_images` with exactly two entries:

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
installs/validates identities, starts validators, waits for chain-specific finalized
height, then starts providers. The edge receives the same release. Application
networking, recovery timer, control-plane staging, seed import and authenticated
service activation follow. The edge starts with the runtime-rendered deny-all
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
