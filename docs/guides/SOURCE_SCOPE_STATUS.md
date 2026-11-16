# Source scope status — November 16, 2026

This maps the local implementation to the original
[WP0–WP10 plan](../economics/proposals/GREENWOOD_CODEX_IMPLEMENTATION_PLAN_2026-11-14.md).
The plan defines a development milestone separately from production qualification.
The user subsequently designated the existing four machines as the production
installation target; no second cluster or preservation of testnet state is required.

## Work-package map

| WP | Local source and verification | External qualification |
|---|---|---|
| 0 — audit | Existing native ledger/provider/runtime preserved; current source hashes and commands in local verification reports | Independent review remains separate |
| 1 — resources | `ResourceCatalog`, `ProviderCapabilityV2`, `ResourceManifest`; 36 typed definitions, explicit unsupported/disabled defaults, exact rational pricing | Actual model/driver/profile qualification |
| 2 — settlement | Existing `ServiceSettlement`, `ResourceQuote`, finite and hosted adapters; funded execution, attestation, disputes, payout and refund regressions | Live funded physical workload |
| 3 — metering | `CgroupAccounting`, `NetworkAccounting`, `DeviceReservationCollector`, `ResourceHostCollector`, `ResourceAllocationJournal`, `MeteredStorageAdapter`, `ResourceCollectionSession`; existing `HostedMeteringMonitor` hook; actual local storage-to-native-payment test | Actual containerd devices, mounted NAS semantics and source qualification |
| 4 — system services | Existing governed watcher/agent, installation journal, meter/attestor/coordinator OCI recipes and private IPC, explicit consent and rollback | Reviewed runtime images and actual install/update/rollback |
| 5 — trust | Existing `AttestationTrust`, `QualifiedSourceAttestor`, `ResourceSettlementWorkflow`; explicit Epoch 0, qualified independent domains, no subsidy or independence bypass | Independent source control and custody |
| 6 — epochs | Existing operator registry, sustained E0–E2 participation and delayed policy transitions; no change to genesis-fixed validators | Ratified thresholds and actual independent participation; E3 consensus membership remains a separately specified upgrade |
| 7 — incentives | Existing `InfrastructureCostModel`, protected budgets/programs and regression comparisons | Measured costs and approved compensation |
| 8 — SDK/UI | Local JS signing/disclosure SDK, PHP CLIs, outbox/coordinator, bounded native RPC resource projection, existing Studio inspector with units, limits, basis, source digest and trust | Browser/full-app and live transport qualification |
| 9 — recovery | Configurable treasury custody recovery, immutable evidence archives, hot-row rollover, replay guards, stale-head and interval checks | Soak, hardware faults, custody ratification and independent security review |
| 10 — acceptance | Existing native runtime/lifecycle/resource-enforcement tests, preflight scripts, release tooling and source verification runner | Physical acceptance and explicit destructive installation authorization remain pending |

## Concrete source additions in the final integration

- `ResourceAllocationJournal`: host-wide serialized reservation admission, overlap
  accounting, exclusive assets, immutable inventory, boot/runtime binding and
  retirement tombstones. Existing containerd provisioning remains responsible for
  applying approved limits and assignments.
- `MeteredStorageAdapter`: actual bounded tenant file writes/reads/deletes/restores,
  immutable object identities, logical quota, durable operation intent, actual byte
  and operation counters, safe recurring interval reuse and no automatic uncertain
  side-effect retry. Authentication stays in the existing service boundary.
- `ResourceHostCollector` and `DeviceReservationCollector`: exact PID/task cgroup
  identity, existing owned-veth source, reviewed driver/version and task-visible
  character-device checks. Unsupported vendor interfaces remain unavailable.
- `ResourceCollectionSession`: accepted funded manifest, persistent sequence,
  source incarnation, actual storage counters, allocation/cgroup/device observations
  and source signatures. Its hosted factory uses the existing funding guard; the
  existing provider monitor invokes an explicitly supplied collector factory.
- `ServicePresentation::resources` and Studio's existing inspector: exact resource
  quantities and limits with reservation/consumption basis, units and source digest.
  A pinned node assertion is still labeled as such, not local certificate replay.

No additional native monetary protocol or frozen compute-wire encoding was added.
The source-frame domain remains `GREENWOOD_RESOURCE_TELEMETRY_V1`. Storage binding,
operation receipts and allocation inventory are local versioned source state, not
new consensus authorities. Source signatures do not prove honest physical hosts.

## Validation

- Expanded resource regression: **56 suites and 271 syntax checks passed**, recorded
  in `build/source-integration-final/verification.json`.
- Final adapter integration: **24 checks passed**, including actual local file I/O,
  native resource projection, existing provider-loop invocation, overcommit,
  exclusive reservation, restart, reboot, quota, cross-tenant and unknown-device cases.
- Actual storage-to-native settlement: **24 checks passed**, including exact payout,
  refund, challenge delay, replay rejection and zero protected subsidy spending.
- Full PHPUnit source/contracts: **607 tests, 6,129 assertions, no failures**;
  one existing AppleDouble runner warning, 20 deprecations and two skips. The first
  sandboxed attempt could not bind test sockets; the unrestricted local rerun passed.
- Studio: **9 PHP read-adapter checks and 20 JavaScript projection checks passed**.
  Browser rendering and live finality were not tested.
- SDK: **17 resource-client checks passed**, including PHP parity for displayed
  refund/challenge policy; existing 32 signing checks passed in the broad runner.
- Earlier certified-history run: **24,488 blocks passed**. It preceded these
  collector/UI-only integrations and is not represented as a newly repeated run.

The final focused machine-readable record is
`docs/economics/evidence/source-integration-2026-11-16/focused.json`.

Focused checks after the broad run cover the subsequent provider-hook and display
changes. The tests use simulated runtime/device identities where stated; the
storage operations themselves use real local files. No independent physical
qualification or production execution is claimed.

## Supported boundaries

The original plan explicitly permits unsupported hardware to remain disabled and
makes GPU/NPU utilization optional. Generic reservation, real Linux counters and
file-backed storage adapters are implemented. Unqualified vendor-specific
partitions/utilization, remote S3/NFS implementations, replication durability and
physical independence are not synthesized from JSON, model names or signatures.
These need deployment-specific qualification or a separately selected adapter.
The supplied local storage adapter is executable source, not just a receipt schema.

Source integration and operator configuration are documented in
[adapter integration](SOURCE_ADAPTER_INTEGRATION.md). Review and package the local
changes using [self-managed updates](SELF_MANAGED_UPDATE.md). No commits, pushes,
new PRs, production keys or infrastructure changes were performed in this work.
