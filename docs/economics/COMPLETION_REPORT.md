# GWD Institute economics — implementation completion report

> **Historical scope — reviewed November 24, 2026.** Completion refers to the reported local milestones. Its older allocation candidates, missing-source lists and deployment statements are not the present status.
> Read the [economics index](README.md) for current source and policy precedence,
> and [project status](../PROJECT_STATUS.md) for release/installation and qualification.

Initial report: November 13, 2026. Updated November 14, 2026. Scope: production-target source implementation; no requirement to preserve the existing deployment's state.

**Status: native monetary/treasury foundation implemented and tested. Full production economics acceptance is NOT complete. Nothing has been deployed, pushed to a remote, or used to generate permanent mainnet genesis/keys.**

## Current GRU v2 update — November 14

[GRU_V2_COMPLETION_REPORT.md](GRU_V2_COMPLETION_REPORT.md) records the new versioned resource catalog, hardware extensions, composite quotes and shared resource settlement validation. All catalog defaults remain disabled; physical qualification is blocked under the user's explicit operating-cluster restriction. No production activation or allocation ratification occurred.

## Current hosted integration update — November 14

[HOSTED_SETTLEMENT_REPORT.md](HOSTED_SETTLEMENT_REPORT.md) supersedes the earlier statement that no hosted adapter exists. A local hosted CPU adapter now shares the native engine, checks the actual Application Registry WASM, and integrates funded-interval gates with controller, provider start/recovery and expiry reconciliation. A complete 24,487-block hosted history now also passes certified reconstruction to an identical state root. Production remains on HOLD: independent metering, runtime cutoff/halt behavior and physical distributed qualification are not demonstrated. Current evidence: [hosted-integration-summary.json](evidence/hosted-integration-summary.json). No candidate switch, production configuration, genesis, wallet or live deployment has been activated.

## Current GRU integration update — November 14

The latest implementation and outstanding scope are recorded in [GRU_SETTLEMENT_REPORT.md](GRU_SETTLEMENT_REPORT.md). Native finite-compute CPU escrow, independent quorum authorization, disputes, payments and refunds now exist; the earlier descriptions below of all service settlement as unimplemented are superseded for this adapter only. Hosted recurring settlement remains disabled, and full production acceptance remains incomplete. Fresh-chain deployment authorization now requires configured chain/genesis/registry pins; hosted starts on economic chains fail closed until funded hosting integration is qualified. The preferred 10/25/30/34/1 model remains unratified.

Current machine-readable test results: [gru-integration-summary.json](evidence/gru-integration-summary.json). Earlier counts below describe earlier runs, not additional current coverage.

## Confirmed decisions

- 60-second target blocks; no cadence change.
- Six-decimal GWD; checked integer base units; total issuance 1,000,000,000 GWD / 1,000,000,000,000,000 base units.
- No fee burn. All configured network transaction fees compensate eligible operators; provider service payments are separate.
- Five protected reserve classes. The supplied 40/20/15/24/1 allocation is encoded in the production policy template; its economic affordability still requires budget validation.
- GRU is nontransferable service accounting, not a token, issuance, peg or redemption promise.

## Implementation delivered

| Area | Result |
|---|---|
| Integer arithmetic | Exact six-decimal parsing, checked sums/multiplication and overflow-safe integer conversion |
| Transfer encoding | Positive signed-range u64 amounts via native transaction type 11; standard PHP CLI, native CLI, shared SDK and InTown producer support the format |
| Treasury wire format | Type 12, canonical JSON without floats/duplicate keys; approvals bind chain, genesis, policy version, exact proposal and nonce |
| Genesis | Economic policy and reserve definitions committed into genesis; exact allocation/supply validation; bootstrap operations funding debited from working reserve |
| Protected reserves | Separate native balance namespace, not ordinary accounts; ordinary and contract-account paths cannot alias reserve identifiers |
| Spending approvals | 3-of-5 normal; 4-of-5 large; 4-of-5 security-policy changes; fixed immutable approval/delay floors |
| Timelocks | 4,320 / 10,080 / 20,160 blocks from threshold attainment, representing 72h / 7d / 14d at target cadence |
| Budgeting | 43,200-block rolling windows; queued proposals reserve capacity; execution rechecks budgets/balances; related-beneficiary aggregation spans reserves |
| Operations | Protected replenishment source, balance/payment/window ceilings, fee-inclusive routine debit checks; contract-call bypass blocked |
| Emergency pause | Authorized guardians pause discretionary reserves only; bounded expiry; repeated renewal requires governance reset/policy change; no spending authority |
| Rotation | Delayed policy replacement invalidates pending/queued approvals under the previous policy version |
| Emissions | Existing 10 / 7.5 / 5 / 2.5 / 1 GWD schedule in integer units, 525,960-height nominal years; reserve-funded only; partial final reward then stop |
| Distribution | Explicit equal-genesis-validator policy with deterministic remainder distribution; not proposer-only or a claim of generic infrastructure-work verification |
| Fees | Explicit genesis-configured flat category fees, all credited to validator operators; no burn; invalid/rejected transactions charge nothing |
| Supply conservation | Reserves + native service escrow + ordinary accounts + contract balances must equal fixed gross issuance after every economic transaction and block release |
| Execution | Same economic transitions in block building, independent validation and replay; failed actions leave original state unchanged |
| Persistence | Derived SQLite economic snapshot, verified against finalized root; authoritative history replay reconstructs it |
| RPC | Correct reserve-inclusive supply, explicit base-unit metadata, actual release/fee counters, compact status and paginated proposal/policy inspection |
| GRU quotations | Configurable quote-authority quorum, chain/genesis/expiry/catalog/service/payer/provider bindings, exact conversion, GWD/GRU maxima and conserved subsidy/user split |
| Operator tooling | Policy/genesis validation and offline transfer/treasury signing; no automatic key/genesis generation or broadcast |

## Mainnet blockers and deliberate exclusions

1. **GRU settlement is incomplete.** Quote verification is not consumption verification. No consensus metering catalog, approved usage-attestation policy, program/beneficiary GRU budget ledger, payment escrow, refund/dispute path, or receipt-to-provider settlement is enabled. The subsidies reserve rejects direct token withdrawal to prevent bypassing this requirement.
2. **Production identities and parameters are not supplied.** The template contains no fake signer/guardian keys. Populate five independent signer public keys, guardians, registered beneficiary groups, operations account, pause duration, explicit fee amounts and bootstrap funding. Empty/null fields fail validation.
3. **Budget affordability is not established.** Token allocations and ceilings do not prove vendor purchasing power, sustainable resource rates, fee revenue, or operational runway. The 400-million-GWD reserve arithmetic is not a funding guarantee.
4. **Calendar guarantees are not implemented.** Delays and rolling windows use finalized height. The target remains 60 seconds; chain stalls delay progress. No node wall-clock or exchange API becomes consensus authority.
5. **Operational and independent security qualification remain.** The local three-validator test verifies real signed certificates, execution, persistence and restart; it does not run a distributed network transport/soak, real deployment, adverse network conditions, full contract-runtime regression, infrastructure acceptance or independent audit.
6. **Not implemented:** guardian recovery of permanently lost treasury signer quorum, automatic vesting/liquidity reserves, temporary program-specific cap increases, dynamic fee policy, hardware signer integration, or dynamic infrastructure reward eligibility. Caps can be changed through the implemented delayed four-signer policy update; that is not a temporary program-budget mechanism.

The production profile is supported by the code; there is no artificial mainnet-name or qualification-only restriction. These blockers reflect missing functionality/inputs, not a legacy preservation requirement. Do not certify or perform permanent genesis cutover as though they were complete.

## Validation and evidence

Reproducible tests live under `tests/Economics`. Machine-readable results are saved in `evidence/`:

- `core.json`: arithmetic, exact issuance, deterministic input ordering, bootstrap, authorization, delays, duplicate/replay rejection, pauses, split-payment escalation, rotation, supply conservation, fee distribution, GRU quote ceilings and reserve exhaustion.
- `certified-chain.json`: three independent chain instances, real three-of-three Ed25519 precommit certificates, matching roots, manipulated-economic-block rejection, SQLite snapshot consistency, restart/replay and RPC inspection.
- `signing.json`: JavaScript SDK signs u64/treasury envelopes; actual PHP protocol classes verify signatures, canonical bytes and hashes. No mock substitutes for PHP validation.

The current local PHP install lacks a normally loaded SQLite driver, so the certified-chain command loads the existing matching PHP 8.5 PDO SQLite extension from `/tmp/intown-php-sqlite/usr/lib/php/20250925/pdo_sqlite.so`. Provision a normal supported runtime for production; do not deploy with this temporary test path.

Commands:

```sh
php tests/Economics/run.php
php -d extension=/tmp/intown-php-sqlite/usr/lib/php/20250925/pdo_sqlite.so tests/Economics/certified-chain.php
node tests/Economics/signing.mjs
python3 scripts/simulate-institute-economics.py --self-test --write-report
```

The signing test needs subprocess access to PHP. Its first sandboxed attempt was denied/timed out; it passed with subprocess access. The JavaScript test resolves the sibling GAP SDK by default; `GREENWOOD_SDK_DIR` can specify its installed location.

## Operator handoff

1. Review the actual code and the blockers above before release approval.
2. Populate `PRODUCTION_POLICY.template.json` into a separate reviewed policy file; do not substitute fixture keys or fee values.
3. Validate it with `php scripts/economics-cli.php validate-policy POLICY.json`.
4. Put the reviewed policy under `economics` in the proposed genesis alongside real validator public keys/endpoints and zero-funded ordinary entries, with operations bootstrap drawn from its working reserve. Validate with `validate-genesis` before any permanent genesis ceremony.
5. Use `transfer-sign` with a GWD decimal in the native economic CLI. The standard `bin/greenwood-cli transfer-sign` accepts integer base units and selects type 11 on economic chains.
6. Treasury action files must carry the current `policy_version`; the native CLI binds the loaded genesis hash. Inspect policy/proposals through `/v1/economics/policy` and `/v1/economics/proposals` before signing.
7. Finish verified GRU settlement, production runtime/infrastructure validation, distributed soak and independent security review before the mainnet acceptance gate.

## November 13 verification result (historical)

94 targeted economic checks passed: 58 core/state checks, 23 certified-chain/persistence/RPC checks, and 13 cross-language signing checks. Six existing shared SDK codec/transport regression tests also passed. PHP syntax checks passed for 22 implementation/test files. These results do not close the production blockers listed above.

## November 14 economic design revision

The supplied November 14 proposal is archived in `proposals/ECONOMIC_DESIGN_2026-11-14.md`. [The five-scenario comparison](ECONOMIC_DESIGN_COMPARISON.md) evaluates the original and proposed allocation with identical assumptions. Neither model is a final allocation resolution. The original production policy template remains unchanged; the separate `PROPOSED_10_25_30_34_1_POLICY.template.json` records the new candidate and its tighter caps.

Implemented provider-signed GWD/GRU offers with rational integer rates and payout-identity binding, without USD price data. Reference-indexed pricing now requires explicit opt-in and is rejected by default. Customer acceptance, consumption verification, escrow and subsidy payments remain outside this quote verifier and remain inactive.

Added disjoint RPC supply categories: uncommitted protected reserves, queued protected withdrawals, restricted operations accounts, unrestricted accounts and contract-held balances. Native service escrow is explicitly reported as disabled. These describe ledger transferability, not exchange liquidity or beneficial ownership.

Both allocation profiles pass 64 core checks and 25 certified-chain/persistence/RPC checks each. The shared wire-format evidence contains 13 passing signing checks. A same-key comparison verifies that changing allocations changes the genesis hash and state root; no live genesis or client pin was replaced. Latest evidence: `evidence/design-review-summary.json`.

**Production acceptance remains blocked.** In addition to verified GRU settlement and real production configuration, the stress review identifies lost-quorum recovery and actual operating-budget evidence as unresolved. Losing two of five keys leaves ordinary-payment authority but prevents four-key rotation. The arithmetic does not resolve that custody risk or prove provider profitability.
