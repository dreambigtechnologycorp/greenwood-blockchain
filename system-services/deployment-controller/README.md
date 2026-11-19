# Greenwood Deployment Controller

Canonical server source lives in this repository. The separate Python CLI ships no
controller implementation. Deployment writes require a protected, current,
release-scoped authorization and genuine physical dependencies.
Use [control-plane operations](../../deploy/ansible/CONTROL_PLANE_HANDOFF.md)
for installation, protected inputs, activation and recovery.

## Execution path

`public/index.php` → `Bootstrap` → `DeveloperRoutes` → Ed25519 challenge/request
verification → fresh finalized registry authority → SQLite operation/journal →
`DeploymentCoordinator` → provider reservation and approved plan → canonical
assignment distribution → both protected-store acknowledgments → edge content
admission → one provider start and inspect → fresh observer evidence → effective timer generation
→ authenticated readiness service / verified public HTTPS body → READY.

A provider timeout leaves START_UNCERTAIN. Recovery inspects the application and
immutable digest; it never automatically repeats start. Every phase is journaled.
READY is discarded before each new observation and authorization check.

`AssignmentDistributor` journals one canonical generation before network I/O.
`AssignmentEndpoint` connects `AssignmentReceiver` to existing `MutualTlsChannel`
framing, pinned identities, certificate revocation and response challenges. Prepare,
commit and fresh status acknowledgments must match at both nodes. The receivers
check independent authority/allocation approvals, persist their generation ledger,
and atomically fsync private assignment files. A crash after file sync can be
reconciled using the identical generation. Conflicts and older versions cannot
replace newer assignments. Replacement is disabled without a verified withdrawal
callback; timer admission supports only a new version-1 application publication.

A read-only candidate for the existing timer is in `deploy/edge-reconcile-candidate.php`.
It uses protected account-bound admission records for applications without a fixed
application-ID allowlist. It cannot apply proxy writes or physically withdraw routes.
Verification does advance policy rollback ledgers; "read-only" refers to proxy
publication, not to all filesystem state. `deploy/edge-reconcile.php` is the
separate write entry point, disabled unless protected configuration explicitly
enables managed reconciliation. It operates only the dedicated loopback managed
file and preserves unrelated virtual hosts.

The receiver composition requires signed policy snapshots
and a root-owned configuration; staging templates are not rollout acceptance. `Bootstrap` requires
explicit server-owned receiver endpoints and an allocation approval file. The
publisher must be provisioned independently with existing authorized signing keys.
Renewal preserves signed approvals; it does not self-approve allocations. `AllocationPolicy` accepts only
exact canonical generation hashes in an unexpired protected policy, never a
wildcard or caller-supplied provider. This local mechanism is not evidence of an
on-chain provider allocation or a completed physical distribution service.

## Gates and configuration

`Bootstrap` uses `WriteEnablement`; writes default to disabled.
Enabling them requires a boolean flag, a current
scope of at most one hour binding account/provider/application/service/release/digest,
the local authenticated operator socket, distinct pinned receivers and pinned
observer/edge identities. Scope and finalized authority are rechecked during
operation progress. A flag alone is insufficient.
The HTTP entry point accepts a private administrator-owned PHP config path through
`GREENWOOD_CONTROLLER_CONFIG`; missing/unsafe config fails closed. It wires the
router subject to these gates. `GET /live`, `/health` and `/v1/health` prove PHP
liveness; `/ready` and `/v1/ready` currently always return 503 with
`PHYSICAL_ACCEPTANCE_REQUIRED`, independently of operation-level READY.
`/metrics` returns 404. Liveness is not deployment acceptance.
Capabilities do not advertise unavailable deployment features.

The composition expects database path, RPC origin, audience, provider HTTP origin,
private operator token file, protected edge directory, provider policy, TLS material
paths and pinned peer policy, explicit assignment receivers, protected allocation
policy, plus reviewed adapters for scoped pull credentials, expected body hash and
authenticated readiness transport. `pull_credential_file` supplies a protected
release-bound credential adapter. With `edge_remote`, mTLS remote edge supplies
admission/readiness; otherwise local edge composition requires the body-hash and
readiness closures. Missing adapters fail closed. There are no
example provider/observer/edge ports treated as deployed defaults. The observer's
role pins and release-content evidence must match the protected configuration.
Generic reconciliation has no fixed application-ID allowlist. Write enablement
still requires `node-1`/`node-4` receiver roles and an explicit `provider_policy.provider_node` (node-1, node-2, node-3, node-6 or node-7); arbitrary
applications do not imply arbitrary control-plane topology. Do not borrow another
service's identity.

The Ansible role stages loopback controller HTTP, standalone Apache TLS frontends
and private peer services without starting them. Request logs are omitted to avoid
retaining signed queries or credentials. Validate rendered Linux units on their
intended hosts before activation.

## Tests

From the greenwood-php root with its existing Composer dependencies installed:

```sh
php system-services/deployment-controller/tests/orchestration.php
php system-services/deployment-controller/tests/distribution.php
php system-services/deployment-controller/tests/distribution_mtls.php
php system-services/deployment-controller/tests/write_enablement.php
php system-services/deployment-controller/tests/http_gate.php
php system-services/deployment-controller/tests/registry_authority.php
```

The mTLS suite needs OpenSSL and local sockets/pcntl. All certificates, databases and
assignment records are temporary. Authority/provider execution is locally modeled;
no physical deployment or chain transaction is performed. Registry execution tests
in the CLI repository additionally exercise native signed Python envelopes in the
actual PHP/WASM VM. See [testing overview](../../docs/testing/overview.md)
for the separation between source tests and physical acceptance.


### Fresh-chain economic integration (November 14, 2026)

Protected controller configuration now requires `deployment_identity` with explicit
`chain_id`, `genesis_hash`, `registry_contract_id`, and `registry_wasm_hash`. There
are no historical chain defaults. Restart the process to change these pins; the
example configuration deliberately contains invalid placeholders. Signed control
policies and SPIFFE identities must use the configured chain. Registry-auth also
requires `GREENWOOD_GENESIS_HASH` and `GREENWOOD_APP_REGISTRY_WASM_HASH`, alongside
its explicit chain and registry ID.

Hosted starts on native-economic chains deliberately fail with
`HOSTED_SERVICE_SETTLEMENT_NOT_QUALIFIED`: recurring service intervals and a
finalized funding guard are not yet integrated. Do not remove that gate to launch.
The finite-compute adapter and remaining production requirements are described in
`../../docs/economics/GRU_SETTLEMENT_REPORT.md`.


The local hosted CPU candidate adapter is now implemented. Production remains on
HOLD. `hosted_settlement_candidate_enabled` defaults false; the disposable-cluster
composition uses a configured provider key and actual funded-interval reads before
assignment distribution, controller start/resume, provider start/recovery and expiry
reconciliation. See [the hosted report](../../docs/economics/HOSTED_SETTLEMENT_REPORT.md)
for exact configuration and the independent-metering/physical acceptance boundary.
