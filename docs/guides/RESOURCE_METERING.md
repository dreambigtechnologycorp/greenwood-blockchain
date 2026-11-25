# Resource metering and settlement user guide

This guide describes source interfaces you can build and operate yourself. Nothing
in these commands approves a resource program, provisions production signing keys,
changes an existing cluster, or authorizes treasury spending. All catalog entries
remain disabled by default. Source support is not hardware qualification.

## Customer-funded applications with a closed subsidy reserve

A service program with `subsidy_percent: 0` may set both `budget_units` and
`beneficiary_budget_units` to the decimal string `"0"`. These fields cap **reserve
subsidies**, not customer expenditure. The program can be approved while the
network's subsidy reserve limit is `"0"`. Existing zero-percent programs with
positive unused subsidy budgets remain valid; those numbers do not grant access
to reserves. Positive-percent subsidy programs still require positive program
budgets within the authorized reserve ceiling.

Customer escrow pays the full accepted provider price. Quotes, positive
`max_gru_units`, per-request/hosted payment limits, resource capacity, permitted
beneficiaries, expiry and verification requirements remain enforced. A zero reserve
reservation makes no reserve debit and is unaffected by reserve pauses or existing
subsidy commitments. Program installation still requires governance approval and
its timelock; this is not permissionless registration or automatic activation.
Refunds return unused customer escrow and successful settlement pays the provider.

For your own applications, use an authorized application payer account distinct
from the provider payout identity. For client applications, the client can be the
payer, or your company can sponsor payment from its authorized account. The hosted
assignment must identify that payer, and the Application Registry must grant it
deployment authority for the current release. Paying does not confer ownership or
permission to deploy someone else's application. Registry publishing authorization
and immutable image/release checks still apply.

Ten applications do not require ten treasury subsidy allocations. Each application's
services still need funded intervals and adequate CPU, RAM and storage; renewals
need continuing funds. Program GRU allowances and provider capacity may be shared
across workloads, so application count alone does not establish cluster capacity.
Public access to an application does not make its hosting free: the sponsor pays.
Private client hosting uses the same funding and authorization rules.

DreamBIG's hardware contribution does not automatically credit a token balance.
It needs existing GWD from an explicitly approved bootstrap transfer, earned
validator distributions, customer revenue or another authorized source. Its
protected operations account can transfer approved amounts to ordinary payer
accounts but cannot directly submit service-funding or contract transactions.
Ordinary accounts do not inherit its protected debit caps. Corporate application
funding and client sponsorship must have recorded purposes; a network-operations
budget is not blanket authorization for those costs.

Under an approved Epoch 0 profile, DreamBIG may operate customer-funded services
using `BOOTSTRAP_OPERATOR_ATTESTED` evidence, explicitly disclosed as operator
attestation. This never authorizes an independent-verification subsidy or downgrades
an existing independent-attestation commitment. Governance and Epoch 0 approvals
remain required. The reference network's [November 18 adoption](../economics/PRODUCTION_POLICY_ADOPTION_2026-11-18.md)
records its selected policy terms; it is distinct from on-chain execution,
custody verification and physical acceptance. Other networks need their own
approved program and trust policy.

Keyless regression: `php tests/Economics/customer-funded-programs.php` exercises
policy validation, program governance, reserve checks and restored-fixture
settlement/refunds. It does not generate keys, verify a newly signed funding
transaction, qualify physical meters or activate production.

## Resource coverage and attribution

| Resource family | Source adapter | Meaning and limits |
|---|---|---|
| CPU consumption | `CgroupAccounting`, `LinuxCollectors` | Cumulative task cgroup CPU milliseconds; counter resets invalidate continuity |
| CPU reservation | cgroup `cpu.max` plus accepted allocation | Quota ceiling and operator allocation assertion, not utilization |
| RAM reservation | cgroup `memory.max` or allocator lease | MiB × available milliseconds, not peak RAM consumption |
| SSD/NVMe/HDD/block reads, writes, IOPS | cgroup `io.stat` | Explicit reviewed asset → major:minor and storage-class mapping; no tenant attribution inferred from host totals |
| GPU/NPU/accelerator reservation and VRAM | `AllocationAccounting` | Exact workload/generation/runtime lease and observed readiness; no invented utilization or FLOPS conversion |
| Storage/NAS/object/backup capacity | allocator lease | Allocator-enforced capacity; host filesystem free space is not a tenant reservation |
| Network ingress/egress | `NetworkAccounting` | Exact owned host-veth counters; host RX is workload egress; not Internet-only traffic |
| Bandwidth/public IP/node reservation | allocator lease | Accepted quantity × available milliseconds; whole-node/bundled resource overlap remains checked by native manifests |
| NAS operations and transfer | `ServiceOperationMeter` | Completed, workload-scoped operation/byte receipts from the cooperating service |
| Object requests and egress | operation journal | Completed request/byte receipts; retries must retain the same event ID |
| Backup restore | operation journal | Completed restore operations; pending/ambiguous restores cannot be counted as success |

`php scripts/resource-metering.php inventory` reports standard Linux GPU/accelerator
sysfs presence as diagnostics. It does not allocate a device, discover every vendor
interface, prove an accelerator partition is isolated, or enable a payable meter.
Vendor drivers and storage gateways must supply actual attributed observations to
the adapter boundary. A missing source is `available:false`, not measured zero.

All 36 defined resource types have typed observation/accounting paths. Unsupported
hardware APIs must remain unavailable until their source integration and workload
attribution are qualified. `network.workload.v1`, `nas.workload.v1`,
`object.workload.v1` and `backup.workload.v1` now have source adapters; enabling an
entry still requires an explicit catalog qualification hash and approved program.

## Two metering interfaces

The existing cgroup interface (`MeteringService`) records `bind`, `begin`, periodic
`checkpoint`, `finish`, and `bundle`. It is used by the provider's existing finite
and hosted workload paths. `LocalResourceEvidence` prepares CPU/RAM/block evidence
from an exact closed local bundle.

The universal interface (`ResourceTelemetryJournal`) accepts signed normalized
frames for any catalog family. It integrates consumed counter deltas and reserved
availability, produces the native resource evidence shape, and retains source
provenance. Use it when combining allocator or service-operation observations with
host counters. It is available through the same private meter daemon:

```sh
install -d -m 0700 /private/greenwood-meter
php system-services/metering/meter.php /private/greenwood-meter --unix
```

The directory must belong to the service user. The OCI image runs as UID/GID 65532;
provision its private mount accordingly. Do not expose `meter.sock` over TCP or make
it world-readable. The daemon needs no containerd socket or privileged cgroup mount;
the authorized host collector reads counters and submits bounded frames.

The journal directory contains SQLite databases and immutable `archive/` files.
Use a supported durable filesystem and the exact installed revision's SQLite
policy. Current source uses `DELETE`/`EXTRA`; old WAL databases require
explicit offline conversion before this release starts. Back up the entire
directory consistently, including its `resources/` child, not just one SQLite file. See [retention and recovery](RETENTION_AND_RECOVERY.md).

## Bind exactly one funded interval

Read the current finalized commitment and program through a trusted, pinned node
or locally replayed certified snapshot. Public routes include
`/v1/economics/services/{id}`, `/v1/economics/programs`, and
`/v1/economics/hosted/funding/{assignment_generation}`. A public RPC response alone
does not prove finality; validate its chain/genesis/state pins using your approved
transport and certificate workflow.

Create `scope.json` from that commitment with these exact fields:

```json
{
  "chain": "CHAIN_ID",
  "genesis": "64_LOWERCASE_HEX",
  "provider_id": "64_LOWERCASE_HEX",
  "workload_id": "64_LOWERCASE_HEX",
  "assignment_generation": "64_LOWERCASE_HEX",
  "commitment": "64_LOWERCASE_HEX",
  "interval_start_height": 1,
  "interval_end_height": 2,
  "catalog_hash": "64_LOWERCASE_HEX",
  "manifest_hash": "64_LOWERCASE_HEX"
}
```

The labels above are placeholders, not valid production inputs. Copy the exact
accepted resource manifest into `manifest.json`. Keep every base-unit amount and
counter a decimal string. Obtain the source public key through approved custody.
Choose an explicit maximum sampling gap in milliseconds appropriate to the source:

```sh
php scripts/resource-metering.php telemetry-bind \
  /private/greenwood-meter/resources scope.json manifest.json \
  SOURCE_PUBLIC_KEY MAXIMUM_GAP_MS
```

The same private IPC operation is `resource-bind`, with fields `scope`, `manifest`,
`source_key`, and integer `maximum_gap_ms`. Binding cannot be changed or reused,
including after archival. Source-key rotation requires a new accepted interval.

## Capture and sign frames

For actual task counters:

```sh
php scripts/resource-metering.php sample-task \
  CONTAINERD_NAMESPACE CONTAINER_ID manifest.json block-bindings.json
```

`block-bindings.json` maps each relevant asset ID to
`{"kind":"block|ssd|nvme|hdd","device":"MAJOR:MINOR"}`. The mapping is an explicit
operator assertion backed by your device inventory; the tool does not infer SSD,
NVMe or tenant ownership from a counter. Unknown/missing devices are unavailable.

For hosted network counters, use `sample-network BINDING_JSON RUNTIME_JSON` with the
current healthy binding and running runtime from the existing application operator.
The collector checks workload, PID, namespace incarnation, interface identity and
ownership alias before and after sampling.

For reserved resources use:

```sh
php scripts/resource-metering.php sample-allocation \
  manifest.json scope.json leases.json runtime.json MONOTONIC_MS
```

Each lease has exactly `asset_id`, `resource_id`, decimal-string `units`,
`workload_id`, `assignment_generation`, `runtime_identity`, decimal-string
`start_ms` and `end_ms`. The current runtime observation has `runtime_identity`,
`workload_id`, `assignment_generation`, and boolean `ready`. Obtain those from the
allocator/runtime owner, not customer input. A lease must reflect actual admission
and enforcement; it is not evidence of consumption or independent verification.

Construct a frame with:

- `version: 1` and `scope_hash`: SHA-256 of canonical scope JSON.
- `runtime_identity` and `source_incarnation`: exact 64-hex identities; the source
  incarnation changes when the source loses/reinitializes its counters.
- `sequence`: integer starting at 0, increasing by exactly one.
- `monotonic_ms`: decimal-string monotonic time from the same source clock.
- `measurements`: one entry per manifest line, in manifest order. Each has exactly
  `line_id`, `asset_id`, decimal-string `value`, and boolean `available`.

For consumed resources `value` is a cumulative counter, not a per-sample delta.
For reservations it is the actually assigned units. Strip diagnostic fields such
as `unavailable_reason` before framing, while retaining them in operator logs.
Never replace an unavailable consumed source with `available:true,value:"0"`.

```sh
php scripts/resource-metering.php sign-frame /private/source.key frame.json > signed-frame.json
php scripts/resource-metering.php telemetry-append \
  /private/greenwood-meter/resources COMMITMENT signed-frame.json
```

Signing is a human-operator or explicitly provisioned service action; never return
production private keys or tokens for documentation review. The key is an
explicitly supplied, current-user-owned 0600 Greenwood 96-byte key
file. The command never generates keys. Node clients may use
`signer.signResourceDocument('telemetry', frame)` and send its `body` and
`approval.signature` as `{body,signature}`. The bound source key is checked locally.

Use `telemetry-close` with the final signed frame, then `telemetry-evidence`:

```sh
php scripts/resource-metering.php telemetry-close \
  /private/greenwood-meter/resources COMMITMENT final-signed-frame.json
php scripts/resource-metering.php telemetry-evidence \
  /private/greenwood-meter/resources COMMITMENT > candidate.json
```

At least two frames are required. Gaps above the configured limit, clock regression,
source/runtime changes, missing lines, duplicate sequence, counter reset and quota
excess are rejected. Reserved time is integrated only where both adjacent samples
are available and cover the accepted units. This is explicitly a source assertion
about continuity between samples, not a hardware proof.

If a consumed source is unavailable, the interval remains unverified. Do not rewrite
its history or change source identity to force a payout. Preserve evidence and use
the accepted dispute/refund/reconciliation policy.

## Instrument NAS, object and backup services

Place the operation journal inside the cooperating service's protected boundary.
Use one scope per accepted billing interval. Persist an intent before issuing I/O:

```sh
php scripts/resource-metering.php operation-begin PRIVATE_DIR scope.json EVENT_ID
```

`EVENT_ID` is a stable 64-hex idempotency identity for the operation. `NEW` means the
intent was newly recorded; `PENDING` means the operation may already have executed.
Do not automatically issue the I/O again on `PENDING`. Reconcile using the service's
own request ID and result. `COMPLETE` means the recorded result is already counted.

After obtaining a definitive result:

```sh
php scripts/resource-metering.php operation-complete \
  PRIVATE_DIR scope.json EVENT_ID quantities.json
```

`quantities.json` is a list sorted by `asset_id:resource_id`, with each entry exactly
`asset_id`, `resource_id`, decimal-string `quantity`. Record actual transferred
bytes/completed operations. Empty quantities are allowed only when reconciliation
establishes that no billable operation occurred. Completion is atomic and identical
retries are idempotent; conflicting retries fail. Unfinished intents prevent snapshot
export, so a crash after I/O cannot silently become zero usage.

`operation-snapshot PRIVATE_DIR scope.json manifest.json` returns cumulative
measurements and the persistent source incarnation. Combine them into signed
telemetry frames. Receipts are operator-origin facts; independently qualified
subsidies still need separate source and attestor authorization.

## Claim, settle and inspect

A telemetry signature is not a payment approval. `candidate.json` is unsigned
native usage evidence. Use the verified-snapshot workflow described in
[RESOURCE_WORKFLOWS.md](../../system-services/runtime/RESOURCE_WORKFLOWS.md):

1. Verify exact commitment/manifest/generation and complete source provenance.
2. Obtain the accepted native resource-usage approvals.
3. Call `prepare-bootstrap` for an explicitly accepted E0 profile, or
   `prepare-independent` for an independent profile. No automatic downgrade exists.
4. Sign the returned service action with the authorized provider account and current
   nonce; enqueue exact signed bytes through `scripts/resource-operations.php`.
5. Reconcile certified inclusion. RPC acknowledgement is not finality.
6. Observe the challenge window, then submit `settle` or the applicable dispute/
   refund action. Repeated settlement remains rejected by the native ledger.

Epoch 0 operator-attested claims have zero subsidy authority. Qualified independent
claims retain finalized source/operator/attestor policies. Runtime readiness,
collector status and an outbox ACK never establish financial settlement.

For concrete storage operations, host-wide admission, device-assignment checks and
the existing provider-loop connection, see
[source adapter integration](SOURCE_ADAPTER_INTEGRATION.md).
