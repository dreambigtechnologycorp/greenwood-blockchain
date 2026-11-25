# Institute treasury: production implementation specification

> **Historical scope — reviewed November 24, 2026.** This is the November 13 baseline plan. Later source implements native settlement, hosted/resource integration and recovery; selected policy is recorded in the November 18 adoption.
> Read the [economics index](README.md) for current source and policy precedence,
> and [project status](../PROJECT_STATUS.md) for release/installation and qualification.

Status: architecture adopted by the user; consensus implementation and production qualification NOT complete. November 13, 2026.

Update: [Historical candidate GWD/GRU data](CANDIDATE_POLICY.json) records the newly supplied provisional allocations and six-decimal simulation inputs. The user explicitly selected **no fee burn; operator compensation**. That supersedes the burn candidate in earlier proposals; historical source documents remain unchanged.

Authority: [Institute treasury decision](INSTITUTE_TREASURY_DECISION.md). This specification records the implementation work and unresolved parameters. It does not authorize or generate permanent genesis, choose allocation percentages, change the live network, or certify infrastructure acceptance.

## 1. Settled requirements

- Retain the established 60-second target block interval. This is not an open economic parameter.
- Issue exactly 1,000,000,000 GWD once, at genesis. No post-genesis minting or reminting burned units.
- Allocate directly to protected reserves; no intermediate account controls the full supply.
- Consensus enforces restrictions. Institute approvals cannot override them.
- Security rewards move existing units from the emissions reserve to eligible recipients.
- Fee burning is disabled under the user-selected policy. Network transaction fees compensate eligible operators. Gross issuance and outstanding supply remain fixed unless a separately approved protocol change introduces a burn. Transfers, reserve releases and fees cannot create units.
- Normal discretionary payments: 3-of-5 approvals and a 72-hour policy delay. Large payments: 4-of-5 and seven days. Signer/security-policy changes: 4-of-5 and fourteen days. Exact consensus clock semantics remain unresolved.
- Guardians can pause specified actions; they cannot spend or change policies.
- No allocation percentages or production signer keys are selected by this specification.
- DreamBIG infrastructure/development compensation is an approved expenditure, not unrestricted control of Institute reserves.

## 2. Baseline findings before implementation

These findings record the starting point. See [the completion report](COMPLETION_REPORT.md) for delivered code, test evidence and remaining production blockers.

Source review is of this checkout, not a fresh GitHub or live-network audit.

| Source | Finding | Required work |
|---|---|---|
| `src/Genesis/Genesis.php` | Initial ordinary accounts; balances committed as u64 | Commit economic parameters, protected reserve identities and policies |
| `src/Node/Configuration.php` | Nonnegative individual balances; no exact aggregate genesis issuance check | Checked sums, unique economic identifiers, required policies and complete allocation validation |
| `src/Transaction/Transfer.php` | Positive u32 payment amounts | Versioned amount encoding and validation across every producer/consumer |
| `src/Transaction/Envelope.php` | V1 transfers require exactly 36 payload bytes | Versioned decoding; do not silently reinterpret old signed bytes |
| `src/State/State.php` | Account and contract balances; no Institute reserve state machine | Protected namespaces, proposals, approvals, locks, budgets, burns and supply conservation |
| `src/Node/ChainConfig.php` | Float reward schedule; explicitly not executed as economic state mutations | Integer consensus parameters and deterministic economic transitions |
| `src/Node/Chain.php` | Shared build/execute/replay path; timestamp zero | Integrate identical economics in every execution path; explicitly choose a consensus clock |
| Persistence, RPC and client signers | Existing V1 economic surface | Root-committed economic state, replay reconstruction, accurate reports, new signing formats |

## 3. Decisions required before production parameters can be frozen

| Decision | Requirement / recommendation for review |
|---|---|
| Decimal precision | Recommend six decimals as a candidate, not an adopted choice. One GWD would equal 1,000,000 base units. |
| Allocation budget | Explicit reserve amounts and any bootstrap ordinary-account balances; optional vesting/liquidity reserves require an approved mandate. Sum must equal gross issuance exactly. |
| Large-payment threshold | Define both per-payment and aggregate classification. No numerical threshold is assumed. |
| Budget windows | Define per-reserve and any shared Institute budget domains, window lengths, ceilings and reservation rules. |
| Consensus clock | Retain 60-second target blocks. Specify timelock/quote expiry semantics and idle-chain behavior; the target cadence itself is settled. |
| Emission eligibility | Define recipients, amounts, rounding, disputed/missed rewards and recipient eligibility evidence. |
| Emission exhaustion | Define stop behavior or an explicitly funded fallback. An indefinite positive reward cannot be promised from a finite reserve. |
| Fees | User selected 0% burn / operator compensation. Define fee calculation, eligible recipients, distribution, rounding and failed-transaction treatment. The current code still declares the older split. |
| Signers / guardians | Supply public identities, independence requirements, guardian threshold, pause scope, expiry, extension and unpause rules. No master key. |
| Vesting / liquidity | Beneficiaries, cliff/release rules, permitted assets/counterparties and change authority, if funded. |
| Bootstrap | Fund the initial governance/relayer transaction fees explicitly; do not rely on extra issuance or unrestricted reserve access. |

### Precision and wire compatibility

At six decimals, gross issuance is 1,000,000,000,000,000 base units, within PHP's signed 64-bit range on the supported 64-bit runtime. A u32 transfer can carry only 4,294.967295 GWD at that precision. This illustrates why changing display decimals alone is insufficient.

All balances, transfer values, rewards, fees and vesting calculations must use checked integer arithmetic. Do not assume that u64 wire encoding means PHP can represent the entire unsigned range. Reject overflow before addition/multiplication and avoid floating-point JSON amounts. Inventory the PHP runtime, CLI, InTown physical transport, GAP SDK, contracts/ABI, RPC and fixtures before assigning new wire versions. Preserve historical V1 decoding and fail closed on unsupported versions.

### Time semantics

At a continuous 60-second cadence, 72 hours corresponds to 4,320 blocks, seven days to 10,080 blocks, and fourteen days to 20,160 blocks. These conversions use the established target cadence; exact timelock enforcement and idle-chain semantics still require implementation. The current chain has zero timestamps and does not guarantee continuous block production. A height delay may take longer in calendar time or stop progressing while the chain is idle. Do not advertise calendar guarantees without implementing and testing the supporting consensus clock/cadence.

## 4. Root-committed economic state

Introduce native protected reserves with distinct, domain-separated identifiers. They must not be ordinary Ed25519 accounts with labels. Ordinary transfers, contract attached value, compute operations and every other debit path must be unable to spend a reserve outside its authorized transition.

Commit at least:

- Economic schema/version, base-unit precision and immutable gross issuance.
- Each reserve's type, balance, policy version and applicable budget domains.
- Signer/guardian sets, approval rules, pause state and timelock parameters.
- Proposal IDs, typed actions, exact beneficiaries/amounts, nonce, expiry, approvals, threshold height/time, execution eligibility and terminal status.
- Spent/reserved budgets, working-balance ceilings and replenishment history.
- Emission progress, eligible releases, cumulative burns and fee distribution state.

Define canonical byte encodings, domain-separated hashes, bounded collection sizes and deterministic ordering. Include every economically relevant field in the state root and genesis commitment. SQLite remains a rebuildable index, not a source of monetary authority.

## 5. Proposal and authorization state machine

Recommended implementation semantics, pending detailed protocol review:

1. Validate a typed action against the current reserve policy; derive a canonical proposal ID.
2. Bind approvals to protocol version, chain ID, trusted genesis identity, reserve ID, proposal/action type, recipient, amount, proposal nonce, policy version and expiry. Policy-change proposals bind the complete replacement policy.
3. Count distinct authorized signers once. Reject duplicate keys, unknown signers and signatures from another chain/genesis/reserve/policy.
4. Start the timelock when the required approval threshold is reached, not when the proposal is first created. Approvals collected late must not bypass the delay.
5. Reserve applicable budget capacity deterministically or otherwise prevent queued proposals from promising the same capacity. Recheck limits at execution regardless.
6. At execution, recheck policy version, threshold, maturity, expiry, pause, balance, beneficiary constraints and aggregate budgets. Atomically debit and credit once, then commit the audit state.
7. Cancellation/expiry releases only the proposal's reserved capacity. Define authorized cancellation explicitly.
8. Recommended rotation behavior: invalidate outstanding approvals/proposals from the replaced policy version and require fresh approval under the new policy. No inherited threshold or shortened delay.

Aggregation must cover related withdrawals over each applicable period. Moving funds through another reserve, splitting payments or replenishing working accounts must not evade limits. Avoid unnecessary double-counting when the same approved movement appears in multiple accounting views.

Emergency pause uses a separate, narrow authorization path. It cannot replace signers, transfer funds, shorten delays or change spending policy. Explicitly define maximum pause duration, extensions and recovery from unavailable guardians before activation.

## 6. Emissions, fees and supply invariants

Use a block-level economic transition shared by proposal building, independent validation, finalization and replay. Specify transaction ordering relative to releases and fee disposition. Duplicate or reordered application must not create a second release.

The currently declared first sixteen reward years would release 52,596,000 GWD at 525,960 releases per nominal year. This is a calculation from the current declared schedule, not an approved reserve allocation. The terminal one-GWD reward requires finite-reserve exhaustion behavior.

Required invariants, in base units:

```
gross_issuance = 1_000_000_000 * units_per_GWD
cumulative_burns = 0  # selected no-burn policy
outstanding_supply = gross_issuance
sum(all distinct value-holding balances) = outstanding_supply
reserve_release = matching recipient credits
fee_debit = operator_credits  # provider service settlement is separate
```

Inventory ordinary balances, protected reserves, contract balances, and any independently held escrow. Never count an escrow claim or contract-storage accounting entry twice as another balance. Negative balances, overflow, unauthorized minting, reserve overspend and excess burns reject the transition without partial monetary mutation.

Failed transactions need explicit fee semantics consistent with existing receipts and atomic state handling. Burn counters and emission counters must reflect executed state, not hard-coded RPC fields.

## 7. Implementation sequence and reviewable deliverables

| Stage | Deliverable | Acceptance |
|---|---|---|
| E0 | Ratified precision, budget model and complete parameter decision record | No unresolved production monetary parameters; no invented allocations |
| E1 | Versioned codecs, checked amounts, canonical economic state and genesis definitions | Cross-language vectors; exact genesis sum; unsupported formats rejected |
| E2 | Protected reserve types and enforcement in every debit path | No ordinary/account/contract/compute bypass; root and replay consistency |
| E3 | Integer emissions, exhaustion and fee accounting | Conservation across valid/failed transactions and epoch/exhaustion boundaries |
| E4 | Proposals, approvals, timelocks, rotation, budgets and guardians | Distinct signatures; no replay, stale policy, early execution or split-payment bypass |
| E5 | Read-only economic RPC and administrative proposal/signing tools | Accurate reserves/outstanding/circulating definitions; keys never required by node automation |
| E6 | Disposable three-validator qualification | Identical roots; invalid block rejection; restart/replay; adversarial and security review evidence |
| E7 | Release and infrastructure acceptance | Both independent gates pass before permanent genesis |
| E8 | Approved cutover, registry/contracts and supporting services | End-to-end validation on the approved release; no claim of completion before evidence |

Do not modify the existing live genesis or enable an alternate monetary engine in a deployment script. Parameter changes after launch require the explicitly approved on-chain change mechanism; node-local edits cannot change consensus economics.

## 8. Required adversarial acceptance cases

- Allocations below/above gross issuance; duplicate reserve/signer IDs; overflowing base-unit conversion; absent mandatory policy.
- Transfer limits beyond u32; fractional rewards represented exactly; deterministic fee remainders.
- Ordinary transfers or contract/compute paths targeting reserve debit authority.
- Forged, duplicate, replayed, cross-chain and wrong-policy approvals; proposal execution twice.
- Threshold reached after the nominal waiting period; maturity/expiry boundaries; chain stalls; policy rotation while queued.
- Payment splitting across recipients, proposals, replenishments and linked budget domains; competing proposals for the same capacity.
- Guardian theft, unauthorized unpause, excessive pause extension, signers lost, and cancellation after expiry.
- Empty and transaction-bearing blocks, emission epoch boundaries, insufficient reserve, disputed recipients and burn exhaustion.
- Conservation of balances including contract value; failed transaction atomicity; restart/rebuild after every economic transition class.
- Three validators independently rejecting a block with a manipulated reserve, fee, emission, approval or supply result.

## 9. Cutover gate

Infrastructure acceptance (including the user's Node 4/NFS/cooling/network/runtime work) is independently pending. Protocol economics acceptance is independently pending. Neither gate substitutes for the other.

Do not generate, fund or distribute permanent one-billion-GWD genesis until the implementation, invariant tests, emission behavior and required security validation have passed, and the production parameters/release are approved.


## November 14 candidate review

The proposed 10/25/30/34/1 allocation and tighter caps have been compared with the original in [five scenarios](ECONOMIC_DESIGN_COMPARISON.md), and both were exercised through the native economic test suite. This is not allocation ratification. Provider-signed GWD offers are implemented; reference-indexed pricing is opt-in. Supply reporting now separates protected, pending, restricted, unrestricted and contract-held value without double counting. GRU settlement and lost-quorum recovery remain production blockers.
