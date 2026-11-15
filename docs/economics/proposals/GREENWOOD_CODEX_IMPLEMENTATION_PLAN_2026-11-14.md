# Greenwood Universal Resource Infrastructure & Progressive Decentralization
## Codex Implementation Plan — 14 November 2026

**Classification:** Engineering execution plan; candidate specification; not governance ratification.
**Status:** Source-development authorized in a local/disposable environment. **Permanent genesis / production rollout: HOLD.**
**Scope:** Greenwood L1 native economics, provider and application-hosting infrastructure, GRU resource catalog, meter/attestor system services, genesis bootstrap, progressive decentralization, UX/SDK, and qualification.
**Primary principle:** *The network defines and enforces eligibility and settlement rules; individual operators own and operate physical machines. Approved system services are distributable by the network but cannot force themselves onto unwilling hosts.*

---

## 0. Codex execution directive

Implement this plan **in the current local Greenwood working tree**, incrementally. First inspect `git status`, the current branch, changed files, the latest local economics interfaces, and the exact versioned contracts. The published GitHub default branch may be older than the local candidate. Do not overwrite, reset, regenerate, or silently reconcile existing uncommitted work.

**Do not** create a replacement blockchain, scheduler, marketplace, provider daemon, receipt protocol, currency, monetary ledger, reserve wallet, or token. Extend the current code. Preserve existing signed wire formats unless a separately versioned protocol upgrade is explicitly required.

For each work package: (1) inspect actual call sites and test fixtures; (2) write/update a short protocol note; (3) implement the smallest composable slice; (4) add positive and adversarial tests; (5) run focused then regression suites; (6) emit machine-readable evidence with exact commands and environment; (7) update the status matrix below. Work from highest-priority local milestones toward physical qualification. An absent external witness or disposable host is **not** grounds to halt local implementation; classify that gate `PENDING_PHYSICAL` and continue the next local work package. Never invent host IPs, physical measurements, independent operators, or passing test counts.

**Hard stop:** No permanent genesis, chain activation, unapproved treasury action, production service flag, public wallet or mainnet payment, real cluster reset, service restart on an operating host, artifact push, source push, or key generation on another person's behalf. Do not touch protected keys/seeds. Production trust policies, operator thresholds, budgets, and allocations require ratification, not inference from this plan.

### Completion definition

A complete **development milestone** means: universal catalog and quote interfaces, all supported collector adapters and explicit unsupported statuses, evidence binding, system-service distribution architecture, bootstrap trust profile, operator/epoch policy, integrated finite/hosted settlement, SDK and operational submission paths, deterministic replay, fail-closed controls, and adversarial tests are implemented locally. A complete **production qualification** additionally requires externally verifiable independent control where claimed, qualified physical measurements, real providers and witnesses, distributed persistence/finality, operational security reviews, ratified policy, and explicit activation authorization. **Do not collapse these definitions.**

---

## 1. Baseline: preserve the work already completed

Source of record: user-supplied **“Hosted recurring settlement — implementation report,” November 14, 2026**, archived locally as `proposals/GRU_IMPLEMENTATION_REVIEW_2026-11-14.md`; inspect the actual local classes and `evidence/` before modifying them.

### 1.1 Reported implemented candidate (do not recreate)

- Native six-decimal fixed-supply **1,000,000,000 GWD**, no burn, existing operator transaction fees, validator emission schedule and 60-second target block cadence.
- GRU is **accounting**, not an independently transferable currency; GWD is the payment asset.
- Type-13 signed financial envelope, versioned canonical actions and native `ServiceSettlement` owned by the ledger; state-root commitment, SQLite persistence, deterministic application and replay.
- Approved immutable programs, catalog binding, provider-signed offers, single-use acceptance, max-payment, account/subsidy escrow and budgets, provider payment, customer refunds, unused-subsidy restoration, dispute windows and challenge-specific resolution.
- Finite compute path: `ComputeRequest -> quote/program -> escrow -> prior-finalized-block funded ComputeAssign -> ComputeReceiptV2 -> usage attestations -> challenge -> GWD payout/refunds`.
- Hosted path: **actual pinned Application Registry WASM**, full approved assignment document, provider-signed recurring interval, funding guard in controller/operator/recovery, overlap prohibition, receiver **COMMITTED** assignment journal, hosted monitor and explicit signed settlement.
- Hosted catalog currently scoped to `hosted_cpu_v1`; finite CPU adapter `compute_cpu_v1`. Operational independent metering is not yet demonstrated.
- Financial status remains distinct from execution readiness and provider job-journal `SETTLED` (meaning *receipt observed finalized*, not payment).
- Historical chain identity removals, explicit chain+genesis pinning and guarded candidate-only hosted deployment enablement.
- Published report: per allocation **64 core, 68 finite compute, 32 hosted, 25 certified-chain** checks; shared **17 hosted boundaries, 9 fresh-chain identity**, 12 deployment/registry suites, 131 PHP syntax checks; separate **24,487 certified-block, 8-assertion** local full hosted history with identical post-restart state root, **under original 40/20/15/24/1 allocation only**. These are *reported local results*, not rerun by this plan.

### 1.2 Existing important controls

- `hosted_settlement_candidate_enabled` remains **false** in production and default reject is `HOSTED_SERVICE_SETTLEMENT_NOT_QUALIFIED`.
- `HostedServiceMonitor` is polling-based; it is **not** a proved instantaneous CPU cutoff.
- An unresolved dispute refund cutoff is final; do not allow a late attestor resolution to resurrect a payable claim.
- Program and commitment state admission currently capped (reported **1,000 programs / 10,000 commitments**); never prune away replay and spent-offer evidence casually.
- Governance lost-quorum recovery, SDK and real operating budgets remain outstanding.
- Both **40/20/15/24/1** and **10/25/30/34/1** allocations are **unratified**. No economics allocation is changed by this work.

### 1.3 Source-of-truth rule

The public `dreambigtechnologycorp/greenwood-blockchain` repo was inspected for architectural touchpoints, but **does not establish that the local November 14 economics patch is pushed**. Inspect the local diff and existing objects; use `rg`/tests to find concrete integration points before creating classes. Proposed names below are examples unless confirmed present.

---

## 2. Design architecture and boundaries

```text
                     GREENWOOD CONSENSUS / NATIVE LEDGER
         finalized governance · registry authority · budgets · escrow
           settlement · system-service eligibility · epoch policies
                                      |
                        network-approved service manifest
                                      |
                         operator bootstrap/reconciler
                                      |
         +----------------------------+---------------------------+
         |                            |                           |
  PROVIDER / HOST                 WITNESS HOST               COORDINATOR
  containerd runtime              independently controlled    off-chain orchestration
  funded assignment              approved attestor image    durable relay/outbox
  protected host collector       evidence verification       no treasury keys
  guest-side diagnostics         signature / refusal
         |                            |                           |
         +------ bound, versioned usage/reservation evidence -----+
                                      |
                             AttestationPolicy
                        source trust + domain/quorum
                                      |
                      native `ServiceSettlement`
                         challenge / payout / refund
```

**Keep four logically separate authorities:**

1. **Execution:** existing provider worker, containerd, compute assignment, Application Registry and hosted deployment controller.
2. **Measurement:** host/guest/storage/network/accelerator collectors gather per-service evidence; they cannot authorize payments.
3. **Attestation:** eligible authorities verify evidence against versioned policies; a valid signature does not itself prove physical truth.
4. **Consensus settlement:** existing native ledger checks exact commitments, valid signatures, budget caps, deadlines, state, challenges and GWD conservation.

**Clarify trust boundaries:** Guest processes may be customer-controlled; host root may be provider-controlled; a separately hosted witness can be administratively independent yet unable to verify the provider's CPU statistics. Approved software hashes prove implementation identity, not truthful measurements. A machine operated by DreamBIG is never counted as independent of another DreamBIG machine merely because keys/PIDs/VMs differ.

**Do not confuse three leases:** (i) containerd resource-GC lease, (ii) provider capacity reservation, (iii) financial service commitment / hosted funded interval. Preserve distinct namespaces and IDs.

---

## 3. Non-negotiable invariants

1. Native GWD issuance fixed at 1B; six decimals; zero burn; no new mint, implicit token, AMM, or undocumented exchange peg. GRU is non-transferable accounting.
2. Balance conservation, protected-reserve classifications, grant/subsidy budgets, fee allocation, emissions, timelocks, quorum governance and supply accounting must not regress.
3. User and subsidy sources escrow **before executable assignment or hosted-service activation**. Earlier finalized funding requirements, exact assignment-generation binding, approved registry authority and fresh chain/genesis/code pins remain mandatory.
4. Money moves only through consensus-validated atomic native state transitions. Metering service, attestor and coordinator **never** receive special treasury spending powers.
5. Exact quote acceptance commits to **all** resource lines, unit definitions, catalogs, maximum quantities, GWD caps, provider payout account, funding sources, and verification mode. No post-acceptance unilateral rate/resource addition.
6. Bounded canonical integers and exact rational arithmetic; forbid float consensus arithmetic, integer overflow, negative deltas, unbounded payloads and ambiguous units.
7. A resource is payable only if its **specific billing basis** (reservation or consumption) has a qualified evidence policy. Unsupported/unknown telemetry must **not** be priced as verified consumed usage.
8. Trust level of a funded commitment is immutable for its lifetime. Independent mode must never silently downgrade when witnesses disappear. Disputes/refunds remain as agreed.
9. Independent attestors must be eligible by trust domain and actually verify the required evidence. Distinct keys alone cannot establish administrative independence or measurement truth.
10. Existing on-chain finite-compute request/assignment/receipt canonical encodings stay frozen. Introduce new tagged/versioned native financial actions and domain-separated attestations rather than silently append fields.
11. Recovery, retries, reboots, restarts, intervals, ownership changes and pruning never permit duplicate usage charges or provider payouts.
12. System-service manifests are network-authorized; operators explicitly install/authorize host access. **Network-owned does not mean consensus can forcibly deploy to arbitrary machines.**
13. Genesis/operator changes cannot silently rewrite prior signed obligations. A future membership transition may tighten the requirements for **new** commitments only under a ratified, delayed activation policy.
14. Permanent genesis, activation of live subsidies or bootstrap-trust payments and use of real signer keys remain separately authorized operations.

---

## 4. Work packages, dependency order and acceptance gates

### WP0 — Local audit, baseline capture and compatibility map — **P0 / first**

**Task:** Inspect the actual local working tree; map native economics and hosted code before modifications.

- Read `proposals/GRU_IMPLEMENTATION_REVIEW_2026-11-14.md`, `INDEPENDENT_CPU_METERING_PLAN.md`, latest `evidence/hosted-integration-tests.json`, `evidence/hosted-certified-history.json`, `evidence/hosted-fresh-mtls.json`, and local diff. Resolve filenames based on actual checkout.
- Locate `ServiceSettlement`, ledger/state routing, type-13 action envelope, program/quote/catalog hash, `HostedServiceBinding`, attestation classes and RPC/signer entrypoints.
- Inspect public-branch structural anchors: `src/Compute/Protocol/{ComputeRequest,ComputeAssign,ComputeReceiptV2,ComputeState}.php`, `src/Compute/Verification/ComputeReceiptVerifier.php`, `src/Compute/Containerd/{CgroupAccounting,SystemServiceDefinition,SystemServiceRuntime}.php`, `src/Provider/{ProviderWorker,WorkerFacts,ReceiptBuilder,Supervisor,ProviderJobRegistry,ProviderApplicationOperator,ProviderApplicationRegistry,ProviderApplicationRestorer}.php`, `system-services/runtime/supervise.php`, `system-services/deployment-controller/src/Deployment/DeploymentCoordinator.php`, and `deploy/ansible/roles/control_plane/tasks/system_services.yml`.
- Confirm checked-in Ansible system-service allowlist presently admits only `greenwood-registry`, `greenwood-registry-auth`; `SystemServiceDefinition` defaults to root + host networking; supervisor does not blindly restart failed container tasks.
- Capture current test invocation, environment, commit SHA, uncommitted status, existing test counts and failure baseline. No dependency installation or network side effects unless explicitly in approved local workflow.
- Produce `evidence/universal-resource-baseline.json` and a one-page implementation map with **confirmed** versus **proposed** file paths.

**Done when:** Existing code is accurately mapped; no regression/claim of tested hardware; no existing local work lost.

### WP1 — Canonical universal resource catalog and capabilities — **P0**

**Task:** Make GRU a typed resource-accounting protocol rather than only CPU settlement, without enabling unsupported meters by assertion.

**Catalog minimum** (distinct billable dimensions, not promises of universal telemetry):

| Category | Resource types and qualified billing candidates | Evidence source(s) |
|---|---|---|
| CPU | reserved millicore-time; consumed CPU milliseconds | cgroup allocation + `cpu.stat` |
| GPU | whole/partitioned GPU-time, GPU VRAM reservation; optional qualified utilization | approved device assignment, NVIDIA/AMD telemetry |
| NPU | device/partition reserved-time; optional qualified device execution counters | vendor driver/firmware telemetry, e.g. Rockchip where supported |
| RAM | reserved byte-seconds; optional verified resident byte-seconds | cgroup memory allocation, event samples, qualified interval integration |
| SSD/NVMe/HDD | provisioned byte-seconds; read/write bytes and operations where qualified | persistent volume registry + cgroup/block layer, storage-side accounting |
| NAS | tenant-exported byte-seconds, read/write operations, transfer | authoritative storage service rather than generic host PID |
| Object storage | retained byte-seconds, put/get/list operations, retrieval/transfer | authoritative object store counters |
| Block storage | provisioned byte-seconds, read/write I/O, throughput | volume / block accounting |
| Network | attributed ingress/egress bytes; reserved bitrate-duration | veth/tap/eBPF/gateway measurements, identity binding |
| Public IP / endpoints | address/port reservation interval | assignment authority and edge controls |
| Backups / snapshots | retained logical/physical bytes over time, operations | qualified snapshot/backup service |
| Dedicated node | exclusive node-hours and included capacities | allocation proof + availability policy |
| Future hardware | device-specific versioned metric/units | explicit plug-in adapter and proof profile |

**Canonical schema proposal (design only; encode in existing codec conventions):** `catalog_version`, `resource_id`, `unit_id`, `charge_basis={RESERVED,CONSUMED}`, `resource_class`, `meter_profile_id`, `evidence_policy_id`, `quantity_scale`, `included_allowance`, `max_quantity`, `rate_gru_num/den`, `aggregation`, `rounding_rule`, `physical_device_requirement`, `exclusivity_group`, `bundle_inclusion_policy`. Hash every semantic field; reject duplicate dimensions, unknown enums, incompatible units, more than policy-bounded line counts, and reused catalog hashes with changed meaning.

**Typed units:** Prefer `milli_cpu_ms`, `byte_seconds`, `allocated_byte_seconds`, `gpu_device_ms`, `npu_device_ms`, `io_bytes`, `io_ops`, `egress_bytes`, `address_seconds`; exact integer conversion to GRU base units. For GB-month, explicitly define byte convention and time basis; avoid calendar-month nondeterminism. Distinguish capacity/reservation from actual utilization. Define precise meter attribution and granularity.

**Capability extension:** Add versioned provider hardware manifests (architecture, CPU topology, GPU vendor/model/partition, NPU accelerator, RAM, disk classes, storage exports, network class, availability, trust/evidence support). Keep original provider capability request/assignment encoding intact. Add a versioned supplement and scheduler adapter only where necessary. Do not claim hardware merely because a model name implies a device is present; require runtime discovery.

**Tests:** round-trip canonical bytes, deterministic hashes, signed catalog changes, invalid units, overflow/underflow, rate boundary rounding, unknown device, duplicate/exclusive dimension rejection, 32-bit/64-bit cross-language vectors, provider capability updates with active reservations.

**Done when:** Every requested hardware type is representable and quoteable as an authorized reservation/consumption line; *unsupported* meters remain disabled for payable consumption; old CPU catalogs still decode and settle identically.

### WP2 — Composite provider offers, escrow and unified settlement — **P0**

**Task:** Extend the current signed native financial path, NOT create another payment system.

- Quote immutable multi-resource line vector, provider identity and payout key, workload/hosted binding hash, resource catalog hashes, rate rational per line, maximum quantity, accepted funding/trust mode and aggregate maximum GWD/GRU. Exact settlement and discount/subsidy split per line; choose/document whether rounding is per-line then sum, or rational sum then round, and prevent inconsistencies.
- Bind reservation resources to existing capacity scheduling and hosted financial commitment. Require all confirmed fees/quotas before activation. Add resource-specific admission limits and total GWD ceiling; deny any service with unbounded exposure.
- Separate allocation cost, provider base-service price, device metered cost, bundled included resources and overage. Example: dedicated node price already includes RAM/SSD unless explicitly excluded; NAS charge must not also bill underlying SSD capacity to same tenant. Define `exclusivity_group` and `included_in_bundle` logic at canonical catalog level.
- Extend existing type-13 financial action through an explicitly versioned extension or new tagged action where necessary; do not change existing signed encodings. Preserve prior-block funding, quotes' single-use rule, escrow conservation, protected subsidy caps, accepted-offer expiry, dispute/refund cutoffs, challenge delay and state-root replay.
- Permit multi-resource finite compute and recurring hosted intervals with identical monetary engine and independent adapter lifecycle. Financial `SETTLED` remains distinct from provider journal `SETTLED`.
- Preserve billing promises through contract/version transition; prior CPU-only commitments must remain unmodified.
- Handle partial failure: used portion payable only under the accepted terminal-status policy; missing telemetry doesn't automatically mean zero or unlimited billable work. Refund and dispute behavior must be explicit.

**Tests:** exact composite GWD/GRU calculations; split refunds; high and low rate; maximum charge; subsidy cap; double counted resources; quote replay; provider swap; reassignment generation; duplicate interval; CPU/GPU mixed lines; inactive catalog; missing settlement; cross-ledger conservation; both unratified allocation templates; 24,487-block full history baseline (rerun if feasible and capture explicit result).

**Done when:** A funded service can hold multiple resource lines and settle each only with the authorized evidence profile; original CPU-only flows are unchanged and no supplemental ledger exists.

### WP3 — Greenwood Resource Meter: continuous host/workload telemetry — **P0**

**Task:** Implement `GREENWOOD_RESOURCE_METER_V1` / `greenwood-metering` as an OCI-managed system service, using current provider/containerd code, with a **narrow host collector boundary**. Do not pretend guest agent or provider-controlled host readings are independently verified.

**Identity model:** `chain/genesis -> provider -> request/application -> finalized assignment generation -> funded commitment -> interval -> runtime (containerd id + cgroup identity + host boot id + stable start identity) -> device/volume/edge endpoint`. PID is diagnostic only: PIDs recycle, subprocesses are grouped by cgroup, and restarts cannot reset accumulated accounting.

**Collectors:**
- `CpuCgroupCollector`: extend existing `CgroupAccounting` from one final sample to periodic cumulative CPU counter snapshots; use cgroup v2 and preserve current complete-final-receipt path.
- `RamCollector`: reserved memory byte-seconds from authorized allocation intervals; optional measured memory byte-seconds only with qualified integration/sampling specification; peak memory **never** mislabeled GiB-hours.
- `BlockIoCollector`: `io.stat` and volume identity; do not equate host-wide block traffic with tenant activity.
- `StorageCapacityCollector`: volume, NAS and object allocations and retained-byte duration; use storage-side attribution and durability model. Include logical vs physical bytes, replication and compression policies.
- `NetworkCollector`: per-container veth/tap and applicable eBPF/gateway accounting, with cgroup/network namespace binding; do not charge host background traffic. Protect against interface reset, wrap and offload differences.
- `GpuCollector`: approved vendor-specific device/partition assignment, reserved time; optional GPU utilization where process/device attribution and telemetry are qualified.
- `NpuCollector`: approved vendor-specific NPU device allocation; fallback to qualified reserved-device time, never guessed computation from TOPS label.
- `ReservationCollector`: independently auditable provisioned CPU/RAM/GPU/NPU/storage/network resources when use metrics are not qualified.

**Operational properties:** bounded interval samples, monotonic counters and reset provenance, exact CPU milliseconds/byte-seconds, durable WAL or equivalent + atomic checkpoint, boot/restart recovery, replay-safe interval sequence, retention/rollover policy, no secrets in logs, protected telemetry API, no arbitrary host file reads, strict per-service authorization and failure-state signaling. Preserve evidence hashes and raw measurement provenance for disputes, with privacy redaction and bounded storage.

**Privilege minimization:** Existing `SystemServiceDefinition` defaults `user=0:0`, `hostNetwork=true`; **explicitly override** for meter/attestor/coordinator. If host cgroup/device access requires root privileges, use a separately reviewed, narrowly exposed host collector with allowlisted read interfaces, not a universal root container or containerd socket given to the public network. The host collector must be install/permission-approved by the node operator.

**Tests:** PID reuse, child process spawn, cgroup deletion, lost sample, concurrent jobs, app restart, host reboot, negative/reset/wrap counter, sampled vs reserved time, volume mapping, duplicate device allocation, missing PCI/NPU driver, cross-tenant leakage, network ingress/egress attribution, race at interval boundary, filesystem failure, unexpected shutdown.

**Done when:** A single-operator disposable Linux host produces durable, canonical, correctly attributed measured/reserved evidence for its available devices; unsupported hardware is explicitly reported unsupported. **This does not claim independent physical attestation.**

### WP4 — System-service policy, distribution and bootstrap — **P0**

**Task:** Make Greenwood network-governed service definitions work beyond the two registry bootstrap services while preserving exact image/cert/permissions checks.

**Native/network policy fields** (versioned): `service_id`, `version`, `image_digest`, `artifact_source`, `approved_role`, `required_capabilities`, `execution_profile`, `host_permissions`, `config_schema_hash`, `minimum_eligible_replicas`, `operator_eligibility`, `activation_height`, `grace/drain`, `rollback/revocation_version`, `governance_proposal_id`, `budget_policy_ref`. The consensus state contains **policy and commitments**; distribution and execution stay off-chain.

**Service classes:**
- `GREENWOOD_REGISTRY_V1` and `GREENWOOD_REGISTRY_AUTH_V1` remain seed/bootstrap services.
- `GREENWOOD_METERING_V1`: measurement and evidence service; host collector separate when necessary.
- `GREENWOOD_ATTESTOR_V1`: policy/evidence verifier and signing workflow.
- `GREENWOOD_METERING_COORDINATOR_V1`: discovery, collection requests, aggregation/relay and durable outbox, **no direct monetary power**.

**Concrete existing anchors:** `src/Compute/Containerd/SystemService{Definition,Runtime}.php`, `system-services/runtime/supervise.php`, `system-services/README.md`, `deploy/ansible/roles/control_plane/tasks/system_services.yml`, `/etc/greenwood/system-services/<id>.json`, systemd `system-service.j2`. Replace/extend the currently hardcoded Ansible two-service allowlist with an explicit reviewed policy that distinguishes seed and later governed services; don't make it `allow any image`. Maintain digest pinning, config ancestry ownership, prohibited symlinks, no silent definition replacement, controlled task reconciliation and no blind restart of uncertain workloads.

**Bootstrap and joining:** Approved seed artifacts may be preinstalled during node provisioning. An operator runs an opt-in Greenwood node/provider bootstrapping agent. The agent watches finalized approved manifests, verifies chain/genesis, signatures, required roles and versions, fetches OCI by approved digest, verifies artifact, requests locally approved permissions, stages, starts via existing runtime and reports health. Network rules can **deny eligibility and payable claims** for noncompliant operators; they cannot force installation or host privilege delegation.

**Update safety:** old manifests cannot authorize new code; explicit delayed activation, staged image caching, two-phase readiness/cutover, rollback compatibility, old-commitment policy retention, independent owner veto over local host permissions (network may mark incompatible), signed audit events.

**Tests:** unknown system service denied, revoked image, mutated image, stale policy, no image source, unauthorized root mount, host-network violation, wrong chain/genesis, interrupted installation/start and restart reconciliation, conflicting config existing, upgrade/rollback/grace, signed manifest replay, system service outage not blocking core chain consensus.

**Done when:** Disposable node can opt in and obtain a governance-approved metering/attestation image through the existing containerd primitives with no bypass of root-owned policies. Network-governed distribution is demonstrated **locally**, not assumed production-ready.

### WP5 — Evidence validation, trust profiles and attestation — **P0**

**Task:** Make actual trust explicit; no fake independence at genesis.

**Trust profiles to propose and version:**

1. `DEV_SIMULATED`: local fixtures and synthetic attestors; never treated as production independent proof.
2. `BOOTSTRAP_OPERATOR_ATTESTED`: one declared operator controls provider and metering. **Clearly labeled provider-origin evidence.** Any commercially payable use requires explicit customer acceptance and a distinct *reviewed/ratified* bootstrap program and consensus action; do not silently bypass current at-least-two-distinct-attestor requirement (administrative independence is not yet demonstrated). **Institute subsidy from protected reserve defaults denied** in this mode unless separate governance authorizes a bounded non-independent program with complete financial disclosures and no false "independent" claims.
3. `INDEPENDENT_ATTESTED`: qualified separately controlled measurement source or validated evidence, independent administrators/trust domains, threshold signatures and approved dispute policy. No mere host count or multi-key quorum.

**Evidence envelope** (canonical, domain-separated): chain, genesis, provider, workload/application identity, assignment generation, commitment ID, interval start/end, catalog/resource manifest hashes, meter profile/version, host/runtime/device/volume lineage, cumulative counters with start/end or duration-defined reservation claims, evidence source identity and trust level, measurement-bundle digest, sequence numbers, public signer identity, timestamp/height, signatures. Include subject privacy boundaries; avoid raw customer payloads.

**Attestor eligibility:** registered operator and trust domain, explicitly qualified evidence source, non-self-attestation for independent profile, disallow customer/provider and affiliated authority if policy says so; attestor revocation and effective-height versioning, signed conflict/refusal event, challenge and resolution linkage.

**Attack assumptions and mitigations:** Provider root can fake host-visible counters, provider can clone keys under multiple identities, attestor service can be correct yet receive false source telemetry, logs can be deleted, firmware/device metrics may be incomplete. Remote attestors alone **cannot** observe consumed CPU truth on an untrusted bare-metal provider. Qualify consumed CPU only with a defensible measurement trust base (independently controlled hypervisor/host, protected hardware evidence with known limits, or other independently observable source). Otherwise qualify explicit reservation/availability under documented terms; **never relabel reservation as consumed usage.**

**Quorum:** distinct cryptographic signatures are necessary but not sufficient. Record domain independence evidence and governance approval; prevent one declared domain from satisfying independent quorum with N keys. Initial example 2-of-3 belongs in a *proposed* policy, not hard-coded consensus without ratification. Outage must not cause silent downgrade. New commitments can opt into another policy only via disclosed acceptance.

**Tests:** forged/duplicate/replayed signed evidence, wrong chain/genesis, wrong quote/catalog/interval, inflated CPU, signed-but-conflicting evidence, self-attestation attempt, two keys same domain, revoked meter, colluding witness model, stale evidence, quorum loss, split brain, late dispute resolution, unsigned dispute, missing counter, refund-to-reserve conservation.

**Done when:** Dev and founder bootstrap paths are labeled correctly; independent profile accepts only independently qualified evidence; any unsupported physical truth claim remains disabled. Existing CPU-quorum code cannot be bypassed by a mere config flag.

### WP6 — Operator registry and network decentralization epochs — **P0 design + P1 activation**

**Task:** Implement protocol-defined reduction of genesis-operator dependence as independently qualified roles join. Treat this as **authority/policy decentralization, not an automatic token-issuance halving**.

**Separate operator roles:** `PROVIDER`, `METERING`, `ATTESTOR`, `SYSTEM_SERVICE`, `VALIDATOR`, `GOVERNANCE_SIGNER`. One legal/admin entity may hold multiple roles but must count once where independence is required. A new independent provider **does not** make its own usage independently attestable; validator/governance distribution are separate.

**Registry candidate fields:** operator ID, operator/controller public keys, roles, externally reviewed administrative-domain identifier, independence evidence digest and reviewer, qualified resource/evidence capabilities, active/paused/revoked state, approved service manifest commitments, activation height, heartbeat/availability history, stake/collateral only if separately designed and ratified, conflicts of interest. Public registration is not proof of distinct beneficial ownership. Define an auditable process and explicit residual trust assumptions.

**Progress vectors, not one boolean:**
- `resource_operator_diversity`: distinct qualified independent resource-supply domains and concentration by capacity.
- `verification_diversity`: attestor source independence, administrative control and qualified availability for specific resource profile.
- `validator_diversity`: independent consensus control **only after a separate dynamic membership protocol is implemented and accepted**; current published validator set is genesis-fixed.
- `governance_diversity`: effective independent treasury/governance control under real custody, not number of signatures.

**Proposed transition model (parameters MUST be reviewed and ratified):**

| Stage | Qualifying state | Safe change |
|---|---|---|
| `E0_BOOTSTRAP` | One disclosed administrative operator | permit explicit operator-attested pilots; no independent attestation claim |
| `E1_RESOURCE_EXPANSION` | >=2 **qualified independently administered resource operators** sustained over a reviewed measurement window | permit independent supplier selection; **do not** weaken/tighten unrelated attestor policy automatically |
| `E2_VERIFICATION_EXPANSION` | sufficient truly independent evidence-qualified attestors per resource class and quorum availability | make independently attested programs eligible; optionally require this for *new* protected-subsidy commitments under ratified policy |
| `E3_AUTHORITY_DISTRIBUTION` | independently administered validator/governance set meets a ratified threshold **and dynamic membership exists** | phase out genesis-only administrative privileges under separately authorized upgrade |

**Transitions:** evaluate *finalized* eligibility and sustained participation, not IP count, announcement, total machines or single-registration transaction. Document operator independence review and challenge evidence. Explicit upgrade proposal, authority threshold, activation height, timelock, notice, sunset rules, compatible manifest, and new-commitment eligibility. Fraudulent/short-lived operators cannot trigger an automatic security downgrade or premature transition. Once stronger independence is required for a contract, lost quorum **freezes new claims/refunds by existing bounded policy**; it never flips them back to founder self-attestation.

**What changes (optional ratified examples):** admission eligibility, assignment candidate pool, independent verification requirement, bootstrap-only service permissions, genesis-only operator privileges, qualified witness selection, infrastructure service compensation. **What does not change automatically:** fixed GWD issuance, existing height-based emission schedule, protected treasury controls, previous contracts, receipt formats, genesis chain identity, or consensus validator membership.

**Network halving analogy:** call it a *decentralization epoch / genesis privilege sunset*. Count **independent control**, not raw operators, to resist Sybil. A second independently controlled data center marks the start of resource-supply decentralization, **not** automatically verified metering, independent governance or consensus.

**Tests:** false operator count (same admin, cloned keys, affiliated entities), fluctuating registrations, outage after E2, stale membership proof, conflicting role flags, delayed activation, before/after boundary replay, new vs legacy funded policy, no founder unilateral epoch acceleration, reproducible state root, genesis privilege expiry, validator set unchanged until explicit upgrade.

**Done when:** E0 runs correctly under a real single operator; simulation transitions to E1/E2 on qualifying signed facts; trust policies cannot silently weaken; consensus/governance decentralization is not falsely claimed from provider joins.

### WP7 — Bootstrap economics and system-service incentives — **P1, policy gated**

**Task:** Define how network-owned system services are compensated without self-approving their own invoices.

- Core registry/meter/attestor infrastructure operates under network-governed *infrastructure-service budgets*, not an escrow that requires the unavailable metering system to certify its own launch.
- Service operating budgets, operator payment eligibility, basic availability fee and per-attestation fee must be deterministic and bounded, with governance approvals; no unapproved new token or automatic reserve expansion.
- Customer/Institute charge split comes from current accepted quotation and actual approved service consumption. Provider costs, uptime and profitability require measured external costs (equipment, electricity, connectivity, maintenance, operations, license, storage replication), not fabricated GWD-USD exchange rates.
- Operator self-attestation cannot pay its own subsidies by default. Budget spend on infrastructure service operations is a separately authorized treasury category and may need its own audit and governance controls; never reuse the customer usage-claim attestor path for issuer compensation without reviewing circular dependencies.
- Reward incentives and bootstrap sunset schedules must be anti-Sybil and ratified; never tie issuance halvings to easily spoofable raw operator counts.
- Keep existing allocation candidates unchanged; model solvency separately, including fee revenue uncertainty and validator compensation.

**Done when:** A reviewed proposal and deterministic accounting tests cover network service cost/compensation; no economic policy silently ratified.

### WP8 — SDK, operator tools, orchestrator and product UX — **P1**

**Task:** Make the existing protocol usable without replacing the app/dashboard.

- PHP signing helpers: preserve `ServiceAuthorization` and `service-sign`; add versioned resource quote, catalog, meter and attestation introspection, no private key generation or automatic broadcasting by default.
- JavaScript GAP SDK: sign/verify type-13 actions and new versioned resource/program actions consistently with PHP; cross-language byte-for-byte fixtures. Implement readable quotes and consent (trust profile, resource limits, amount, subsidy dependence, refund policy, provider identity).
- Operator CLI: list advertised hardware, meter support/unsupported reason, signed quote, funded assignment, current interval, witness status, financial status, stalled/outage conditions, pending challenge, expiration restriction.
- Coordinator: collect evidence and approvals, durable outbox, idempotent submission, no automatic uncertain nonce re-signing, permissionless settlement relay where protocol allows, chain identity pinning, replay protection and bounded backoff.
- Dashboards distinguish `EXECUTING`, `RECEIPT_FINALIZED`, `USAGE_PENDING`, `CHALLENGE`, `PAYMENT_FINALIZED`, `REFUNDED`, and `UNVERIFIED` with no conflation. Hosted app owner view tracks reserved vs consumed quantities by unit and trusted source.
- RPC status: paginated, bounded list of resources, providers, service definitions, operator roles, epoch qualification, attestor policy, metering evidence digests (not sensitive raw data), settlement and refunds; honor chain/genesis and finalized state root.

**Done when:** End-to-end local developer and operator journeys work through existing provider/registry/financial interfaces; unsupported resources show explicit restrictions; JS/PHP sign identical payloads.

### WP9 — Recovery, pruning and security hardening — **P1, release blocker**

- Design bounded storage for programs/commitments/used quotes/resource evidence/operator lifecycle; preserve chain-replay-deterministic state and durable spent/revoked tombstones or equivalent cryptographically authenticated non-replay history. **Never discard spent-offer or paid-commitment protection.**
- Improve hosted watchdog for stale financial head and interval expiry, explicit fail-closed network restriction, and observed physical CPU/resource enforcement. Do not claim polling is a real-time cutoff.
- Clarify recovery from lost attestor quorum (refund and provider-unpaid semantics must be explicit) versus treasury signing quorum (separate governed key-replacement design; emergency council cannot spend by itself).
- Secure secret material and configuration, event/audit retention, attestor signer key custody, mTLS, pinned identities, reader vs writer permissions, workload isolation, remote RPC distrust, malicious resource metadata, malicious OCI manifest, update authorization and rollback.
- Verify against existing fixed topology and changing operator policy; do not claim dynamic consensus membership from provider registry alone.
- Threat model: malicious founder/admin, forged independent operators, false signed telemetry, quote/replay abuse, subsidy drain, overlapping claims, colluding attestors, 1-host outage, corrupt WAL, cross-tenant leak, faked resource type, stale block, chain ID replay, governance takeover, root compromise, workload restart.

**Done when:** Security boundaries and archival behavior are tested on full certified replay and documented with explicit residual risks.

### WP10 — Disposable physical qualification and production release gates — **P1/P2, external hosts dependent**

**Do not assume a second operator or real witness environment exists.** Publish candidate inventories only with placeholders; distinguish host name, operator, administrative domain, measurement-trust source and authorized credentials. Published `deploy/ansible/inventory/validators.ini` contains localhost; `deploy/ansible/FRESH_CLUSTER.md` documents a four-node ARM64 layout, not an independent meter installation. Do not point destructive fresh-cluster playbooks at the existing operating nodes.

**Qualification staircase:**

A. **Single-operator physical:** One disposable host/provider, real containerd/cgroup accounting, quota and cutoff tests, GPU/NPU/NAS available only if actually present, real resource commitments and receipts, refunds and payouts on a disposable local economic chain. Label all evidence operator-attested.

B. **Simulated multi-operator:** Three separate local test processes/keys verify deterministic protocol and independent-role selection logic; record that they share real administration.

C. **Independent physical witnesses:** At least required distinct real operators, independent key custody and qualifying measurements. Conduct an admin/host privilege separation review. Test intentionally inflated provider CPU telemetry, witness refusal, outage, collusion assumptions, and direct signal from trusted source; mere cross-check of provider logs is not a pass.

D. **Physical hosted lifecycle:** actual WASM registry authority, provider-signed interval, finalized escrow, receiver COMMITTED journal, deployment, network readiness, measurements, attestations, challenge, GWD settlement, renewals, restarts, registry ownership revocation, expiry network cleanup, watchdog and truthful outage status.

E. **Distributed certified chain:** actual independent processes/hosts, block finality, restart/replay, signed settlement transaction, no double payment, identical state across validators, real network partitions and rejoin, stability soak and resource-metric evidence.

F. **Go/no-go:** independent review, security qualification, operator contract/consumer disclosures, economics viability and qualified measurement trust, governance and allocation ratification, quorum recovery, validated immutable deployment artifacts, explicit permanent genesis approval. No test script may silently perform F.

**Per-resource qualification:** Publish a matrix with hardware/model, collector version, billing basis, evidence source, ability to verify physical truth, admin control, test results and `QUALIFIED`/`NOT_QUALIFIED`. No qualified consumption meter -> cannot enable a verified consumption payment. Reservation billing can qualify through its own explicitly agreed reservation/availability policy.

**Done when:** The actual hardware, certificates, receipts, signed evidence and final GWD movements are demonstrated. Independent attestation qualification **cannot** be marked complete while one operator controls all trust domains.

---

## 5. Proposed API and protocol objects — refine after local audit

These names are **proposals**, not claims about present code:

```text
ResourceCatalogV2
  catalog_hash, resource_id, unit_id, charge_basis, metering_profile,
  verification_policy, rate semantics, exclusions/bundles, version

ProviderHardwareManifestV1
  provider_id, operator_domain_id, chain/genesis, host/capability digest,
  CPU/GPU/NPU/RAM/disk/NAS/network classes, device identity, resource limits,
  provenance, supported meter profiles, signature

CompositeServiceQuoteV1
  provider_id, payout_address, service_binding_hash, catalog_hashes,
  resource_lines[], max_gru, max_gwd_units, expiry, nonce, trust_profile,
  split/rounding policy, accepted customer funding rules, signature

WorkloadMeterBindingV1
  funded_commitment_id, compute_request_or_app_id, assignment_generation,
  interval_id, host_boot_id, container_id, cgroup_id, device/volume/interface IDs

ResourceEvidenceV1
  chain_id, genesis_hash, funded_commitment, provider_id, assignment_generation,
  interval_start/end, binding_hash, catalog/resource manifest hash,
  metric lines[], source/provenance, meter_version, sequence, evidence_hash,
  attestor_signatures or separate signed attestations

SystemServiceManifestV1
  service_id, version, immutable image digest, deployment requirements,
  allowed host privileges, authorized operator roles, governance activation,
  revocation, rollback compatibility, funded infrastructure budget reference

OperatorRegistryV1 / DecentralizationPolicyV1
  operator and admin domain, eligible roles, evidence, status,
  resource/attestation qualifications, finalized activation, policy epoch
```

**Compatibility rule:** `CompositeServiceQuoteV1` is a new explicit protocol object; the class suffix `V1` does *not* authorize changes to existing frozen `ComputeRequestV1` or `ComputeReceiptV2`. Native ledger action/codec versioning is the integration boundary. Exact schema and hash domain names must be ratified through implementation tests; never hash noncanonical JSON or use floating-point data in consensus.

---

## 6. Test matrix (mandatory adversarial coverage)

| Category | Positive acceptance | Negative/failure cases |
|---|---|---|
| Catalog/units | deterministic integer unit conversion for all resource classes | unknown/duplicate units, changed catalog hash, overflow, ambiguous month |
| Capacity | valid provider resource advertisement and reservation | fake GPU/NPU, conflicting exclusive reservations, overcommit, active capability reduction |
| Pricing | multi-resource signed quote, exact GRU to GWD split | missing fee line, unsupported meter, rate tampering, replay, new line after acceptance |
| Execution | funded finite job and hosted interval | unfinanced work, same-block funding, wrong assignment generation, stale registry release |
| CPU/RAM | correct cgroup accounting / reservation interval | PID reuse, process fork, `memory.peak` incorrectly treated as time, missing samples |
| GPU/NPU | valid device assignment reservation | shared device double-charge, unsupported telemetry, unknown firmware/driver |
| Storage/NAS | scoped tenant volume allocation and duration | NAS+backing SSD duplicate charges, shared export attribution, deleted snapshot |
| Network | workload egress/ingress counters | host-wide traffic charge, interface reset, loopback, lost namespace |
| Attestation | qualified source, correct signed evidence | provider self-attestation in independent mode, same admin multiple keys, fake counters |
| Bootstrap | single operator mode labeled correctly | bootstrap evidence accepted as independent, Institute subsidy paid without ratification |
| Epoch | finalized sustained independent operator milestone | 1 actor 10 keys, two affiliated entities, flapping registration, instant unreviewed change |
| Service deploy | approved digest, local opt-in, safe profile | forced installation, unauthorized root, image mismatch, stale/revoked manifest |
| Payment | correct customer/provider/subsidy debits and refund | replay, duplicate receipt, over-cap charges, negative delta, payout without claim |
| Disputes | bounded final challenge and refund | late council resolution after cutoff, conflicting quorum, unavailable council |
| Recovery | app/container restart preserves binding and totals | reset counter billed twice, uncertain outbox resubmits wrong nonce, corrupt DB |
| Persistence | deterministic full certified history and state root | pruning removes paid/used tombstones, SQLite tamper, incomplete replay |
| Physical | real metering/evidence/payout with actual cluster | disconnected witness, forged telemetry, offline gateway, hard cutoff not enforced |

**Evidence rules:** Include command, binary version, source SHA, hardware identity where material, environment/container digest, chain/genesis, test fixture classification, test count/assertions, start/end result, expected vs actual, source artifact hash and honest pass/fail. Do not manufacture `PASS` from test mocks or label loopback as distributed.

---

## 7. Codex deliverables / files to produce

All names below are **candidate outputs**; respect existing repository conventions and avoid naming conflicts.

1. `proposals/GREENWOOD_UNIVERSAL_RESOURCES_V1.md` — catalogs, units, pricing and reservation-vs-use semantics.
2. `proposals/GREENWOOD_NETWORK_SYSTEM_SERVICES_V1.md` — bootstrap manifests, operators, runtime privileges, service upgrades.
3. `proposals/GREENWOOD_ATTESTATION_TRUST_V1.md` — measurement provenance, trust profiles and adversarial assumptions.
4. `proposals/GREENWOOD_DECENTRALIZATION_EPOCHS_V1.md` — role-specific progress, independent-domain rules, anti-Sybil criteria, delayed activation and founder sunset.
5. Source additions/extensions in actual `src/Compute/`, `src/Provider/`, native `Ledger`/`State`, `system-services/`, `deploy/ansible/`, RPC and SDK areas found in WP0.
6. Versioned PHP+JS signing vectors and unit/integration tests; certified-replay suites; Linux/containerd disposable host fixtures.
7. `evidence/universal-resource-implementation-summary.json` — exact feature gates by resource, workload, trust mode, physical environment and test status.
8. `docs/operations/resource-metering.md` and `docs/operations/operator-onboarding.md` (or existing docs layout) — no invented qualified host information.
9. `proposals/RELEASE_GATES_UNIVERSAL_RESOURCES.md` — ratification and hardware qualification checklist.
10. A short final **implementation status report** separating `IMPLEMENTED_LOCAL`, `TESTED_LOCAL`, `TESTED_PHYSICAL_BOOTSTRAP`, `INDEPENDENTLY_QUALIFIED`, `REQUIRES_RATIFICATION`, `NOT_IMPLEMENTED`, `BLOCKED_EXTERNAL`.

**Keep the current codex-run evidence and reports intact**; add dated updated files instead of rewriting historical test outcomes.

---

## 8. Sprint sequencing and decision gates

**Sprint A — Stabilize existing work (WP0):** establish local source/evidence baseline; run representative economics/hosted tests; validate no changed genesis/policy.

**Sprint B — Resource vocabulary (WP1):** canonical typed catalog, hardware capability manifests, deterministic conversions and feature flags. This is the first functional deliverable.

**Sprint C — Multi-resource economics (WP2):** signed composite quotes, funding, subsidies and refunds using `ServiceSettlement`; preserve CPU-only regression.

**Sprint D — Meter implementation (WP3):** real Linux collector, interval journals and GPU/NPU/NAS/network capability adapters with fail-closed status; no independence claim.

**Sprint E — Network system services (WP4):** immutable manifest, policy, operator opt-in bootstrap, safe execution profile and updater/reconciler; do not turn on production deployment.

**Sprint F — Attestation trust (WP5):** bootstrap trust profile separate from independent mode; exact evidence validation and anti-self-attestation for independent claims. Requires policy/gov review to activate operator-attested real payments.

**Sprint G — Decentralization epoch mechanism (WP6):** operator registry, role-specific progress vectors, E0→E1→E2 fixture transitions, no automatic consensus change. Actual E3 membership is a separate consensus roadmap.

**Sprint H — Operations & completion (WP7–WP9):** incentives design, JS SDK and operator interfaces, migration/persistence, pruning, recovery and full adversarial suite.

**Sprint I — Qualification (WP10):** disposable single-operator host, independent operators when available, real hard cutoffs and distributed state replay. Mark pending external gates honestly. Production ratification remains an independent decision.

**Gate A (architecture):** Existing economic invariants and frozen wire codecs preserved; new catalog/manifest canonical and deterministic.
**Gate B (source behavior):** Composite pricing + funded execution + evidence + escrow + refund work locally with negative tests.
**Gate C (network behavior):** System-service distribution and explicit bootstrap policy work on disposable hardware; no keys or runtime permissions self-granted.
**Gate D (independence):** Actual independent operator/admin domains, qualified evidence source and threshold verified.
**Gate E (mainnet):** Treasury recovery, pruning, economic cost model, security review, full distributed soak, permanent governance/allocations and genesis separately ratified.

---

## 9. Exact existing validation commands from the supplied report

Re-run only in the authorized local checkout with approved preinstalled compiler/runtime artifacts and matching PHP extensions. Do not silently install/unpin dependencies or touch real services.

```sh
GREENWOOD_ASSEMBLYSCRIPT_ROOT="$PWD/contracts/application-registry-next/node_modules/assemblyscript" \
  sh contracts/application-registry-next/build.sh /tmp/greenwood-hosted-registry.wasm
# GREENWOOD_WASMTIME_LIB must reference the approved local libwasmtime.so.
php -d ffi.enable=true tests/Economics/hosted.php /tmp/greenwood-hosted-registry.wasm
php tests/Economics/hosted-boundaries.php
php -d ffi.enable=true tests/Economics/hosted-certified-history.php /tmp/greenwood-hosted-registry.wasm
```

Use the actual local test manifest to locate the core economics, finite-compute, regression, identity, provider and cross-language suites. Record whether this plan's new runs reproduce reported counts; never carry forward past results as new passes.

---

## 10. Ratification register: intentionally not decided by Codex

These values and authorities need an explicit economic/security/governance decision. Codex may create default-deny schemas, candidate policies and adversarial simulations, but **must not present them as ratified protocol policy**.

- Independent domain qualification: human/organization review authority, beneficial-control/conflict checks, challenge, sanctions and appeals.
- Role-specific thresholds and required sustained participation windows for E1/E2/E3, activation delays and minimum qualified resource capacity.
- Whether/how an `OPERATOR_ATTESTED` paid bootstrap program is permitted, with explicit customer consent, fees/refunds and whether any Institute funds can ever be used without independent measurement.
- Resource class measurement trust profiles and which consumption meters can justify payment.
- System-service operator compensation and bootstrap privilege sunset.
- 2-of-3 or other independent attestor quorum, key custody and dispute outage treatment.
- Dedicated CPU/GPU/RAM/storage bundle semantics, retention periods and exact cost models.
- Lost Institute signer-quorum recovery and separate dynamic consensus validator membership.
- Treasury allocation candidate (40/20/15/24/1 vs 10/25/30/34/1), reserve ceilings and actual operator budget.
- Production code/hardware qualification and permanent genesis activation.

---

## 11. Definition of done for this Codex assignment

Codex should finish with a **current-state matrix** listing each numbered WP, exact changed source files, new protocol versions, locally run tests, passed/failed evidence paths, known hardware limitations, remaining governance decisions and the next runnable step. If substantial scope remains, complete WP0–WP3 and the highest-value local integration tests before reporting; do **not** stop at a high-level design document.

**Objective achieved at software level:** Greenwood can quote, reserve, meter and settle multiple concrete resources against existing native financial commitments, automatically distribute approved system-service code to consenting operators, operate honestly with a single bootstrap operator, and mechanically transition to stronger role-specific verification eligibility when independently controlled operators are present.

**Objective not yet achieved by code alone:** independent physical truth, independently controlled operators, real-world hardware cutoff, cost/revenue viability, decentralization of the genesis-fixed validator set, permanent mainnet acceptance. Keep these unclaimed until the corresponding conditions are actually met.

---

### Short paste-in Codex kickoff instruction

> Continue from the current local Greenwood economics/hosted integration; read `GREENWOOD_CODEX_IMPLEMENTATION_PLAN_2026-11-14.md` in full. Execute WP0 immediately and then implement WP1–WP3 in dependency order, adding tests and evidence for every change. Continue through WP4–WP9 as the local tree permits without needing external hosts. Use existing `ServiceSettlement`, provider, registry, receipt and system-service runtimes; do not recreate them. Keep the existing immutable economics, unratified allocations and candidate-only production flags untouched. Implement three separately labeled trust profiles and a role-specific decentralization epoch mechanism; never claim fake independent attestation from one founder or multiple controlled keys. If physical proof or ratification is unavailable, keep those paths disabled, record `PENDING_PHYSICAL`/`REQUIRES_RATIFICATION`, and continue other local engineering tasks. Return the exact changed files, test commands/results and evidence artifacts; do not push or activate anything.
