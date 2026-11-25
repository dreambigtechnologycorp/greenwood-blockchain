# Universal resource implementation report — November 14, 2026

> **Historical scope — reviewed November 24, 2026.** The work-package labels and FULL/WAL journal descriptions below are historical. Later source integration and uncommitted rollback-journal changes have separate evidence.
> Read the [economics index](README.md) for current source and policy precedence,
> and [project status](../PROJECT_STATUS.md) for release/installation and qualification.

**Source implementation remains incomplete. Production remains HOLD.** This report distinguishes completed local mechanisms from partial work packages; passing listed tests is not a claim that an entire WP or physical qualification is complete. Existing work and historical evidence were preserved.

The local increment adds timelocked resource-network policy, role/domain/participation qualification and E0–E2 logic, opt-in service definition validation, explicit independent-program restrictions, durable meter checkpoints and signed nonpayable observations, exact-byte outbox, GAP SDK types 13/14, bounded RPC/consent/status tools, terminal archival and provider stale-head restriction. It does not complete automated service distribution, authoritative hardware collectors or the end-to-end operator/product workflow.

## Verification

31 regression suites and 114 PHP syntax checks passed; SDK 30 assertions passed with actual PHP verification. Final meter checkpoint suite adds corruption coverage (13 passed). Both allocation candidates passed core/finite/hosted/resource/certified foundation tests. Separate full 24,487-certified-block histories passed and reproduced roots after actual Chain restart: proposed allocation with native operator record (471.358s), original allocation with operator record and archive_v1 (455.797s). These use three actual Ed25519 validator identities in local processes, actual registry WASM, SQLite and no live network payments.

Evidence: `docs/economics/evidence/universal-resource-2026-11-14/`. Exact per-suite commands/results are in `verification.json`; history flags/commands and runtime hashes are in `universal-resource-implementation-summary.json`. JS subprocess tests required approved sandbox escalation; no external network/keys were used. Initial sandboxed subprocess run failed; the final approved run passed. Historical evidence elsewhere is unchanged.

## WP completion matrix

### WP0 — TESTED_LOCAL

**Implemented scope:** Audited dirty local source and existing GRU v2/hosted/system-service boundaries; preserved existing work.

**Files:** `docs/economics/evidence/universal-resource-2026-11-14/baseline.json`, `docs/economics/evidence/universal-resource-2026-11-14/working-tree.txt`

**Executed tests:** Baseline: core 64, resources 75, metering 11, actual-WASM composite 21 before this increment. Final regression: 31 suites PASS; 114 PHP syntax checks PASS.

**Remaining defects/limitations:** Audit is local source/test evidence; operating physical services and hosts were not contacted.

**Physical qualification:** Disposable runtime and independently administered hosts are not designated.

**Ratification:** No policy ratified by audit.

### WP1 — TESTED_LOCAL

**Implemented scope:** Reused existing 36-definition catalog v2, typed manifests, type-14 capabilities and existing scheduler/reservation adapter without frozen codec changes.

**Files:** `src/Resources/ResourceCatalog.php`, `src/Resources/ProviderCapabilityV2.php`, `src/Resources/ResourceManifest.php`, `src/Compute/Scheduling/ReservationLedger.php`, `src/Compute/Scheduling/Scheduler.php`, `docs/economics/proposals/GREENWOOD_UNIVERSAL_RESOURCES_V1.md`

**Executed tests:** resources.php: 75 PASS; resource-settlement.php: 28 PASS per allocation; SDK codec tests: 30 PASS.

**Remaining defects/limitations:** Definitions and advertised model names do not prove actual hardware. All default catalog entries remain disabled.

**Physical qualification:** Runtime discovery/assignment and evidence qualification for each deployed hardware class.

**Ratification:** Per-class units/qualification/bundle policy and payable profile activation.

### WP2 — TESTED_LOCAL

**Implemented scope:** Existing unified finite/hosted resource settlement retained; added exact trust-policy offer binding and terminal archival protection.

**Files:** `src/Economics/ServiceSettlement.php`, `src/Economics/Ledger.php`, `src/Network/AttestationTrust.php`, `tests/Economics/resource-settlement.php`, `tests/Economics/hosted-certified-history.php`

**Executed tests:** Per allocation: core 64, finite CPU 68, hosted CPU 32, resource settlement 28, certified foundation 25 PASS. 24,487 certified blocks/restart replay per allocation PASS; original allocation includes archive_v1; proposed allocation includes network policy but predates archive use.

**Remaining defects/limitations:** Explicit independent profile checks tested as native policy/trust components; a full financial journey under that profile is not yet added.

**Physical qualification:** Actual funded workloads and physical metering, payouts/refunds on a disposable network.

**Ratification:** Programs, profiles, allocations and budgets remain unratified.

### WP3 — NOT_IMPLEMENTED

**Implemented scope:** Partial: periodic SQLite FULL/WAL checkpoints, hash lineage, restart-safe closure, CPU/block reset rejection, signed nonpayable scoped observations, private stdin service and candidate OCI recipe.

**Files:** `src/Provider/MeteringService.php`, `src/Resources/Metering/SignedObservation.php`, `system-services/metering/meter.php`, `system-services/metering/Dockerfile`, `tests/Economics/meter-checkpoints.php`

**Executed tests:** Existing meter journal: 11 PASS; final checkpoint/recovery/signature/corruption suite: 13 PASS. Actual local observation recorded separately; this is not funded workload or physical qualification evidence.

**Remaining defects/limitations:** WP is partial, not complete: no continuous funded-workload collector composition, approved persistent OCI mount/transport or qualified vendor/storage/network collectors. RAM peaks are not time integrals. OCI image was not built/run.

**Physical qualification:** Disposable containerd host, actual cgroup/device/volume/netns identity, restart/reboot/counter/reset/cutoff tests.

**Ratification:** Host collector permissions, meter profiles, evidence retention and measurement trust bases.

### WP4 — NOT_IMPLEMENTED

**Implemented scope:** Partial: native timelocked service manifest records and exact local opt-in adapter to existing SystemServiceDefinition; explicit nonroot/no-host-network/no-mount execution profile.

**Files:** `src/Network/ResourcePolicyState.php`, `src/Compute/Containerd/GovernedServiceDefinition.php`, `src/Economics/Ledger.php`, `docs/economics/proposals/GREENWOOD_NETWORK_SYSTEM_SERVICES_V1.md`

**Executed tests:** network-policy.php: included in 28 PASS (manifest mutation/root/network/mount/opt-in denial).

**Remaining defects/limitations:** Automatic OCI fetch/distribution, consent file installation, durable staging/cutover/health/rollback, supervisor/Ansible integration, attestor/coordinator executables remain unimplemented. Existing two-seed Ansible allowlist intentionally unchanged.

**Physical qualification:** Disposable runtime distribution/update/outage qualification.

**Ratification:** Exact artifacts, schema/roles/privileges, activation/grace/rollback, replica policy and budget references.

### WP5 — TESTED_LOCAL

**Implemented scope:** Three trust labels represented honestly: dev/bootstrap cannot fund native payments; independent opt-in programs require qualified source/roles/E2 and distinct nonprovider domains; quotes bind immutable trust policy; no legacy contract rewrite.

**Files:** `src/Network/AttestationTrust.php`, `src/Resources/Metering/SignedObservation.php`, `src/Economics/ServiceSettlement.php`, `docs/economics/proposals/GREENWOOD_ATTESTATION_TRUST_V1.md`

**Executed tests:** network-policy.php: 28 PASS total; checkpoint/signature suite: 13 PASS; existing CPU/resource adversarial settlement suites PASS.

**Remaining defects/limitations:** Logical reviewed-domain fixtures share real administration. No physical independence or trusted bare-metal consumed-CPU source demonstrated. Native payable bootstrap is deliberately unavailable; independent end-to-end financial journey still needs a dedicated test.

**Physical qualification:** External beneficial-control/custody/source review; direct qualified measurement; malicious telemetry/refusal/collusion tests.

**Ratification:** Qualification authorities, conflict policy, quorum, source profiles, revocation/challenges and any bounded payable bootstrap extension.

### WP6 — TESTED_LOCAL

**Implemented scope:** Native versioned operator roles, immutable controller/domain identity, review/evidence/participation windows, freshness bounds, revocation, delayed E0/E1/E2 policies; same-domain keys count once; no E3/dynamic-validator upgrade.

**Files:** `src/Network/ResourcePolicyState.php`, `src/Economics/Ledger.php`, `src/Network/AttestationTrust.php`, `tests/Economics/network-policy.php`, `docs/economics/proposals/GREENWOOD_DECENTRALIZATION_EPOCHS_V1.md`

**Executed tests:** network-policy.php: 28 PASS total; native governed bootstrap record retained in both full certified replays.

**Remaining defects/limitations:** Reviewed availability is externally asserted, not live uptime proof. Automatic heartbeat/qualification workflow and actual founder authority migration/sunset are not implemented. Operator registry grants neither validator votes nor treasury custody.

**Physical qualification:** Genuine separate control, sustained real participation, profile-qualified sources and custody.

**Ratification:** Domain review/anti-Sybil/challenge process, E1/E2 thresholds/windows/maximum evidence age/notice; any authority sunset and separate dynamic membership upgrade.

### WP7 — TESTED_LOCAL

**Implemented scope:** Nonpayable deterministic bounded infrastructure cost projection using existing GWD and separate treasury approval; no circular self-approved invoices or issuance expansion.

**Files:** `src/Network/InfrastructureCostModel.php`, `tests/Economics/infrastructure-cost.php`, `docs/operations/operator-onboarding.md`

**Executed tests:** infrastructure-cost.php: 7 PASS (cap, overflow, zero work, bounded operators/activity).

**Remaining defects/limitations:** Projection only: no automatically payable infrastructure invoice protocol or measured profitability claim. Real external costs are unknown.

**Physical qualification:** Actual operator equipment/electricity/connectivity/availability cost evidence.

**Ratification:** Infrastructure budget/category, eligibility, independent invoice review, availability/activity rates and bootstrap sunset.

### WP8 — NOT_IMPLEMENTED

**Implemented scope:** Partial: existing GAP SDK types 13/14 and document domains; UTF-8 canonical cross-language signatures; durable exact-byte outbox and actual certified-chain reconciliation; bounded native policy/archive RPCs; status/consent DTO and read-only operator CLI.

**Files:** `src/Provider/SettlementOutbox.php`, `src/Economics/ServicePresentation.php`, `src/Rpc/HttpApi.php`, `scripts/resource-operations.php`, `tests/Economics/signing.mjs`, `tests/Economics/settlement-outbox.php`, `/home/djordanjones/Apps/intown-metaverse-naf-main/greenwood-avatar-protocol/sdk/typescript/signing.mjs`, `/home/djordanjones/Apps/intown-metaverse-naf-main/greenwood-avatar-protocol/sdk/typescript/signing.d.mts`

**Executed tests:** SDK: 30 PASS with actual PHP signature/codec verification. Outbox: 13 PASS with real native actions, SQLite restart and three certificate-verified blocks.

**Remaining defects/limitations:** Automatic coordinator/attestor outbox relay, RPC/UI journey integration and operator workflow remain incomplete. DTO helper is not a finished dashboard. RPC-specific new-route integration assertions still need expansion.

**Physical qualification:** Real transport uncertainty, operator service deployment and customer workflow qualification.

**Ratification:** Exact trust/refund/consent disclosure and operational key custody.

### WP9 — TESTED_LOCAL

**Implemented scope:** Authenticated archive_v1 terminal compaction retaining quote/binding protection and budgets; private journals; checkpoint corruption rejection; stale-head watchdog wired to provider funding; bounded uncertain nonce retries.

**Files:** `src/Economics/ServiceSettlement.php`, `src/Provider/FinalizedHeadWatchdog.php`, `src/Economics/HostedFundingGuard.php`, `src/Provider/HostedRuntimeFunding.php`, `src/Provider/SettlementOutbox.php`, `tests/Economics/head-watchdog.php`, `tests/Economics/resource-settlement.php`

**Executed tests:** Watchdog: 10 PASS; resource settlement/archival: 28 PASS per allocation; original-allocation full 24,487-block archival replay PASS; outbox 13 PASS; checkpoint corruption included in final 13 PASS.

**Remaining defects/limitations:** Local hardening is partial release qualification. 10,000 live/100,000 lifetime quote+tombstone/1,000 immutable program limits remain finite; no indefinite accumulator migration. Block history must remain. Treasury lost-quorum recovery is not implemented. Stale-head polling is not real-time CPU enforcement. Full security/load/retention/rollback review outstanding.

**Physical qualification:** Stalled chain/network restriction, actual hard resource cutoff, disk/WAL faults and distributed recovery soak.

**Ratification:** Retention/export/capacity migration, signing quorum recovery, watchdog deadline, secret custody and security review.

### WP10 — BLOCKED_EXTERNAL

**Implemented scope:** Preserved disabled qualification/metering inventories; documented exact qualification staircase and production HOLD.

**Files:** `deploy/ansible/inventory/qualification.ini`, `deploy/ansible/inventory/metering.ini`, `docs/economics/proposals/RELEASE_GATES_UNIVERSAL_RESOURCES.md`, `docs/operations/resource-metering.md`, `docs/operations/operator-onboarding.md`

**Executed tests:** Local simulations/replays only; no physical qualification performed.

**Remaining defects/limitations:** No disposable host or independently administered source/operator has been designated. Existing operating cluster excluded explicitly.

**Physical qualification:** All physical staircase A/C/distributed lifecycle/soak requirements remain pending; simulated logical role tests are not independent witnesses.

**Ratification:** Production policies, allocations, authorities, budgets, artifacts, security/physical acceptance and explicit activation authorization.

## Next runnable source work

WP3: wire the private journal service to a reviewed narrow collector and persistent OCI data policy, then add explicit device/storage/network adapters and unsupported results. WP4: implement finalized-policy distribution with local consent, interrupted-start reconciliation and staged cutover through the existing runtime/supervisor. WP8: connect independent trust programs, attestor/coordinator and durable relay into existing product/operator flows. Expand native trust-program financial/RPC integration, archival capacity/load and deployment adversarial tests. These are source gaps, not external-host excuses.

No permanent genesis, production funding/flags, protected signing keys, operating-cluster services, dependencies, source or artifact pushes were touched. Allocation candidates, fixed supply, no-burn policy, 60-second target and frozen V1 wire formats remain unchanged.
