# Greenwood resource metering candidate — 2026-11-14

> Historical snapshot: the findings and commands below describe their dated
> source/infrastructure state, not the current installation procedure. Start with
> [project status](../PROJECT_STATUS.md) and [operator onboarding](README.md).
> Retain the recorded evidence and counts; do not repeat completed initialization,
> removal or credential-generation steps from this snapshot.

**November 15 continuation:** source implementation/publication and replacement of
the existing four-node testnet are now the authorized objective, subject to the
ordered gates in `PRODUCTION_REBUILD.md`. Earlier authorization and missing-source
statements below are historical. Private Unix IPC, governed journal mounts, the
scoped local evidence converter and packaged resource workflow are now implemented.
See `system-services/runtime/RESOURCE_WORKFLOWS.md`. Epoch 0 native customer-funded
settlement is explicit, with no subsidy access; independent preparation never falls
back to it. Actual OCI execution and physical acceptance are still unverified.

Production is HOLD. No permanent genesis, funding, host installation, image push,
wallet access, or operating-cluster change is authorized by these instructions.

The existing native `ServiceSettlement` remains the only service-payment engine.
`ResourceCatalog` v2 represents 36 resource dimensions with exact units. Every entry
in its default template is disabled. `allocation.interval.v1` means reserved
capacity; `cgroup.cpu.v1` means consumed CPU; a memory peak is never an integral.
Per-line GRU rounding precedes aggregate GWD conversion. Protected subsidy controls,
prior-finalized funding and challenge/refund deadlines are unchanged.

`MeteringService` stores provider-origin cgroup observations in private SQLite
FULL/WAL. Begin, periodic checkpoint and finish retain commitment/runtime lineage.
Counter resets, missing prior block devices and changed runtime identities fail
closed. Open segments remain UNVERIFIED after restart. Closed bundles contain
checkpoint count and digest chain. Limits are 100,000 segments, 256 segments per
commitment, and 1,024 checkpoints per segment (one slot reserved for finish).
There is no automatic local retention rollover; close intervals before reaching
limits. Preserve raw evidence and backups; do not delete open segments to recover.

The candidate stdin service is `system-services/metering/meter.php`. Its OCI recipe
requires an approved prebuilt PHP/SQLite runtime and installs nothing. No image has
been built or distributed. A private persistent data mount and narrow host collector
transport still require implementation/review before OCI execution qualification.
Do not grant a public service a root shell, host network, arbitrary host mounts,
or the containerd socket. No host collector has been installed by this work.

`SignedObservation` binds chain/genesis/provider/workload/assignment/commitment,
interval and catalog/manifest hashes to a provider signature. It always says
BOOTSTRAP_OPERATOR_ATTESTED, C and payable=false. It cannot be submitted as an
independent native usage approval. Native usage approvals retain their separate
existing domains and quorum checks.

CPU and cgroup block I/O are local diagnostics; block device counters alone do not
prove storage-volume tenant attribution. GPU/NPU vendor counters, independent
reservation availability, NAS/object operations and workload network attribution
remain unqualified. Missing data is never zero consumption. No remote witness can
infer true consumed CPU solely by re-signing provider-controlled logs.

Provider runtime funding now has a local monotonic stale-head watchdog. Startup
requires observed finalized progress; a stalled head, rollback or conflict denies
continuation. Default local deadline is 180 seconds, configurable through protected
`max_stale_head_seconds` (60–3600). This does not change 60-second target blocks or
convert heights into measured usage. Existing monitor attempts network restriction
before stop and retries uncertain failures. Polling is not a physical hard cutoff.

`SettlementOutbox` holds already signed native actions, pins chain/genesis, and
persists UNCERTAIN before transport. Retrying sends identical bytes with bounded
backoff (64 attempts). Acknowledgement is not payment finality. Reconcile against
a locally certificate-verified `Chain`; conflicting consumed nonces stop for operator
review. Never generate another signature merely because a submission timed out.
The library is not yet wired into an automatic metering/attestor coordinator.

Terminal native records can be compacted with type-13 `archive_v1`, up to 16 records
per action. Each record must have a matching `GREENWOOD_SERVICE_ARCHIVE_V1` digest,
be paid/refunded, and have ended. Finalized block history authenticates the full
record. Native tombstones retain payment/status/digest; used quotes, bindings and
budget counters remain. Do not prune authoritative block history. Admission limits
are 10,000 live commitments, 100,000 lifetime used quotes/tombstones, and 1,000
immutable programs. Exhaustion denies new admission; indefinite accumulator-based
history is not implemented. Treasury quorum recovery is a separate unratified upgrade.

Read-only tooling: `php scripts/resource-operations.php catalog`. Existing signing
commands remain in `scripts/economics-cli.php`. Run local verification with the
installed SQLite extension and Wasmtime paths recorded in the evidence manifest.
