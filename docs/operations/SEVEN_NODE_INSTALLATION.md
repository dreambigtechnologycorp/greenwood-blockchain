# Four- and seven-node deployment guide

This is source tooling for the existing Pis. It does not require the future PowerEdge, a datacenter move or another qualification cluster. It does not authorize destructive installation or supply production governance approvals.

The [November 17 infrastructure checkpoint](SEVEN_NODE_PREFLIGHT_2026-11-17.md)
records the completed seven-host identity and netboot checks, unused reformatted
SD cards, actual runtime gaps, permission repairs and remaining activation gates.
The profile placement below is the intended production layout, not a claim that
registry, edge or provider services have already moved to the new hosts.

## Supported placement

The versioned `deploy/topology/profiles.json` is shared by Terraform, inventory generation, Ansible preflight and production release/acceptance tools.

| Function | `four` profile | `seven` profile |
|---|---|---|
| Validators A/B/C | nodes 1–3 | nodes 1–3, unchanged |
| Providers | A/B/C on nodes 1–3 | retain A/B/C; add D/E on nodes 6–7 |
| Source/build controller | node-1 | node-1 |
| Application deployment controller | node-1 with its local provider | node-1 with its local provider |
| Registry and registry authentication | node-1 | node-5 |
| Application edge / registration frontend placement | node-4 | node-5 |
| New customer application hosts | node-1 | nodes 6–7 |
| LAN/network boot/NFS | node-4 | node-4, preserved |
| Governed meter hosts | selected providers | selected providers, plus node-5 for coordinator/approved supporting classes |

The runtime deployment controller currently uses an authenticated local provider connection. It deliberately stays on node-1. Do not move it to node-5 by deleting a systemd dependency or exposing the operator socket. The registry/edge/coordinator moves already offload useful work. Existing providers are not silently retired, and no new validator or independent attestor is created.

Node-5 can initially netboot if that is the available setup. This retains the known node-4 failure dependency. Local boot/storage is an optional future improvement, not a fabricated prerequisite. Define realistic capacity reservations and outage/recovery expectations for the hardware available.

## 1. Fill private inputs; do not deploy the example

Start with `deploy/ansible/inventory/seven-node.example.yml`. It contains deliberately unusable addresses/identities and false approval flags. Record the actual new Pis' MAC addresses, boot serial directory IDs, SSH host fingerprints and machine IDs. Never copy an old machine ID or SSH host key onto a new Pi. Host fingerprints must be verified before authenticated deployment.

Supply independently provisioned validator/provider keys through private source paths. Provider-only hosts receive a provider key and operator token, never a validator key. The installer does not generate signing authorities or register providers by inventing authorization. Approve/register each provider through Greenwood's existing governance/operator workflow and verify registration during physical acceptance.

Supply the existing protected configuration inputs described in `deploy/ansible/FRESH_CLUSTER.md` and `CONTROL_PLANE_HANDOFF.md`, including:

- Immutable source SHA, genesis/chain file checksums and reviewed PHP/containerd/runc/EROFS pins.
- `greenwood_expected_genesis_hash`: the protocol genesis hash, **distinct from** `greenwood_genesis_sha256`, the SHA-256 of the supplied genesis JSON file.
- Separate provider public keys and private key/token paths; approved chain ID and RPC endpoints.
- For provider-only hosts, `provider_rpc_endpoint` must be an approved HTTPS origin whose chain/genesis matches. Colocated validators may use `http://127.0.0.1:9201`. Configure system trust for the approved TLS issuer; never disable certificate validation.
- Capacity fractions `provider_cpu_fraction` and `provider_memory_fraction` (default 0.60, permitted >0 through 0.80), plus `provider_max_concurrent` (default 3). These are local admission ceilings, not measured payable capacity.
- Explicit per-host control-plane configs, files, policy renewal/pull transport, endpoint certificates, receiver/provider identities and edge-address/network policies.
- Registry/auth offline archives, immutable references and supervisor definitions **only on `registry_hosts`**. Supply root-only containerd socket configuration for an infrastructure-only host; do not unnecessarily grant the worker group access there.

The private inventory must exactly match its chosen profile groups. The seven-node example's node-1 control services are controller/observer/receiver; node-5 edge/receiver; remaining providers observer/receiver. Registry seed definitions are separate. TLS frontends, RPC forwarding and public tunnel routes require their actual reviewed configuration, not guessed endpoints.

Terraform consumers can set `topology_profile = "seven"`, supply all seven distinct addresses, and render the generated membership:

```sh
terraform -chdir=deploy/terraform output -json | php deploy/ansible/generate_inventory.php > /private/generated.ini
```

Merge reviewed private variables using normal Ansible host/group vars or an explicit vars file. Generated inventory is membership/address material, not a complete deployment approval. `four` remains the default for legacy callers. No unknown profile or arbitrary validator expansion is accepted.

For `scripts/production-preflight.py`, the seven-host identity file uses `version: 2`, `topology_profile: "seven"`, and the same exact per-host identity fields as version 1. The known-hosts file must match precisely all declared addresses. Version 1 remains the four-node interface. Release and RPC acceptance configs select the same `topology_profile`.

## 2. Prepare new netboot clients without replacing node-4

Use `deploy/topology/netboot-client.example.json` as a declaration template. Verify actual root-parent and TFTP paths on node-4. The Pi boot directory ID is not a MAC address; record the actual EEPROM/serial boot behavior. Supply:

- A reviewed, digest-pinned clean rootfs archive with numeric ownership and executable modes preserved.
- A reviewed per-client boot archive with firmware/configuration and `cmdline.txt` already pointing at the exact new NFS root. The tool does not invent kernel filenames or EEPROM configuration.
- An empty machine-id in the root image, no SSH host keys, user SSH credentials, Greenwood signing identities, containerd state or chain data. An empty `/dev` is mounted by the OS; device archive entries are refused.

Place the pinned archives under protected root-owned paths on node-4 using the existing approved file-transfer mechanism. The preparation tool rejects unprotected ancestry, changed checksums, traversal, unsafe links, device files, identity/state material and existing client destinations. It preserves reviewed archive ownership/modes. It creates only a new client's root/boot directories and private evidence/fragments. Failed preparation leaves isolated partial directories for explicit inspection; it never auto-deletes or retries over an existing root.

```sh
ansible-playbook -i /private/production.yml deploy/ansible/playbooks/netboot_clients.yml \
  -e @/private/approved-client-preparation.yml
```

The vars file supplies `greenwood_netboot_prepare_approved: true`, `greenwood_netboot_client` (the declaration), and a new `greenwood_netboot_evidence_dir` under `/var/lib/greenwood-netboot/`. Server identity and existing boot services are checked first. Preparation does not install configuration fragments or reload services.

Review the generated `status.json`, `exports` and `dnsmasq.conf`; verify the state is PREPARED, root path/boot directory match the intended Pi, and no existing DHCP reservation/export/client uses its address, MAC or boot ID. Record SHA-256 hashes of both reviewed fragments. Network-wide collision review is an operator preflight, not inferred from the template.

Only during an explicitly approved maintenance window, use:

```sh
ansible-playbook -i /private/production.yml deploy/ansible/playbooks/activate_netboot_client.yml \
  -e @/private/approved-client-activation.yml
```

Required inputs: `greenwood_netboot_activation_approved`, confirmed recovery access, exact server machine ID, `greenwood_netboot_client_name`, evidence directory, `greenwood_netboot_exports_sha256`, and `greenwood_netboot_dnsmasq_sha256`. This refuses replacement of existing per-client fragments, validates dnsmasq configuration, reloads NFS exports without stopping NFS and restarts dnsmasq to read its new configuration. A DNS/DHCP interruption is possible; this is why activation is a separate maintenance action. Invalid dnsmasq staging removes only the newly introduced fragments before any service reload. If later activation fails, retain the evidence and reconcile explicitly rather than blindly rerunning.

Boot one client at a time. Capture its newly generated identity/fingerprint and verify SSH, sudo, root mount, CPU/RAM, cooling and container-runtime storage before adding it to installation inputs. Initial image creation is not workload qualification or production activation.

## 3. Preflight and fresh install

Run the role-aware read-only production preflight with verified identities and recovery evidence. New profile membership does not waive SSH trust or recovery checks.

```sh
ansible-playbook -i /private/production.yml deploy/ansible/playbooks/production_preflight.yml
ansible-playbook -i /private/production.yml deploy/ansible/playbooks/fresh_cluster.yml --syntax-check
```

`fresh_cluster.yml` is an installation on prepared hosts, not a testnet eraser. It refuses existing protocol state. Removing the disposable testnet still requires the exact reviewed destructive installation procedure and explicit authorization. No old ledger or signing identity is migrated.

For the seven-node layout, `greenwood_preserve_gateway: true` on node-4 skips fresh networking and allows its existing gateway state to remain. Node-4 is not in the seven-node protocol/control-plane installation groups, so its functioning boot/storage services remain outside that installation. This does not silently retire its old application edge or change public traffic routing.

All approved targets pass topology/machine checks before mutations. Shared OCI runtime installation supports infrastructure-only hosts. Protocol identities are provisioned before activation; provider-only startup separately verifies chain/genesis over its configured endpoint. Registry/auth seed services bootstrap from verified offline archives before registry-dependent services. Route publication remains policy-gated.

Moving the application edge requires separately reviewing frontend certificates, provider edge-address/network policy, firewall rules, RPC frontend and tunnel/public routing. Verify the new path before retiring old edge endpoints. Keeping old node-4 services during validation is intentional; the fresh installer is not an automatic public-traffic cutover tool.

## 4. Metering and governed service placement

Do not enable `inventory/metering.ini` to describe DreamBIG's extra hosts as independent observers. Provider collectors stay on providers. Node-5 is eligible for the coordinator and approved supporting services through the existing governed agent.

Set `greenwood_governed_services_prepare: true` only with a complete per-host `greenwood_governed_service_agent_config`, `enabled: false`, matching chain/genesis and protected journal/snapshot or pinned-node settings. `governed_services.yml` stages the existing agent/configuration without starting it. Use existing runtime consent schemas/examples in `system-services/runtime/`; do not add meters to the registry seed allowlist.

To activate approved finalized-policy consent later, provide `greenwood_governed_activation_config`, its exact Ansible `to_nice_json` plus newline SHA-256 as `greenwood_governed_activation_sha256`, and `greenwood_governed_activation_approved: true`:

```sh
ansible-playbook -i /private/production.yml deploy/ansible/playbooks/activate_governed_services.yml \
  -e @/private/approved-governed-consents.yml
```

This only accepts production `verified_snapshot` or `pinned_node` modes, refuses replacement of active consent, saves a private prior configuration and invokes the existing policy-verifying agent. The agent remains responsible for verified finalized policy, consent, immutable images, journals and trust requirements. Installation/start state is not successful resource measurement or physical acceptance. Validate the actual service lifecycle and evidence before enabling payable catalog profiles.

## 5. Acceptance, updates and remaining inputs

`production-acceptance.py` probes the profile's explicit RPC hosts (nodes 1–3 plus the selected RPC edge). Other installation hosts require separate `service_acceptance_sha256` references. The output remains node assertions, not independent certificate replay or complete physical acceptance. Exercise real container execution, resource attribution, settlement, registry authorization, restart/recovery and node-4 outage recovery separately.

`release_runtime.yml` now preflights the artifact for validators, providers, control-plane and system-runtime hosts before stopping consumers. It discovers installed active consumers and restores only those previously active. This is a maintenance rollout, not a claim of zero-downtime consensus upgrades. Keep the artifact/rollback and availability procedure reviewed.

The tooling does not supply unresolved governance ratification, treasury spending authority, production keys, final service consents, private TLS configuration, new hardware identities or installation authorization. These remain operator inputs. Source preparation does not require the future PowerEdge or independent operators for approved Epoch 0 services, and it never converts operator telemetry into independent subsidy evidence.

Runtime package preparation and guarded installation are documented in [Runtime prerequisites](RUNTIME_PREREQUISITES.md). Run its complete-host audit before selecting idle hosts for installation.

The obsolete testnet was removed on November 17; see [the removal checkpoint](TESTNET_REMOVAL_2026-11-17.md) for retained prerequisites and verified clean-state boundaries.

### RPC bootstrap ordering and trust

Fresh installation stages the non-validator runtime, application-hosting settings
and control-plane/TLS files before protocol startup. After validators finalize,
`activate_rpc.yml` starts validator HTTPS origins before non-validator relays and
checks the exact chain/genesis/finality. Provider-only system trust is installed
from `greenwood_rpc_ca_src` with `greenwood_rpc_ca_file_sha256`, then provider
endpoint readiness is checked before any provider start. Internal origin name
resolution is required; Cloudflare public ingress is not a startup dependency.
All operator, protocol and hosting approvals remain required. See the
[RPC DNS/TLS handoff](RPC_DNS_TLS_HANDOFF.md) for Cloudflare and certificate steps.

## Receiver topology and operator certificate handoff

The seven-node assignment profile replicates to nodes 1, 2, 3, 5, 6 and 7;
node-4 is excluded. Every configured receiver must return a fresh matching commit
acknowledgement before an application can start. This adds an availability
dependency on all six receivers; it does not add independent operators.
Controllers, receivers and the edge must use the same complete peer map.
See [certificate handoff](SEVEN_NODE_CERTIFICATE_HANDOFF.md) for exact URI roles,
public-only verification material and the operator-controlled installation order.
