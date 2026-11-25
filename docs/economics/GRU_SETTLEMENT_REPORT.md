# GRU settlement integration — November 14, 2026

> **Historical scope — reviewed November 24, 2026.** This finite-CPU milestone predates hosted/resource settlement, explicit Epoch 0 profiles, SDK integration and recovery implementation.
> Read the [economics index](README.md) for current source and policy precedence,
> and [project status](../PROJECT_STATUS.md) for release/installation and qualification.

Latest update: [HOSTED_SETTLEMENT_REPORT.md](HOSTED_SETTLEMENT_REPORT.md) supersedes this finite-compute milestone report for hosted support and service-history replay. The text below records the earlier milestone and its then-outstanding work.

Status: finite compute CPU settlement implemented locally. The complete requested production milestone is **incomplete**. Hosted recurring billing, operational independent metering/attestation, lost-quorum recovery, a real operating budget and distributed acceptance remain outstanding. No permanent genesis, keys, live payments, deployment or remote push occurred.

The 10/25/30/34/1 model is the preferred **budget-modeling candidate**, not a ratified allocation. The original template remains unchanged; the separate proposed template retains a 300,000-GWD subsidy ceiling per 43,200-block window. Six decimals, 1B fixed issuance, 60-second target blocks, current validator emissions, no burning and operator fee compensation remain unchanged.

## Implemented financial path

`ComputeRequest → approved program + accepted provider offer → native escrow → prior-block funded ComputeAssign → ComputeReceiptV2 → independent usage quorum → challenge period → payment + refunds`

- New signed envelope type **13** carries canonical version-1 financial actions, genesis binding and ordinary account nonce/signature authorization. Frozen ComputeRequest/ComputeAssign/receipt encodings are unchanged.
- `ServiceSettlement` belongs to the native `Ledger`, is cloned with candidate State, committed in the state root, persisted through SQLite and replayed through normal State transitions. Balances, reserve debits, fees and financial status change atomically. Validators do not execute containers, read cgroups or call price APIs.
- Governance creates immutable service programs using **4-of-5 approvals and 20,160 blocks**. Programs select the provider, service/catalog identities, explicit beneficiaries, subsidy percentage, total and related-beneficiary GWD budgets, total GRU ceiling, rational CPU-to-GRU conversion, interval and independent attestor quorum. At least two distinct attestors are required; neither provider nor program customers can attest their own usage. This is a candidate threshold-attestation trust model, not proof that measurements are objectively true.
- Catalog hashes commit the CPU conversion, GRU precision and terminal-status billing rules; a program cannot change the meaning of the quoted catalog hash.
- Customer funding accepts the exact signed provider offer, request, assignment generation and deadline. Quote payer/provider/service/catalog must match. Accepted offers are single use, including across different requests. Providers can include a signed `offer_nonce` for distinct otherwise identical offers.
- Maximum calculated customer contribution and subsidy contribution move into native escrow. The request's `max_payment`, protected reserve balance, rolling spending limit, outstanding commitments, program budget, related-beneficiary budget and GRU allowance all constrain admission. Direct subsidy grants remain prohibited. A subsidy pause prevents new funding; existing contractual obligations are not confiscated.
- On economic chains, **all compute assignments require matching funding from an earlier finalized block**. Same-block funding/assignment, provider mismatch, generation mismatch and active parallel financial commitments are rejected. The existing provider supervisor continues consuming finalized assignments; no second scheduler, daemon or receipt protocol was created.
- Receipt acceptance alone pays nothing. Billing requires a matching existing terminal receipt plus independent signatures binding chain, genesis, financial commitment, receipt ID, catalog and CPU measurement. CPU above the request's accepted CPU-time envelope is rejected. GRU conversion and price/split rounding use checked integers.
- Quote expiry limits acceptance. Settlement verifies an accepted offer at its acceptance height, allowing payment after that offer expires.
- This adapter bills independently approved **consumed CPU for any terminal status**. A runtime failure with zero CPU costs zero; a timeout can charge actual approved CPU. Peak RAM is not memory-hours; output bytes are not egress; GPU/storage are unsupported.
- A verified claim waits **4,320 blocks**. The customer or an authorized attestor can dispute it before maturity. A quorum signs a dispute-specific pay/deny resolution. Denial refunds both sources; approval starts a fresh delay and cannot enter an unlimited customer challenge loop.
- Unattested commitments refund after the service deadline plus **4,320 blocks**. Unresolved disputes refund after **43,200 blocks** from challenge. These are explicit candidate protocol choices requiring review: a refund timeout can leave a provider uncompensated if the attestation council is unavailable. Quorum members can collude; key independence and actual evidence collection need operational validation.
- Final payment is permissionlessly relayable, pays the provider once, refunds unused customer escrow and restores unused subsidy to its protected reserve. Program counters release commitments and record actual consumption. No GWD is minted; no GRU currency moves.

Example in base units: lock customer 200 + subsidy 800; verify charge 750; pay provider 750; charge customer 150 + subsidy 600; refund customer 50; release protected subsidy 200.

## Inspection and signing

Public paginated routes: `/v1/economics/programs`, `/v1/economics/services`, `/v1/economics/services/{id}`. Financial status is distinct from execution-receipt finality. Supply categories include native service escrow without double-counting reserves/accounts.

PHP clients can use `ServiceAuthorization::transaction()` and `ServiceAuthorization::approval()`. The CLI supports `service-sign`, `quote-sign`, `usage-sign`, `resolution-sign`; it never broadcasts or creates keys. The existing GAP JavaScript signer has not yet been extended for type 13.

## Fresh-chain deployment identity

Removed production historical chain constants from assignment documents, deployment challenges, request authentication, registry authority and control-policy verification/publication. Protected controller configuration must supply `deployment_identity` with explicit `chain_id`, `genesis_hash`, `registry_contract_id`, and `registry_wasm_hash`. Pins are immutable within the process. Chain status now exposes genesis hash. Registry authorization checks the configured genesis and code identity.

Registry-auth additionally requires `GREENWOOD_CHAIN_ID`, `GREENWOOD_GENESIS_HASH`, `GREENWOOD_APP_REGISTRY_CONTRACT`, and `GREENWOOD_APP_REGISTRY_WASM_HASH`; there is no historical chain default. Replace/review protected configuration and policy snapshots before starting the fresh cluster. Historical scopes appear only in explicit local test fixtures.

**Hosted deployments on native-economic chains fail closed with `HOSTED_SERVICE_SETTLEMENT_NOT_QUALIFIED`.** The hosted assignment/controller/operator path has not been connected to financial intervals. Do not bypass this gate or reuse a containerd `lease_id` as a billable identity. Existing execution journal `SETTLED` semantics remain unchanged.

## Remaining engineering and acceptance

1. Implement a hosted adapter bound to actual Application Registry authority, assignment generation and provider payout; finalize interval funding before deployment/renewal; preserve interval identity across restart; reject overlapping capacity claims. Reuse the shared monetary engine rather than duplicating balances. Currently the engine accepts only `compute_cpu_v1` programs.
2. Implement operational independent measurement collectors and attestor reconciliation. Existing provider-signed cgroup CPU facts alone are insufficient for an Institute subsidy. Test inflated signed receipts against independent observations, council outage and collusion. Extend explicitly versioned catalogs before adding other meters.
3. Extend the shared JavaScript SDK and application UX for acceptance, disputes, refunds and financial status.
4. Design/review and implement lost-quorum recovery. The document's 5-of-7 council and 30-day delay are examples, not authorization to silently adopt new recovery trust. Recovery must only replace signers, never spend; preserve immutable floors, publish events and support a reviewed incumbent challenge mechanism. No recovery shortcut was added.
5. Collect real validator/provider/Institute costs, revenue and capacity, then model 10–20 years and 16-year security coverage plus contingency. Do not infer affordability from token runway or raise caps solely because demand rises.
6. Design bounded archival/pruning with durable replay tombstones: the current candidate deliberately stops admitting programs at 1,000 and commitments at 10,000 rather than growing state without bound. This admission limit is not an indefinitely scalable production implementation.
7. Run fresh disposable distributed compute + hosted + contracts/registry acceptance, restart/replay, operational key custody, independent security review and soak before permanent genesis ratification.

## Evidence scope

`tests/Economics/settlement.php` uses actual source classes, signed envelopes, existing compute lifecycle, real Ed25519 attestations, native conservation and SQLite checkpoint hydration/tamper rejection. Its deterministic replica check is a local transition replay, **not full certified service-history replay or a distributed provider execution test**. The existing certified-chain suite separately exercises economic state roots, certificates, persistence and restart replay. No new container execution or public-chain payment finality is claimed.

Machine-readable current results live in `evidence/gru-integration-summary.json`; earlier reports/evidence are historical unless explicitly rerun there.


Current results: each allocation passed 64 core economic checks, 65 compute-settlement
checks (including SQLite and RPC fixtures), and 25 certified-chain foundation
checks. Shared fresh-chain identity checks: 9. Deployment/registry regression suites:
12 passed. PHP syntax checks: 91 files passed; `git diff --check` passed. These are
local results; full certified service-history replay and distributed service
execution remain unverified.
