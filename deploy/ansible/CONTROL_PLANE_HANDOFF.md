# Control Plane Operations

This guide describes the current source-controlled installation process.
Staging, activation, deployment authorization and physical acceptance are
separate operations. Review the intended hosts and scope before each operation.

## Entry Points and Inventory

Use [deployment entrypoints](../README.md) to choose the correct playbook.
control_plane.yml selects the control_plane inventory group, runs with privilege,
and stages without activation. Provide an already installed approved release and
the PHP/runtime prerequisites; staging does not build a release or provision the
provider runtime.

| Input | Requirement |
| --- | --- |
| greenwood_hosting_release | Existing root-owned release under /opt, including vendor dependencies; ancestry must reject non-root writes |
| greenwood_control_services | Explicit subset of receiver, observer, controller, edge per host |
| greenwood_control_configs | Service-keyed data-only maps matching each service composition; deployment_enabled and managed_reconciliation_enabled false |
| greenwood_control_directories | Protected root-owned directories under /etc/greenwood or /var/lib/greenwood-control-plane, including required parents |
| greenwood_control_files | External protected src/dest files; copied root:root 0600, no-log/no-diff, without overwriting existing files |
| greenwood_control_environment | Explicit service environment mapping |
| greenwood_control_packages | Distribution-appropriate staging packages; names alone are not version/provenance pins |
| greenwood_system_services | Approved registry/issuer definitions, immutable imageRef ending in @expectedDigest, commands and storage mounts |

Existing configuration drift fails instead of silently replacing authorization.
force: false preserves files but does not attest their content; compare approved
fingerprints independently. Never commit private keys, passwords or policy
signing material to inventory.

The enabled controller composition currently requires node-1/node-4 receiver
roles, provider/node-1 and unix:///run/greenwood-provider/operator.sock.
Addresses are configurable; this is not arbitrary topology support.

## Runtime and Seed Artifacts

Supply containerd_version, containerd_sha256, runc_version, runc_sha256,
greenwood_containerd_config and an exact erofs_utils_version. Version defaults
are empty and provider preflight rejects missing pins. Retain approved package
repository/snapshot provenance and verify target architecture availability.
Use PHP 8.5.12 with required extensions and the compatible approved Wasmtime library.
Ansible installs the 8.5 ABI package names at version `8.5.12*` (allowing vendor
package revisions), then verifies `/usr/bin/php`. The configured APT repository
must supply those ARM64 packages; no fallback to an older runtime is permitted.
The registry issuer image uses `php:8.5.12-cli`; record its resolved digest when
building the release. Source changes alone do not patch running hosts or images.
Schedule process restarts after host upgrades and rebuild/redeploy the issuer
image; inspect the runtime inside every running PHP service before closing rollout.
See [Linux requirements](../../docs/linux-execution-manifest.md).

Seed registry and issuer images must already exist in the provider's greenwood
containerd namespace. Staging does not fetch, import or build them. Provision
approved archives through the artifact channel, verify archive SHA-256, immutable
OCI digest and ARM64 platform separately, then import with the approved snapshotter.
Verify commands and mounts against greenwood_system_services before supervisor
activation. The registry must not depend on fetching its own missing seed image.

Pin the source commit, runtime submodule, Composer lock and native artifacts in
the installation manifest. A source commit does not pin external OS packages.

## Trust and Signed Policies

Externally provision the authorized CA chain, dedicated leaf certificates,
private keys and exact receiver/distributor/observer/admission peer pins. Verify
CA key control before issuance. Do not generate substitute trust to pass staging.

Use separate Ed25519 revocation and allocation keys, pinned public keys, current
signed snapshots and persistent rollback ledgers. Allocation approval binds the
exact canonical assignment SHA-256 generation; assignment version is a separate
monotonic integer. Finalized chain authority is rechecked independently.

greenwood_policy_mode is empty (disabled), renew (publisher) or pull (consumer).
The role stages a 30-second timer without enabling it:

| Mode | Required greenwood_policy_environment entries |
| --- | --- |
| renew | GREENWOOD_POLICY_PUBLISHER_DIRECTORY, GREENWOOD_POLICY_PUBLIC_DIRECTORY |
| pull | GREENWOOD_POLICY_TRANSPORT_DIRECTORY, GREENWOOD_POLICY_STAGING_DIRECTORY, GREENWOOD_POLICY_SSH_PEER, GREENWOOD_POLICY_SFTP_PORT |

The receiver configuration supplies policy_publisher_directory and
publisher_validity_seconds. The timer requires at least 90 seconds, within both
policy maximum ages. Publisher state must contain existing revocations.key,
allocations.key and their signed JSON snapshots. Renewal preserves approvals.
Explicit --approve-assignment requires a protected export, its exact generation
and finalized authority; renewal does not grant new capacity.

Consumers need id_ed25519 and known_hosts in the protected transport directory.
Pin the dedicated publisher [address]:port host key. Batch SFTP uses strict host
checking and identity-only authentication.

## Dedicated SFTP Endpoint

Optional greenwood_policy_sftp requires user (gw-policy-...), explicit IPv4
address, integer port 1024..65535, existing protected host_key under /etc/greenwood,
and approved bare Ed25519 client_public_key. Provision keys externally; staging
does not generate them. Reserve a distinct port and restrict the firewall to
authorized consumers.

The standalone service uses a password-locked nologin account, read-only internal
SFTP and a root-owned 0755 public-bundle chroot containing only bundle.json.
Private parents, signer keys and ledgers remain protected. Root login, passwords,
PTYs, arbitrary commands and forwarding are prohibited. Administrative SSH is
unchanged. Account home/shell mismatches fail. Package installation suppresses
automatic daemon startup.

Before activation, run sshd -t -f /etc/greenwood/control-plane/policy-sftp.conf.
Prove authorized retrieval and rejection of unknown keys, writes, commands and
forwarding on the target host. Check PAM permits the intended locked-account
public-key authentication; do not enable passwords to bypass a failure.

Policy verification updates SQLite rollback ledgers, including observation-only
edge checks. Do not restore older ledgers to accept stale snapshots.

## HTTPS and Registry

Each greenwood_https_frontends entry supplies name (controller, registry,
registry-auth or rpc), address, port, tls_name, loopback HTTP backend,
request_limit, certificate, private_key, ca and ca_sha256.
Ports/request limits must be YAML integers. Distinct listener/name validation,
root-owned ancestry, key permissions, certificate/key match, CA fingerprint,
chain validity, expiry and hostname/IP checks precede activation.

Standalone Apache units configtest before startup and omit request logs that
could retain signed query strings. Keep backends private and preserve unrelated
sites. Verify Zot bearer realm matches the issuer token endpoint and issuer
signing public key matches Zot's verification certificate. Preserve storage and
repositories; TLS alone does not establish scoped push/pull authorization.

## Activation

1. Verify release/runtime identities, images, volumes, addresses and firewall
   policy. Inspect effective systemd units/drop-ins; do not start duplicate operators.
2. Stage configuration and trust. Validate signed policies and persistent ledgers.
   Accept the SFTP endpoint, then activate renewal/distribution and prove expiry,
   recovery and rollback rejection.
3. Run systemd-analyze verify for staged units and apache2 -t -f
   /etc/greenwood/control-plane/NAME-https.conf for each frontend on Linux.
4. Start containerd/provider and the required receivers, observer, edge admission,
   controller and HTTPS frontends in dependency order.
   Install and enable the [application recovery timer](../application-recovery/README.md)
   on application providers; fresh_cluster.yml installs it automatically, while
   standalone control_plane.yml does not stage it. Containerd
   startup alone does not restore application tasks after reboot.
5. Confirm authenticated developer access, finalized owner/release authority,
   scoped registry credentials, exact-generation receiver ACKs and independent
   release-bound content evidence. Negative cases must fail closed.
6. Enable deployment only through WriteEnablement's protected bounded scope and
   explicit operation authorization. A boolean change alone is insufficient.
7. Enable managed reconciliation only after validating its dedicated file/listener,
   proxy source, public domain and admission. Configtest and verify withdrawal
   before treating the edge timer as operational.

application_hosting.yml starts the forwarding service and ensures containerd is
started, but does not restart a running provider to adopt its drop-in. Inspect and
schedule that restart explicitly when needed. Its application_edges stage requires
greenwood_ingress_assignment_verified plus the exact application hostname, backend,
source and port; it can publish and reload a route. This is distinct from staging
and from managed edge timer reconciliation.

READY requires the actual task, digest, PID, namespace, acknowledged assignment,
effective route and approved HTML/assets to agree. Global controller /ready is
currently 503; use authenticated operation readiness, not process liveness.

## Validation and Recovery

Source-only commands from the repository root:

```sh
ANSIBLE_LOCAL_TEMP=/tmp/greenwood-ansible-local python3 -m unittest discover -s deploy/ansible/tests
ANSIBLE_LOCAL_TEMP=/tmp/greenwood-ansible-local ANSIBLE_CONFIG=deploy/ansible/ansible.cfg ansible-playbook -i localhost, deploy/ansible/playbooks/control_plane.yml --syntax-check
php system-services/deployment-controller/tests/orchestration.php
php system-services/deployment-controller/tests/signed_policy.php
php system-services/deployment-controller/tests/policy_install.php
php system-services/deployment-controller/tests/allocation_approval.php
php system-services/deployment-controller/tests/edge_admission.php
php system-services/deployment-controller/tests/release_content.php
```

Some local tests require loopback sockets. Syntax tests do not verify installation.
Before acceptance, validate Linux units, Apache, SFTP/PAM, runtime plugins, firewall,
two-pass idempotency and end-to-end behavior on the intended profile. Do not use
--diff with secrets. Ansible check mode can run explicit validation commands.

Preserve operation IDs and START_UNCERTAIN records. Inspect runtime identity before
retrying; never blindly repeat a start or transaction submission. Preserve policy,
assignment and route journals, signing state and registry storage. Configuration
drift requires explicit review, not deleting protected files. Failed Apache reload
requires inspection of effective routes; it is not successful withdrawal.

See [operational troubleshooting](../../docs/provider-operations.md#troubleshooting)
and [regression tests](../../docs/testing/overview.md). Physical acceptance records
and environment-specific verification scripts are local-only in `docs/acceptance/`.
