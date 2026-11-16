# Ansible inspection for the proposed seven-node layout

November 16, 2026. Source inspection only. No Ansible plays, infrastructure migrations or network changes were executed. The existing seven-Pi hardware plan remains the target; no PowerEdge or additional qualification hardware is required by these findings. New hosts remain unverified and unassigned.

## Conclusion

Reuse the existing roles and deployment system. Gateway and application-edge work is already separable. The expansion requires a targeted update to fixed topology validation, provider-only provisioning, infrastructure runtime prerequisites and service placement. Editing only an inventory would currently fail or leave new nodes incompletely provisioned.

## Existing boundaries

| Component | What the inspected source actually does | Expansion implication |
|---|---|---|
| `roles/gateway/tasks/main.yml` | Verifies inventory-supplied addresses; does not provision networking or remove services | Keep node-4's address checks. This is not a complete router/netboot installation role. |
| `tasks/fresh_network.yml` | Provisions explicitly approved netplan, dnsmasq, IPv4 forwarding and an isolated NAT table | Keep on the network gateway; do not apply to the application-edge host merely because it runs Apache. |
| `roles/gateway/tasks/application_ingress.yml` | Separate application ingress tasks | Already invoked independently through `application_edges` in `playbooks/application_hosting.yml`; can reuse on a different selected host. No mandatory gateway-role rewrite. |
| `roles/gateway/tasks/teardown_protocol.yml` | Explicitly authorized removal of protocol roles | Remains separate. Never invoke it to move application ingress or merely inspect node-4. |
| `tasks/fresh_edge.yml` | Apache installation, empty fail-closed ingress and a 403 readiness probe | Useful on a newly provisioned edge; it is fresh-install work, not a complete live route-migration procedure. |
| `roles/controller` | Source checkout and canonical release build/package operations | This is the build controller, not the application deployment-controller daemon. Host group selection is distinct from runtime control-plane service selection. |
| `roles/control_plane` | Selects receiver, observer, controller and edge services; stages protected configuration, policy transport, TLS frontends and system-service definitions | Reuse the service-selection mechanism. Review per-service dependencies and runtime installation before assigning a non-provider infrastructure host. |
| `roles/provider` | Worker account, containerd, provider runtime/configuration and hosting support | Contains runtime machinery needed by OCI infrastructure services; avoid giving an infrastructure host customer-provider identity merely to install containerd. |
| `roles/common` | PHP/native dependencies and common directories | Does not by itself provide the complete containerd infrastructure-service host setup. |

## Concrete changes required

### 1. Replace fixed four-host assumptions with an explicit approved topology

Inspected constraints:

- `deploy/terraform/main.tf`, `variables.tf`, `outputs.tf`: four named hosts; providers A/B/C on nodes 1–3; build controller node-1; gateway node-4.
- `deploy/ansible/generate_inventory.php`: requires exactly four nodes and those role placements; emits fixed `control_plane`, `application_providers` and `application_edges` memberships.
- `deploy/ansible/tasks/fresh_preflight.yml`: requires the exact original groups; assigns allowed control-plane composition according to whether the hostname is node-1.
- `deploy/ansible/playbooks/production_preflight.yml`: exactly four production-rebuild hosts.
- `scripts/production-preflight.py`: exactly four hosts, node-1 through node-4, exact matching known-host records.
- `scripts/prepare-production-release.py`: requires the original four named installation hosts.
- `scripts/production-acceptance.py`: requires four distinct installation RPC endpoints and checks all of them as chain endpoints.

Use a shared, versioned topology/role contract with validated host identities and an explicit approved target set. Preserve validator A/B/C and its consensus policy unless separately authorized. Additional providers and infrastructure machines must not create new validators. Preserve duplicate-address/identity rejection, exact SSH fingerprint matching, artifact pinning, and installation authorization. Replace the obsolete fixed count, not the security gates.

Acceptance should distinguish chain/RPC endpoints from boot, registry, meter and provider hosts. Merely changing every `4` to `7` would incorrectly require all service hosts to act as chain endpoints.

### 2. Finish provider-only host provisioning

`roles/provider/templates/provider.json.j2` hard-codes `rpcEndpoint` to `http://127.0.0.1:9201`. A new provider-only Pi will not have that validator endpoint automatically. Make the approved reachable RPC endpoint explicit and validate connectivity/trust according to the supported deployment model. Its CPU/memory fractions also special-case node-1; replace hostname-based capacity assumptions with explicit role/resource reservations.

`playbooks/fresh_cluster.yml` calls `fresh_identity.yml` and starts providers inside plays targeting `validators`. `fresh_identity.yml` copies both validator and provider keys and validates them through the combined validator identity procedure. New provider-only hosts require their own approved provider identity, registration/configuration path and startup/readiness phase, without receiving validator keys.

The initial all-node fresh preflight targets `validators:control_plane`, omitting standalone providers, gateway-only and build-only hosts. Expand preflight coverage to every approved deployment target before any mutations.

### 3. Untangle registry hosting from customer application providers

`playbooks/activate_hosting.yml` imports offline registry/auth images and starts their supervisors on **every `application_providers` host**. `fresh_preflight.yml` likewise requires the registry/auth seeds on those hosts. Adding compute providers to this group would otherwise require registry services on each of them.

Introduce explicit registry/system-service placement, retaining digest-pinned offline bootstrap before registry-dependent services. Preserve the separate registry/auth seed whitelist and network-system-service publishing authorization. Do not weaken the seed whitelist to install arbitrary governed services.

### 4. Make the infrastructure host independently provisionable

Registry units require containerd (`roles/control_plane/templates/system-service.j2`), but containerd installation currently lives in the provider role. Reuse/extract shared runtime installation tasks so an infrastructure host can receive the approved runtime without an unnecessary customer-provider or validator identity.

`fresh_cluster.yml` explicitly installs the canonical release on validators/providers through existing role deployment and on application edges through a separate play. `control_plane` checks that the release already exists. Ensure any control-plane-only infrastructure host receives the release and required runtime before staging services.

`roles/control_plane/templates/service.j2` gives **both observer and controller** a `Requires=greenwood-provider.service` dependency. Provider observers need their provider relationship; review the deployment controller's actual local/remote dependencies before placing it on an infrastructure-only host. Do not simply delete the dependency without validating its configuration and operating path.

The build controller and runtime deployment controller may share a physical machine if deliberately chosen, but they are separate responsibilities. A runtime service move does not automatically require moving source builds.

### 5. Complete placement for governed metering services

The repository has meter/attestor/coordinator OCI recipes and a governed runtime agent. `roles/control_plane/tasks/governed_system_services.yml` stages that agent only with explicit local opt-in, disabled consent and no automatic startup. This is useful existing infrastructure, not an absent metering implementation.

However, there is no dedicated expanded-cluster metering/coordinator host composition in the inspected Ansible playbooks. Define the approved agent placement, service manifests, journal/socket mounts, runtime prerequisites, resource reservations and activation/readiness steps through that governed mechanism. Provider-local collectors remain on the provider machines; the coordinator's host is a separate selection.

`inventory/metering.ini` is intentionally disabled for independently administered observers. Keep it disabled unless independence is actually established. DreamBIG's additional Pis do not qualify as independent attestors. Epoch 0 operator telemetry and governed paid-service eligibility must remain explicitly distinguished.

### 6. Account for existing boot/storage infrastructure

The inspected Ansible YAML does not install or configure NFS exports, per-node rootfs creation or `tftpd-hpa`. `fresh_network.yml` covers netplan/dnsmasq/NAT, not the complete observed node-4 boot/storage stack. Therefore the current Ansible path cannot be described as a complete reproducible rebuild of that stack.

For the near-term expansion, treat node-4's functioning boot/storage services as explicitly preserved infrastructure. Add read-only preflight and controlled per-client netboot provisioning for the new machines after their boot identities and storage paths are confirmed. Reuse verified root-image permissions and worker UID/GID rules; do not clone stale machine IDs or signing identities. Do not apply broad recursive ownership changes to live root exports or container snapshots.

A full node-4 OS rebuild would additionally require boot/NFS provisioning and tested recovery. That is not necessary just to assign the three new Pis, and was not authorized by this inspection.

## Proposed role matrix for implementation review

| Host | Existing/proposed responsibility | Source change needed |
|---|---|---|
| nodes 1–3 | Retain validators A/B/C; existing providers until a separately reviewed placement decision | Keep validator identity policy fixed. Parameterize provider presence and reservations rather than silently removing providers. |
| node-4 | Gateway, DHCP/DNS, boot and NFS; existing edge during transition | Preserve network/boot operation. Move application-edge group membership only through a reviewed route/certificate/policy migration. |
| proposed node-5 | Infrastructure runtime, selected registry/auth/controller/coordinator services; possible application edge | Explicit system-service groups, runtime prerequisites, release staging and corrected dependencies. Exact final service list remains a placement decision. |
| proposed nodes 6–7 | Customer application providers | Provider-only identity/RPC/startup support; separate from registry seed placement. |

This matrix supplies no invented IPs, host keys, machine IDs, credentials or budgets. Netboot remains an acceptable initial constraint with the documented node-4 dependency. No datacenter-grade redundancy or PowerEdge is introduced as a prerequisite.

## Validation performed

- Ran `python3 -m unittest discover -s deploy/ansible/tests -p test_fresh_cluster.py -v`: **5 tests passed** against the current four-node implementation.
- Passed a synthetic seven-host topology to the real inventory generator: rejected with exit code 2 and `ERROR: 'node_topology' value is empty or not an object`. Source inspection confirms the misleading error comes from the exact-four-host check. No real inventory was changed.
- Traced the playbook imports, role tasks, templates, Terraform output and release/preflight/acceptance constraints listed above.
- No live deployment or Ansible execution occurred. Ansible is not installed in this workspace; these results are source inspection and Python/PHP regression checks, not expanded-cluster qualification.

## Implementation boundary

Next implementation should be one coordinated update to the topology schema/generator, shared runtime prerequisites, provider-only bootstrap, service placement, role-aware preflight/acceptance and regression fixtures. Keep the old four-node arrangement as a supported regression case, and add the seven-node arrangement as an explicit configuration. Tests should reject incomplete identities, role conflicts, unqualified attestation, unauthorized activation and registry bootstrap cycles.

No application/runtime redesign is indicated. No service move, provider removal, new governance authority, inventory activation or Git publication was performed. This inspection added documentation only; earlier local permission-role edits remain separate.

## Implementation follow-up

The coordinated source update is described in [the installation guide](SEVEN_NODE_INSTALLATION.md). Further controller inspection confirmed its local authenticated provider dependency; it stays on node-1 in the seven-node profile. Registry/auth, edge and coordinator placement can use node-5 without changing that security boundary. The findings above record the pre-update baseline.
