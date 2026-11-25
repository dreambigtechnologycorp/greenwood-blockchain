# Economics: current rules and evidence

Reviewed against the working source on **November 24, 2026**. Start with
[project status](../PROJECT_STATUS.md) for release and deployment state and the
[November 18 adoption record](PRODUCTION_POLICY_ADOPTION_2026-11-18.md) for the
operator's selected policy. This index explains existing decisions; it does not
approve a new budget, asset valuation, release, or activation.

This guide is cluster independent. Network protocol invariants, genesis-bound
parameters, approved service programs and local operator responsibilities are
separate layers. The November 18 record is the adopted DreamBIG Epoch 0 policy;
it is not authority for an unrelated operator to spend its reserves or change
network membership. Joining an existing network uses its actual trusted genesis
and finalized policy. See project status for the particular installation's progress.

The source review covers the `bd529a5` artifact baseline and subsequent
storage/startup source changes. The latter are not yet incorporated into rebuilt immutable
artifacts. Documentation review does not establish activation or qualification.

## Monetary rules

GWD is the native settlement asset. Exactly **1,000,000,000 GWD** is preminted at
genesis, with **six decimals**: 1 GWD = 1,000,000 base units and total issuance is
1,000,000,000,000,000 base units. There is no post-genesis minting or burning.
Emissions transfer existing GWD from the protected security reserve; fees are
distributed to validator operators. Provider service payments are separate.

| Protected reserve | Selected allocation | GWD |
| --- | ---: | ---: |
| Security/emissions | 15% | 150,000,000 |
| Grants | 25% | 250,000,000 |
| Infrastructure subsidies | 30% | 300,000,000 |
| Institute treasury | 25% | 250,000,000 |
| Working operations | 5% | 50,000,000 |

These balances are protected ledger state, not one unrestricted wallet or an
approved expenditure. Fresh-production allocation validation enforces these
proportions; replay of historical alternatives does not adopt them.

## Adopted Epoch 0 policy reference

The following limits describe the November 18 adoption, not universal values for
all historical chains or an operator's authority to alter existing genesis.
The selected rolling 43,200-block limits are 10,000 GWD
for operations and zero for grants, subsidies, and discretionary Institute
treasury. Security follows its separate height-based release schedule. The
operations account has a 5,000-GWD balance cap, 500-GWD fee-inclusive routine
debit cap, and 10,000-GWD rolling outflow cap. Its 1,000-GWD bootstrap comes from
the operations allocation and counts against that reserve's first window.
See the adoption record for purposes, fee amounts, governance, recovery, cash
budgets, and organizational controls. Five distinct signing keys do not establish
five independent custodians; the adopted reference policy discloses sole-founder
custody. Calendar notice, invoices, USD ceilings and beneficial-control review are
organizational requirements, not automatically enforced protocol facts.

## Emissions and resource accounting

The reward schedule is 10 / 7.5 / 5 / 2.5 / 1 GWD per finalized block over the
existing four-year epochs and terminal epoch. A nominal year is 525,960 blocks;
60 seconds is a target, not a guaranteed clock. Releases are distributed equally
among genesis validators with deterministic integer remainders. An insufficient
security reserve pays its remaining balance, then stops. Token runway does not
establish cash affordability, GWD liquidity, or provider profitability.

GRU is **nontransferable resource accounting**, with its own declared integer
scale. It is not a second token, a redeemable asset, a GWD peg, or a guaranteed
USD price. Accepted provider-signed quotes bind resource definitions, exact
rational rates, limits, identities, expiry, and trust policy. The historical
$0.01/GRU planning reference and conditional 1 GWD/GRU examples are not an
adopted market valuation or a production quote.

## Operator roles and admission

A provider supplies approved capacity and accepts customer quotes. A meter
collects scoped evidence. An attestor authorizes evidence under an approved trust
profile. A coordinator prepares/relays permitted transactions. Validators execute
the deterministic ledger, and governance approves programs and policy changes.
One host or organization can hold several disclosed roles without acquiring
independence. Role registration does not automatically add validator votes,
appoint treasury signers, grant host access or release protected subsidies.

For a homelab joining the network, use [operator onboarding](../operations/ROLE_BASED_ONBOARDING.md)
and [provider operations](../provider-operations.md). Obtain the actual network's
trusted public identity/configuration and required eligibility/program approvals;
do not create a replacement genesis or copy another operator's keys. There is
no implemented open dynamic-validator admission mechanism.

## Funding, execution, and payment

The implemented path is approved program → accepted quote → native escrow →
finalized funded assignment/interval → execution → permitted usage evidence →
challenge/dispute handling → explicit settlement or refund. Request creation,
runtime readiness, finalized execution receipts, and provider-journal `SETTLED`
do not establish final GWD payment.

Explicit zero reserve budgets are supported. With `subsidy_percent: 0`, a program
may set both `budget_units` and `beneficiary_budget_units` to zero: those fields
limit subsidy spending, not customer escrow. Customer payments still need funds,
an approved program, an accepted quote, bounded GRU/resource capacity, eligible
beneficiaries, and the declared trust profile. Zero does not mean unlimited and
does not bypass funding. A positive subsidy cannot use a closed reserve budget.

Finite compute assignment requires matching funding in an earlier finalized
block. Hosted starts/recovery require the receiver's **COMMITTED** assignment
journal and an active funded interval for the exact document, generation,
provider, chain, and genesis. Missing protected funding configuration fails with
`HOSTED_SERVICE_SETTLEMENT_NOT_QUALIFIED`; enabling a candidate setting cannot
create escrow or prove qualification. Expiry/stale-head enforcement is polling
based and still requires physical cutoff, restart, and outage acceptance.

Fresh-production policy requires `explicit_profiles_v1` and resource adapters
`compute_resources_v2` or `hosted_resources_v2`. Older CPU adapters remain for
historical replay. `BOOTSTRAP_OPERATOR_ATTESTED` permits approved customer-funded
Epoch 0 services with **zero subsidy**, finalized E0 policy, and disclosed common
provider/meter/attestor control. The adopted DreamBIG profile discloses that
common control; different keys or nodes do not make it independent. `INDEPENDENT_ATTESTED`
requires qualified separate sources/domains and finalized E2 eligibility. That
physical independence has not been demonstrated. Independent claims cannot fall
back silently to operator telemetry when witnesses are unavailable.

## Developer source map

| Concern | Source and focused checks |
| --- | --- |
| Amounts, selected allocation, explicit zero limits | [Amount](../../src/Economics/Amount.php), [Policy](../../src/Economics/Policy.php), [zero reserve checks](../../tests/Economics/zero-reserve-budgets.php) |
| Reserve transitions, fees, emissions, recovery | [Ledger](../../src/Economics/Ledger.php), [RecoveryPolicy](../../src/Economics/RecoveryPolicy.php), [recovery checks](../../tests/Economics/governance-recovery.php) |
| Programs, escrow, claims, refunds, archive tombstones | [ServiceSettlement](../../src/Economics/ServiceSettlement.php), [customer-funded checks](../../tests/Economics/customer-funded-programs.php) |
| Accepted pricing and resource limits | [GruQuote](../../src/Economics/GruQuote.php), [ResourceManifest](../../src/Resources/ResourceManifest.php), [resource guide](../guides/RESOURCE_METERING.md) |
| Hosted runtime authorization | [HostedFundingGuard](../../src/Economics/HostedFundingGuard.php), [HostedRuntimeFunding](../../src/Provider/HostedRuntimeFunding.php), [boundary checks](../../tests/Economics/hosted-boundaries.php) |
| Bootstrap versus independent evidence | [AttestationTrust](../../src/Network/AttestationTrust.php), [ResourcePolicyState](../../src/Network/ResourcePolicyState.php), [source validation](../guides/SOURCE_VALIDATION.md) |

From the repository root, `php tests/Economics/zero-reserve-budgets.php` and
`php tests/Economics/customer-funded-programs.php` are focused keyless boundary
checks. Follow [testing prerequisites](../testing/overview.md) for broader
SQLite, FFI/Wasmtime, SDK, and certified-history suites. A passing fixture is
software evidence only; it does not approve a real program or installation.

## Document precedence and historical coverage

The following map covers every existing economics prose file. Dated reports and
proposals retain their original test results and assumptions; words such as
"current", "not implemented", "not ratified", and "nothing deployed" in their
bodies refer to their checkpoint. Historical task prompts are not current
operator instructions. Use this index and project status before following them.

| Document | How to use it now |
| --- | --- |
| [Production policy adoption](PRODUCTION_POLICY_ADOPTION_2026-11-18.md) | Selected operator-authorized terms, with explicit exceptions to the original drafts; not evidence of on-chain activation or institutional/custody completion |
| [Epoch 0 governance draft](proposals/PRODUCTION_GOVERNANCE_EPOCH0_DRAFT_2026-11-18.md) | Original digest-bound draft; adopted only as clarified by the adoption record; its positive-only-limit source note and old corporate GWD budget are superseded |
| [Hyperscaler budget draft](proposals/HYPERSCALER_APP_BUDGET_DRAFT_2026-11-18.md) | Digest-bound November 18 price snapshots and planning scenarios; no live tariff, final resource admission, or fixed GWD price |
| [Institute treasury decision](INSTITUTE_TREASURY_DECISION.md) | Initial architecture rationale; burn language, unset percentages, and missing-source findings are historical |
| [Implementation plan](IMPLEMENTATION_PLAN.md) | November 13 implementation baseline; precision, clock, settlement, recovery, and policy selection have progressed |
| [Completion report](COMPLETION_REPORT.md) | November 13–14 local milestones, older allocations and counts; not present release acceptance |
| [Economic comparison](ECONOMIC_DESIGN_COMPARISON.md) | Selected-allocation arithmetic; its pending-ratification language predates November 18 |
| [Simulation results](SIMULATION_RESULTS.md) | Offline arithmetic and hypothetical exchange-rate examples; not authorized subsidy spending |
| [GRU settlement report](GRU_SETTLEMENT_REPORT.md) | Finite CPU milestone; hosted settlement, SDK and recovery gaps were later addressed in source |
| [Hosted settlement report](HOSTED_SETTLEMENT_REPORT.md) | Hosted CPU milestone and historical commands/results; fresh-production programs now require resource V2 profiles |
| [GRU V2 completion](GRU_V2_COMPLETION_REPORT.md) | Catalog/quote/settlement milestone; historical two-candidate allocations and topology restrictions |
| [Universal implementation](UNIVERSAL_RESOURCE_IMPLEMENTATION_2026-11-14.md) | Original WP matrix, later expanded; no inferred physical qualification |
| [Universal continuation](UNIVERSAL_RESOURCE_CONTINUATION_2026-11-14.md) | Later local integration; FULL/WAL describes that checkpoint, not the current rollback-journal source policy |
| [Production implementation](PRODUCTION_IMPLEMENTATION_2026-11-15.md) | November 15 source evidence; four-node scope and unratified-policy gates are historical |
| [Independent CPU plan](INDEPENDENT_CPU_METERING_PLAN.md) | Qualification requirements for independent CPU claims, not a prohibition on the separately adopted zero-subsidy E0 profile |
| [Genesis tokenomics proposal](proposals/GENESIS_TOKENOMICS_PROPOSAL.md) | Historical 40/20/15/24/1 and burn candidate; neither governs current production |
| [GRU economic model](proposals/GRU_ECONOMIC_MODEL.md) | Accounting rationale; reference prices, old allocation and 1 GWD/GRU are proposals |
| [Economic design](proposals/ECONOMIC_DESIGN_2026-11-14.md) | Historical 10/25/30/34/1 alternative and sensitivity analysis |
| [Economics review](proposals/ECONOMICS_REVIEW_2026-11-14.md) | Historical subsidy ceilings and recovery example; no 5-of-7 council was adopted |
| [Infrastructure integration](proposals/GRU_INFRASTRUCTURE_INTEGRATION_2026-11-14.md) | Pre-integration design; keeps containerd leases distinct from financial commitments |
| [GRU implementation review](proposals/GRU_IMPLEMENTATION_REVIEW_2026-11-14.md) | Finite-compute review; a payable claim still requires an explicit settlement transaction |
| [Hosted review](proposals/HOSTED_REVIEW_AND_ACCEPTANCE_2026-11-14.md) | Historical hosted acceptance roadmap and counts |
| [Universal resources design](proposals/GRU_V2_UNIVERSAL_RESOURCES_2026-11-14.md) | Design intent, not proof every proposed device or collector is supported |
| [Implementation work packages](proposals/GREENWOOD_CODEX_IMPLEMENTATION_PLAN_2026-11-14.md) | Historical engineering directive; later status records supersede task permissions and incomplete-work lists |
| [Kickoff prompt](proposals/GREENWOOD_CODEX_KICKOFF_PROMPT_2026-11-14.txt) | Archived task input, not instructions to rerun deployment or implementation |
| [Resource mapping](proposals/GREENWOOD_UNIVERSAL_RESOURCES_V1.md) | Candidate mapping to actual catalog V2/type 14; old allocation references are historical |
| [Attestation trust proposal](proposals/GREENWOOD_ATTESTATION_TRUST_V1.md) | Earlier nonpayable-bootstrap restriction superseded by the implemented and adopted zero-subsidy E0 path |
| [System services proposal](proposals/GREENWOOD_NETWORK_SYSTEM_SERVICES_V1.md) | Earlier missing distribution/installer source has since been implemented; physical acceptance remains separate |
| [Decentralization epochs](proposals/GREENWOOD_DECENTRALIZATION_EPOCHS_V1.md) | E0–E2 framework; provider joins do not confer validator votes or treasury custody; E3 remains unsupported |
| [Universal release gates](proposals/RELEASE_GATES_UNIVERSAL_RESOURCES.md) | Historical deployment restriction and source-gap list; use project status and current runbooks |

## Data and evidence

All 60 tracked economics JSON files parsed successfully in the November 24 review.
Parsing is structural inspection, not a rerun of reported tests. The disabled
catalog also passed `ResourceCatalog::validate`: 36 entries, zero enabled. Both
policy templates remain intentionally incomplete and fail `Policy::validate` on
their missing signer identities; they are not ready-to-install policies.
The tracked evidence text/log files were retained. No JSON, CSV, log, or pinned
policy artifact was regenerated during this documentation update.

| Data group | Meaning and limitations |
| --- | --- |
| [Production policy template](PRODUCTION_POLICY.template.json) and [topology template](PRODUCTION_TOPOLOGY.template.json) | Incomplete public-input scaffolds, not install-ready production artifacts; do not substitute fixture identities or bypass unset fields |
| [Candidate policy](CANDIDATE_POLICY.json), [older proposed template](PROPOSED_10_25_30_34_1_POLICY.template.json) | Candidate data now models the selected allocation but retains pending-ratification labels, unset reserve budgets and older operations ceilings. The other template retains 10/25/30/34/1. Neither overrides adopted spending terms or validates as the completed production policy |
| [Comparison JSON](ECONOMIC_DESIGN_COMPARISON.json), [simulation JSON](SIMULATION_RESULTS.json) | Offline arithmetic; any pending-ratification status is historical and does not undo the adoption record |
| [Disabled resource catalog](RESOURCE_CATALOG_V2.disabled.json) | All default entries remain disabled; enabling a definition requires a qualified policy, not merely valid JSON |
| [Evidence directory](evidence/) | Core, signing, GRU, hosted and V2 result snapshots; each command, allocation, source hash and environment constrains its scope |
| [Universal-resource evidence](evidence/universal-resource-2026-11-14/) | Local baseline, checkpoint, IPC, SDK, certified-replay and continuation results, including failures and synthetic trust fixtures |
| [November 15 release evidence](evidence/production-release-2026-11-15/) | Distinct attempted, final-local and strict-policy runs; top-level failed run and native-unavailable report are not successes |
| [November 16 integration](evidence/source-integration-2026-11-16/focused.json) | Focused source checks, not complete physical deployment evidence |
| [November 18 pricing evidence](evidence/hyperscaler-pricing-2026-11-18/) | Dated provider extracts, scenario inputs and results; no automatic price refresh or approved token valuation |

The adoption record and its two referenced proposal documents are byte-preserved
because their SHA-256 digests are bound into policy/release provenance. Corrections
belong in a new explicit record; changed artifact hashes need operator review and
approval. Historical evidence never certifies a later dirty tree, new build, or
physical installation merely because its tests once passed.
