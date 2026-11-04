# Current Source Functionality

This page describes the current checkout. Implemented functionality is not
proof of production readiness, live service configuration or fresh-node acceptance.
The [control-plane guide](../deploy/ansible/CONTROL_PLANE_HANDOFF.md) defines
installation inputs, activation prerequisites and acceptance requirements.

## Two Workload Paths

**Compute jobs:** finalized L1 compute requests and assignments are consumed by
the provider supervisor; execution is off-chain and receipts are canonical chain
transactions. See [compute v1](GREENWOOD_COMPUTE_V1.md).

**Hosted applications:** Application Registry ownership, permissions and release
state are finalized on-chain. The controller additionally requires protected
execution scope and signed infrastructure allocation for an exact physical
assignment generation. Receiver ACKs, container observations, edge admission and
public content checks are off-chain evidence, not additional consensus votes.
Do not describe hosted deployment as an on-chain compute assignment transaction.

## Implemented Components

| Component | Current source behavior | Boundary |
| --- | --- | --- |
| L1 consensus | Genesis-fixed equal-power validators; certificate quorum `intdiv(2*n,3)+1`, minimum 3 | Three-validator physical profile needs 3-of-3; four-validator local fixture needs 3-of-4 |
| PHP `bin/greenwood` | RPC and private operator client | Not the Python developer CLI |
| Python CLI v0.3 | Encrypted keys, finalized registration/release workflow, scoped OCI auth, build/push, signed deploy/status/resume and durable journals | Separate repository; owner unlock remains interactive; no automatic uncertain transaction resubmission |
| Registry issuer | Ed25519 challenge proof, finalized application permission checks, short-lived scoped sessions and bearer tokens | Existing issuer identity is externally provisioned; Zot realm/key alignment remains an installation check |
| Controller | Durable operation state, provider reservation, exact assignment ACKs before start, uncertain-start inspection, fresh readiness | Writes default off; protected bounded scope plus real physical adapters required |
| Assignment services | Pinned mTLS identities/roles, signed revocations and allocations, rollback ledgers, atomic durable prepare/commit/status | Unknown, expired, conflicting or unacknowledged generations fail closed |
| Provider | Authenticated reservation/start/inspection and verified retirement/replacement; container/network identity checks | Private operator surface, not developer access; no duplicate start on uncertainty |
| Edge | Generic application routes, release-bound body/assets, provider identity/PID/namespace/digest verification, atomic Apache apply and durable journal | Separate managed listener/file; existing explicit marketing route is not automatically migrated |
| Ansible | Protected staging, runtime-pin gates, isolated read-only policy SFTP, HTTPS units, timers and maintenance separation | No automatic control-plane activation; fresh-node installation remains unverified |

## Controller Authorization and Recovery

`Bootstrap.php` composes `PhysicalAssignmentServices`, `WriteEnablement`,
`PhysicalServices`, `DeploymentCoordinator` and signed developer routes.
`deployment_enabled` defaults false. When true, the scope binds the account,
provider, chain, registry, application, service, release and image digest, with a
maximum one-hour validity window. The enabled profile also requires the local
authenticated operator socket and pinned physical receiver/observer/edge roles.
It is not enabled by possession of an arbitrary administrator token.

Reservation creates an exportable assignment before allocation approval or network
distribution. The distributor must acknowledge the same canonical generation at
both receivers. Edge admission precedes the single provider start. Start intent
is persisted as `START_UNCERTAIN` before the side effect; recovery inspects actual
runtime identity rather than repeating start. READY is rechecked against authority,
runtime, effective edge generation and public content, not cached indefinitely.

The current composition retains fixed `node-1` and `node-4` receiver roles and a
`provider/node-1` role. Inventory can supply addresses and identities, but arbitrary
application admission is not a claim of arbitrary receiver topology support.
Current timer admission supports initial version-1 publication; replacement still
requires verified withdrawal support. Do not infer general rolling updates.

Process `/health`, `/v1/health` and `/live` are liveness only. Controller `/ready`
and `/v1/ready` are presently hardcoded 503, even when an individual operation can
reach READY. Developer capabilities report configuration-dependent enablement.

## Application Restart Recovery

The [recovery timer](../deploy/application-recovery/README.md) restores missing or
stopped tasks for provider records confirmed RUNNING, using retained container,
digest, snapshot, lease and network binding. It refreshes the observed PID and
networking under the per-application lock. It does not replay uncertain initial
starts, intentionally stopped applications, image pulls or chain transactions.
Install it explicitly on application providers; Ansible control-plane staging
does not currently provision it. Verify actual reboot recovery on the intended
host before claiming reboot acceptance.

## Policy and Content Evidence

Assignment `generation` is SHA-256 of the canonical assignment document;
`version` is its monotonic revision. Signed policy snapshots have their own
monotonic revision and expiration. Separate revocation/allocation keys are required.
Renewal preserves existing approvals; it does not self-approve a deployment.
Consumer verification advances persistent rollback ledgers.

The new-node transport is a separate, public-key-only, read-only SFTP server,
chrooted to the public bundle directory. It does not expose signing keys or enable
administrative SSH. Consumer host-key pinning and `PolicyInstaller` verification
remain mandatory; use the matching batch-SFTP consumer and publisher endpoint.

Readiness content comes from independently approved release-bound evidence,
including supported normalized HTML/asset checks. The OCI digest is not an HTML
hash. A live response cannot approve itself merely by being hashed.

`edge-reconcile-candidate.php` forbids proxy publication but can mutate policy
verification ledgers. `edge-reconcile.php` requires explicit managed enablement;
failed observations withdraw candidates through the atomic adapter. A failed
Apache reload is an unresolved physical state, not successful withdrawal evidence.

## Documentation and Evidence Rules

Current guides describe source behavior and supported operating processes.
Evidence does not grant authority or override protected configuration and code.
Vendor/containerd documentation is third-party reference, not Greenwood operating
instructions. Normative protocol changes require compatibility review.

The separate blockchain and marketing repositories remain inaccessible under
macOS permissions. Their documentation is not audited by this checkout review.
Full CLI discovery, Linux SFTP/PAM and new rendered unit/Apache acceptance, runtime
artifact provenance and fresh-node two-pass idempotency remain separately tracked.

## Source Anchors

- [Certificate quorum](../src/Consensus/Messages/Certificate.php)
- [Controller bootstrap](../system-services/deployment-controller/src/Bootstrap.php)
- [Write enablement](../system-services/deployment-controller/src/Deployment/WriteEnablement.php)
- [Coordinator](../system-services/deployment-controller/src/Deployment/DeploymentCoordinator.php)
- [Assignment composition](../system-services/deployment-controller/src/Assignment/PhysicalAssignmentServices.php)
- [Controller HTTP entrypoint](../system-services/deployment-controller/public/index.php)
- [Registry authorization entrypoint](../system-services/registry-auth/public/index.php)
- [Provider entrypoint](../bin/greenwood-provider)
- [Managed edge entrypoint](../system-services/deployment-controller/deploy/edge-reconcile.php)
- [Ansible control-plane role](../deploy/ansible/roles/control_plane/tasks/main.yml)

## Documentation Audit Scope

The current operating reference set covers getting started, configuration,
provider/controller operations, troubleshooting, Ansible provisioning and testing.
The documentation hub links that set. Evidence records are not operating guides.
Local Markdown file links and section anchors have been checked across the
first-party PHP and CLI documentation sets. This does not validate external URLs or establish the
correctness of unreviewed third-party specifications. The inaccessible repository
review remains outside this operating-guide reconciliation.
