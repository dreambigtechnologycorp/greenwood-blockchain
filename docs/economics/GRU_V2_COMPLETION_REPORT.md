# GRU v2 — resource accounting implementation report

> **Historical scope — reviewed November 24, 2026.** The original allocation candidates, deployment restrictions and source-gap list below describe this checkpoint, not the current role-based onboarding requirements.
> Read the [economics index](README.md) for current source and policy precedence,
> and [project status](../PROJECT_STATUS.md) for release/installation and qualification.

November 14, 2026. **Local candidate implementation; production HOLD.** No operating cluster was contacted, installed, reset or changed. No permanent genesis, live payment, trusted metering identity or production gate was created/enabled. Both allocations remain unratified; 10/25/30/34/1 remains the preferred modeling candidate. Sixty-second target blocks, six GWD decimals, fixed issuance, emissions and no-burn/operator fees are unchanged.

## Scope delivered

| Stage | Local result | Qualification boundary |
| --- | --- | --- |
| Resource definitions/capabilities | Versioned catalog, 36 resource definitions, typed hardware extension, signed composite offers | Advertisements remain level C; no inferred SBC/GPU/NPU quantities |
| Measurement adapters | Existing cgroup collector extended, CPU/I/O observation framing, durable local CPU segment journal, signed-observation reconciliation library | Local CPU observed as level C; local block-I/O unavailable; no independent witnesses or vendor collectors qualified |
| Unified settlement | Composite compute and hosted profiles share existing native escrow, budgets, attestations, disputes, payments and refunds | Qualification declarations are governance-authenticated policy inputs, not proof of physical truth |
| Physical acceptance | Disabled inventories and concrete requirements recorded | **Blocked:** no disposable qualification hosts or independent metering administrators provisioned |

The source implementation supports universal resource **accounting**, not a claim that all hardware is now metered or executable. NVIDIA/AMD telemetry, NPU vendor counters, tenant-attributed NAS/object/network collectors and hardware/runtime enforcement still require implementation/qualification. No GPU utilization percentage is converted into work or NPU/GPU equivalence.

## Catalog and exact units

`ResourceCatalog` defines CPU reservation/consumption; GPU/NPU/specialized-accelerator reservation; VRAM; RAM; SSD/NVMe/HDD/block/NAS/object storage; read/write/operation counts; ingress/egress/bandwidth; public IP; dedicated nodes; and backup capacity/restore operations. Persistent volumes use the block-capacity definition with explicit persistence metadata.

Quantities are canonical integer atoms:

- Consumed CPU: milliseconds. Reserved CPU: millicore-milliseconds.
- Device reservations: millidevice-milliseconds; explicit model, partition and runtime.
- RAM/VRAM/storage reservation: MiB-milliseconds, distinct from peak observed memory or physical/logical stored bytes.
- Network transfer: bytes; bandwidth reservation: kbps-milliseconds. IOPS-labeled consumption entries count actual operations rather than inventing a utilization charge.
- IP/node duration and backup operations have their own definitions.

A displayed GiB-hour is 1,024 × 3,600,000 MiB-milliseconds. Calendar months and target block time are not measurement clocks. Quotes state an explicit millisecond allowance, at most 31 days; values that overflow checked integer arithmetic are rejected and need smaller billing intervals. Rates are exact rational **GRU atomic units per canonical quantity**, at the quote's explicit `gru_scale`.

`RESOURCE_CATALOG_V2.disabled.json` defines every resource with `enabled=false`. Supported native evidence forms are `cgroup.cpu.v1`, `cgroup.io.v1`, and `allocation.interval.v1`. That means the verifier understands their bounded fields; it does not mean an operating collector or independent physical profile has been qualified. Other measurement forms cannot become payable by changing an enabled flag. Enabled entries require an explicit reviewed policy/qualification hash. Level-C lines cannot draw Institute subsidy.

The Linux observation extension follows the documented cgroup controller interfaces, including per-block-device byte and operation counters. Those counters do not establish tenant NAS/storage attribution. Missing optional controllers remain unavailable, not zero. [Linux cgroup v2 documentation](https://www.kernel.org/doc/html/latest/admin-guide/cgroup-v2.html)

## Hardware registration and scheduling

New envelope type **14** carries a bounded canonical `ProviderCapabilityV2` extension. Its provider/key, genesis, extension nonce and referenced V1 capability nonce are authenticated. The V1 capability/request/assignment/ComputeReceiptV2 byte formats remain frozen. Assets advertise actual supplied identifiers, physical billing/exclusivity groups, class, vendor/model, runtime, partition identity, capacity, supported resources/meters and optional typed storage/accelerator/network attributes. Storage persistence, durability and protocols are explicit declarations; declared VRAM cannot exceed declared device-local memory. These claims remain level C, even when signed.

The existing `Scheduler` and `ReservationLedger` gained typed offer/capacity support; no separate GPU/NPU/storage scheduler was introduced. Native funding independently checks capacity over overlapping height intervals, including partition/shared-device groups. CPU/RAM allocations must be explicit in both runtime profiles, including free/bundled capacity. Dedicated-node reservations hold associated resource groups, including idle capacity.

Resource-mode conversion is explicit. Active legacy obligations/unreleased legacy assignments block conversion; providers advertising a nonempty V2 extension must fund through V2 manifests. Active resource metadata cannot be removed/reidentified and base-capability reductions cannot bypass typed reservations. Draining or changing unused advertisements cannot rewrite an accepted invoice. Legacy-release/migration and hard physical enforcement still need operational acceptance; conversion must not infer that a vanished worker freed resources.

## One accepted quote, one charge

A composite offer binds the exact catalog, manifest, **capability nonce and full capability hash**, provider payout identity, payer, service, validity window and aggregate GRU/GWD ceilings. A provider cannot silently substitute another device/model before funding. Each line fixes its resource, asset/class, meter, capacity, maximum quantity, rational rate, coverage and any included allowance/overage relationship. New charges require a new accepted offer/interval; in-place amendments are not implemented.

Rounding is explicit: ceil each line's billable quantity × rational GRU rate, sum GRU atomic units, then apply the existing exact GWD conversion once. The shared ledger enforces both per-resource ceilings and the aggregate payment ceiling. Existing customer/Institute splits, protected-source refunds, related-beneficiary/program budgets and rolling caps remain authoritative. No new GWD or transferable GRU is created.

Positive-price overlapping coverage is rejected: NAS and backing SSD capacity, a dedicated node and included RAM, a whole accelerator and a fractional overlapping allocation. Included resource lines may have zero price. Explicit overage references name an earlier line and a positive included allowance. Node coverage and network/accelerator umbrella coverage are expanded before overlap checks; physical capacity admission is separate from billing coverage. Declared physical groups still require independent verification to establish they describe real hardware.

## Shared native profiles and evidence

`compute_resources_v2` binds the existing ComputeRequest, prior-finalized funding, ComputeAssign and terminal ComputeReceiptV2. `hosted_resources_v2` binds actual Application Registry WASM authority and the existing committed assignment document/recurring interval. Both use `ServiceSettlement`; all affected balances and counters change in the same cloned consensus transition.

`ResourceUsageEvidence` is versioned canonical JSON, limited to 32 quoted lines and bounded payload framing. It binds chain/genesis, provider, commitment/workload, assignment and billing interval, catalog/manifest, explicit measurement clock, runtime/device identities, source hashes and the evidence bundle. Signatures are outside the signed body in the existing approval list, under `GREENWOOD_RESOURCE_USAGE_V1`.

Every quoted line needs a typed observation. Missing telemetry cannot become zero. Reservation quantities equal accepted units × attested available milliseconds and cannot exceed the observed/authorized duration; consumed fields cannot masquerade as reservations. Compute CPU must agree with its finalized execution receipt. Foreign devices, unaccepted lines, changed rates, missing quantities, over-budget consumption and replayed claims/payments are rejected. RAM peak is not used to calculate reserved RAM charges.

Runtime/source identity and attribution in a signed bundle are assertions that witnesses must independently substantiate. Validators check authorized signatures and deterministic consistency, not a remote kernel or device. The program's qualification hash and level are reviewed governance declarations; the software does not establish real administrative independence from a hash or signature count.

## Metering service and independence

`Provider\MeteringService` is a library in the existing provider/control-plane architecture. It opens no daemon/listener and stores no financial balances. It records level-C samples and immutable CPU counter segments in private SQLite storage. A restart reopens the same journal; it cannot reset an existing segment, close another runtime, reuse an old counter range in a different interval or turn an open segment into zero usage. Runtime counter resets require reconciliation. Local retention is bounded at 100,000 segments and 256 segments per commitment; archival/reconciliation is required beyond those limits.

`ObservationReconciler` verifies separately signed observations under configured source, administrative and infrastructure domains. Duplicate domains, unsupported/provider-supplied sources, missing observers, resets and disagreement outside an explicit tolerance prevent a payable aggregate. It returns a conservative agreed quantity/hash and **does not claim physical independence**. The domain configuration itself must be assessed against actual operators and infrastructure.

The local probe observed CPU and marked I/O unavailable. No independently administered observer hosts exist in the supplied configuration. GPU/NPU vendor telemetry adapters and independent per-resource allocation/availability collectors remain unqualified; the NVIDIA documentation is a reference for future vendor-specific profiles, not evidence of an implemented adapter. [NVIDIA DCGM metrics reference](https://docs.nvidia.com/datacenter/dcgm/latest/reference/dcgm-exporter-metrics.html)

## Interfaces and deployment boundary

- Public reads: `/v1/resources/catalog`, `/v1/resources/providers/{provider_id}`; existing economics/program/service routes expose the signed catalog, manifest, usage and payment state. Provider advertisements are explicitly labeled level C and show whether their V1 base reference is current.
- Signing: `resource-capability-sign` and `resource-usage-sign` extend the existing PHP CLI/helpers. No command broadcasts, generates authority or replaces a wallet. JavaScript/product integration is still incomplete.
- The existing hosted funding guard accepts both hosted profiles. Actual receiver journal checks, controller/provider start/recovery, native escrow and expiry polling are reused. No runtime resource quota/device/volume/network enforcement is silently inferred from a financial manifest. Hard cutoffs, stale-head watchdogs, collector privileges, device isolation and actual cleanup still need physical acceptance.

Per the user's inventory clarification, the 192.168.72.100–103 operating cluster is a **topology reference only**. It was not contacted or used as a disposable environment. The new `deploy/ansible/inventory/qualification.ini` and `metering.ini` contain disabled empty groups; no existing machine or fabricated attestor was inserted. Populate only with explicitly designated disposable hosts and independently administered metering identities. The fresh installer must not run against the operating cluster.

## Validation and remaining work

Current results are recorded in `evidence/resource-v2-tests.json`, `resource-v2-certified-history.json`, and the aggregate `resource-v2-summary.json`. Tests use actual Greenwood source, actual Application Registry WASM, actual signatures and SQLite persistence. Local fixture policy hashes are deliberately **not physical qualification evidence**.

The certified V2 history uses the proposed 10/25/30/34/1 allocation and includes type-14 registration, program authorization/timelock, registry activity, two composite CPU/RAM intervals, attested payment and outage refund. Replaying all 24,487 certified blocks checks the same final state root. This is single-process consensus testing with three validator identities, not physical distributed execution. Earlier reports' original-allocation history remains historical evidence for the earlier CPU milestone.

Remaining HOLD conditions:

1. Provision/designate separate disposable machines and genuinely independent observer administrations; implement/qualify remaining collectors and attribution/reconciliation profiles. No current hardware type has production assurance merely from this implementation.
2. Integrate and physically verify accepted resource manifests with actual runtime quotas, GPU/NPU/device assignments, storage/NAS tenant controls, network limits, trusted timing, stale-head/watchdog cutoffs and restart behavior. Missing hard enforcement must not be advertised as a hard usage guarantee.
3. Complete SDK/product workflows, evidence publication/submission and payment/refund reconciliation on that environment. No automated witness daemon or settlement broadcasting was activated.
4. Implement authenticated native state retention/archival without deleting consumed-offer/replay protection. Native 1,000-program/10,000-commitment admission caps remain.
5. Ratify treasury lost-quorum recovery, validate real operating costs and reserve allocations, finish independent security acceptance and separately authorize permanent genesis. No recovery council, allocation or mainnet activation was adopted here.


### Current local test totals

- Resource definitions, arithmetic, composite offers, hardware substitutions,
  backing/bundle overlap and scheduler admission: **75 checks**.
- Meter journal/crash/reset/reused-counter protection: **11 checks**.
- Native composite compute + hosted settlement, actual registry WASM and SQLite:
  **21 checks per allocation**.
- Existing economics **64**, finite-compute **68**, hosted CPU **32**, and
  certified-chain foundation **25** checks per allocation remain passing.
- Shared hosted boundary **17** and fresh-chain identity **9** checks pass;
  **12** deployment/registry regression suites pass.
- **24,487** certified GRU v2 blocks on the proposed allocation replay to the
  identical final state root (**8 assertions**, approximately 448 seconds).
- **160 PHP syntax checks** and `git diff --check` pass. Both new inventory
  files were checked to contain no hosts, and all **36** default catalog entries
  remain disabled.

Reproduction uses the actual approved local registry build and Wasmtime setup
from `HOSTED_SETTLEMENT_REPORT.md`. Run `resources.php`, `metering.php`, and
`resource-settlement.php` under `tests/Economics`; enable PDO SQLite and FFI where
required. For the full v2 proposed-allocation history, set `GWD_RESOURCE_V2=1`
and `GWD_ALLOCATION_CANDIDATE=10-25-30-34-1` when running
`tests/Economics/hosted-certified-history.php`. There is no external token-price
API, live transaction, physical workload or mock registry in these results.
