# Universal-resource implementation — continuation checkpoint

**Superseded for current scope/status:** [November 15 production checkpoint](PRODUCTION_IMPLEMENTATION_2026-11-15.md). The existing four-node cluster is the authorized destination; the disposable-host requirement below is historical.

Production remains **HOLD**. This checkpoint supersedes the earlier report's descriptions of missing source components, while preserving its historical test results. The full WP0–WP10 milestone is not yet qualified or complete.

## Source now implemented

- Funded hosted metering is composed with the existing funding guard and application registry. Samples retain immutable chain/genesis, assignment, commitment and manifest scope. Recurring intervals can continue on the same runtime; a polling boundary is explicitly UNVERIFIED and never invents an exact CPU split.
- The meter runs over private Unix IPC with SQLite FULL/WAL, exclusive process ownership, bounded frames, crash recovery and no network/containerd privileges. A governed private journal mount is permitted only with explicit local consent and protected ancestry.
- Governed service installation uses existing image transfer and containerd primitives, a durable installation journal, exact image/config consent, hard CPU/memory/PID profiles, readiness approval, staged cutover/drain, revocation and exact rollback validation. Journal/runtime identity is pinned. Unknown resources are not adopted; exclusive leases and read-only vacancy inspection protect recovery. Canonical installation audit exports can be signed by an explicitly supplied operator key.
- A disabled-by-default watcher reads coherent policy pages from a certificate-pinned node (or local loopback). Its node assertion is explicitly distinguished from local replay verification. Ansible stages disabled configuration and a manual unit only.
- Qualified-source attestation, shared native evidence validation, claim preparation, exact-byte outbox relay and nonce/finality reconciliation are connected as reusable workflows. Source proof and witness signatures remain distinct. Future participation and reuse of one controller key across supposedly independent domains are rejected.
- Studio has a read-only commitment inspector in the existing deployment page. It checks application binding, chain/genesis and coherent finalized state. Runtime readiness and a provider journal's SETTLED label cannot establish payment. Economic reads remain disabled unless explicitly configured; no environment configuration was changed.
- Explicit `archive_v2` retains authenticated product lookup metadata. `archive_v1` behavior remains unchanged. Quote/binding replay protection and budget counters remain intact.

## Verification evidence

Evidence is under `docs/economics/evidence/universal-resource-2026-11-14/continuation/`.

The latest broad regression run passed 35 suites and 132 PHP syntax checks (`verification.json`). The completed certified replay records **24,487 blocks**, actual registry WASM, three validator signatures, native policy and archival, identical restart state root, and no live transactions (387.085 seconds). SDK signing records **32** cross-language assertions.

Focused current results: installation/audit journal **24**, governance/epoch **35**, independent native funding/attestation/payout/revocation/refund/rollover/archival journey **24**, Studio read adapter **7**, and Studio status/amount projection **17** assertions. Studio tests do not establish browser rendering or live finality. The final private IPC suite passed 12 checks, including SIGKILL/recovery and uncertain interval boundaries (`meter-ipc.json`).

## Follow-up: RPC/archive integration

The independent native settlement journey now includes **58 checks per allocation candidate**, passing for both `40-20-15-24-1` and `10-25-30-34-1`. The added 34 checks exercise the actual HTTP handler against the native journey's terminal state: archive-v2 assignment identity, amounts and ordering; pagination; exact chain/state pins; payment versus execution status; runtime funding denial after archival; mixed live/archive lookup; and unchanged archive-v1 schema and direct lookup. Invalid second-record digests, duplicate batches and archive replay are rejected without state-root mutation.

Evidence: `evidence/universal-resource-2026-11-14/continuation/archive-rpc.json`, including source hashes. Both edited PHP test files pass syntax checks, and `git diff --check` passes. The prior broad regression and certified replay results above remain historical; they were not rerun for this test-only extension. HTTP transport, certificate-backed archival RPC history and browser rendering remain unqualified: these new RPC checks explicitly use a synthetic finalized tip. No production source behavior changed in this follow-up.

## Current WP matrix

| WP | Local state | Remaining source or verification | Physical requirement | Ratification |
|---|---|---|---|---|
| 0 | Audit and baseline preserved | Refresh final completion evidence after remaining integration | No operating hosts contacted | None inferred |
| 1 | Catalog/capability/manifest accounting tested | Unsupported collectors remain disabled; schema is not hardware proof | Actual hardware discovery/assignment | Resource profiles and payable activation |
| 2 | Unified finite/hosted settlement and terminal RPC/archive-v2 integration tested | Certificate-backed archival RPC history and transport qualification | Real funded workload/payout/refund journey | Programs, allocations and budgets |
| 3 | Funded CPU/IO diagnostics, private IPC, restart and interval boundaries implemented | Authoritative accelerator, storage and device adapters; retention rollover/capacity qualification; OCI execution | Disposable cgroups/containerd/devices/volumes/network | Source qualification, privileges, retention |
| 4 | Policy reader/watcher, consent, staging, cutover, revocation, recovery and audit source implemented | Actual OCI distribution/update/rollback verification; attestor/coordinator OCI packaging and operational composition | Approved images and disposable runtime | Digests, roles, profiles, health/grace/rollback |
| 5 | Explicit trust profiles and qualified-source workflow tested | No physical independence or bare-metal truth established | Independent source/control/key custody review | Reviewers, source trust, quorum, disputes |
| 6 | Native roles, participation windows, anti-key-reuse and E0–E2 controls tested | E3/validator and authority migration are separate upgrades | Real sustained independent participation | Thresholds/windows/notice and authority sunset |
| 7 | Deterministic bounded cost projection tested | Measured costs and actual compensation program | Real operator cost/availability evidence | Budget, eligibility, custody and compensation |
| 8 | SDK, signing, claim preparation, outbox relay and Studio inspector source connected | Browser/full-app qualification; operational coordinator deployment | Real transport and operator/customer workflow | Consent and key custody |
| 9 | Tombstones, stale-head handling, private journals and uncertain-state recovery implemented | Full retention/load/fault qualification; long-lived capacity migration; treasury lost-quorum protocol | Disk/WAL faults, real hard cutoff and distributed recovery | Retention, recovery authority, security review |
| 10 | Disabled inventories and gates preserved | External qualification pending | Designated disposable and independent hosts do not exist in the supplied inventory | Production acceptance and explicit activation |

No source/artifact pushes, dependency installation, permanent genesis, protected signing-key access, mainnet funding, production flag changes or operating-cluster changes were performed. Existing source and dirty working trees were preserved. The 60-second target, 1B fixed supply, no burn, allocation candidates and frozen receipt/request encodings remain unchanged.
