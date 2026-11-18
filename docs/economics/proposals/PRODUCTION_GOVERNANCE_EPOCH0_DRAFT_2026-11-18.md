# Greenwood production governance policy — Epoch 0 draft

**Date:** November 18, 2026. **Status:** recommendation for review, not ratified.
**Operator and initial sole key custodian:** DreamBIG, through its designated founder.
The founder's legal name and the Institute's actual approving authority must be
recorded before adoption; this draft does not invent either appointment or authority.
**Planning input:** approximately $1,000/month cash operating costs, excluding labor
and transportation. Dollar figures below assume USD; confirm before adoption.
**Scope:** first 90 calendar days of founder-operated production, with reviews at
30, 60 and 90 days. Review dates do not automatically change on-chain permissions.

## 1. Authority and monetary invariants

DreamBIG may operate the initial validators, providers and approved network services.
The lack of another independent infrastructure operator does not itself prevent
launch. Disclose that consensus infrastructure, administration and initial governance
are under one controlling person. Multiple machines, accounts, devices or keys do
not establish administrative independence.

Create exactly 1,000,000,000 GWD at genesis with six decimal places. No later minting
or burning. Allocate 150,000,000 to security/emissions, 250,000,000 to grants,
300,000,000 to infrastructure subsidies, 250,000,000 to Institute treasury and
50,000,000 to working operations. No automatic personal founder allocation.
Reserve balances remain separately protected; allocations are not spending approvals.
GRU remains nontransferable accounting; GWD is the settlement asset.

Retain the existing protocol emissions schedule and operator fee distribution.
At the initial 10 GWD/block, 43,200 blocks distribute 432,000 GWD across the three
genesis validators. With DreamBIG controlling all three, this is founder-controlled
operator revenue, separate from the discretionary limits below. Report it explicitly;
do not imply the operations cap limits all GWD received by DreamBIG. These are
transfers from the security reserve, not new supply. Provider receipts are separate.

## 2. Initial spending authorizations and ceilings

Recommended maximum reserve releases over a rolling 43,200-finalized-block window:

| Reserve | Epoch 0 ceiling | Permitted use |
| --- | ---: | --- |
| Working operations | 10,000 GWD | Replenish the protected operations account against an approved budget |
| Ecosystem grants | 0 GWD | Closed until a named milestone-based program is separately approved |
| Infrastructure subsidies | 0 GWD | Closed; no independent verification exists at launch |
| General Institute treasury | 0 GWD | Closed to discretionary distributions during the initial period |
| Security/emissions | Existing protocol schedule | No discretionary withdrawals |

These are loss/exposure limits, not cost-derived valuations or spending targets.
Unused capacity does not carry forward as an entitlement. Include queued commitments
when testing reserve capacity. No splitting invoices, recipients or transactions to
avoid limits. A later grant, subsidy or treasury budget requires a new reviewed policy
proposal; the 90-day review does not unlock reserves automatically.

**Source support:** explicit zero reserve ceilings are accepted by policy validation.
The keyless `tests/Economics/zero-reserve-budgets.php` regression checks closed
payment budgets, subsidy reservation rejection, monetary conservation and later
budget changes through existing approval thresholds and timelocks. Missing or
malformed limits remain invalid; zero is not an omitted approval. This source
support does not ratify this draft or authorize production genesis. Customer-funded
programs may use `subsidy_percent: 0` and explicit zero `budget_units` and
`beneficiary_budget_units` even with a closed subsidy reserve. Their customer
escrow remains subject to quoted prices, GRU/capacity limits and approved trust
profiles. Positive subsidy requests still require a positive authorized reserve
ceiling. The keyless `tests/Economics/customer-funded-programs.php` checks program
validation/installation, reserve isolation and settlement/refund boundaries; it
does not qualify live signed funding or physical metering.

## 3. Cash budget and DreamBIG-related payments

Authorize a planning budget of $1,000/month plus up to $200/month contingency for
necessary, evidenced external costs: total $1,200/month and $3,600 for the first
90 days. Unused contingency is not compensation. Labor, salary, management fees,
transportation and capital purchases outside this approved schedule are not included.
Each requires its own prospective authorization; do not create automatic arrears.

Record every expense in its actual currency, with invoice, purpose, beneficiary,
conflict disclosure and transaction reference. No fixed GWD/USD price or redemption
promise is created. A proposed GWD reimbursement needs an explicit, time-bounded
GWD amount and defensible conversion/payment terms approved before execution.
Both the cash allowance and token ceilings must be satisfied; if they cannot be,
pause the payment and revise the budget through approval rather than assume liquidity.

If network cash revenue or usable GWD liquidity is absent, DreamBIG supplies the
cash operating funds. Record whether those payments are contributions or a separately
approved liability; they are not automatically loans owed by the Institute. Token
emissions and internal transfers are not evidence that cash bills can be paid.

During sole-person custody, permit only documented third-party cost reimbursement
under this adopted budget, with no markup. No discretionary founder draw, founder
grant or salary is authorized. Publish related-party expenditure totals monthly.
The founder signs in both roles with a disclosed conflict; this is not independent
approval. Recommend a second human reviewer before introducing discretionary
compensation, but that reviewer need not operate infrastructure.

All DreamBIG-controlled beneficiaries must be identified consistently as related.
The source has beneficiary groups and aggregation, but does not discover beneficial
ownership or enforce conflict recusals, invoice checks, fiat ceilings, or this ban
on salary. These remain explicit organizational controls unless implemented later.

## 4. Protected operations account and bootstrap funding

Recommend genesis parameters: maximum operations balance **5,000 GWD**, maximum
routine debit **500 GWD including its network fee**, and operations outflow maximum
**10,000 GWD per rolling 43,200 blocks**. The account is organizational working
capital, not a personal wallet. Hold its key separately from governance, validator,
provider and recovery keys. No automatic top-up daemon. Replenishment requires
normal treasury approval and the applicable delay.

Authorize a one-time **1,000 GWD** bootstrap transfer from working operations into
that account. It is part of the 50-million-GWD allocation, not extra issuance, and
counts toward the first reserve-release window: at most 9,000 GWD of additional
working-reserve replenishment remains in that window. Operations-account debits
have their own outflow counter; do not double-count a replenishment as a vendor cost.

**DreamBIG operating liquidity (draft):** DreamBIG Technology Corp needs GWD in
permitted transaction accounts to operate paid network services. Physical spending
does not automatically create GWD, authorize reimbursement, or unlock a reserve.
The protected operations account only permits routine transfers; it cannot itself
submit service-funding or contract transactions. Approved bootstrap transfers must
therefore fund named ordinary administration/application payer accounts, with
recorded purposes and organizational spending controls. These accounts do not
inherit the protected account's on-chain debit ceilings.

The trial allowance below does not authorize ongoing hosting of DreamBIG's ten
applications or private client projects. Before launch, document a distinct corporate
application budget, its authorized source of existing GWD, named payer accounts and
expected resource quotes. Sources can include earned validator distributions,
customer revenue, or a separately authorized transfer; none is created by this draft.
Do not recategorize corporate/client application costs as network operations without
explicit purpose approval. Founder custody does not remove related-party disclosure.

Initial sub-budgets, including transaction fees:

| Purpose | Maximum GWD |
| --- | ---: |
| Governance signing-account fee balances, up to 10 each | 50 |
| Named system-service/administration fee balances | 300 |
| Controlled acceptance and initial customer trials | 150 |
| Unspent protected operational buffer | 500 |
| Total | 1,000 |

Fund only named, recorded accounts, after checking address derivation and purposes.
Not all funds must leave the protected account immediately. Do not treat funded
ordinary accounts as still subject to the operations-account protections: their
subsequent spending is not constrained by that account's caps. Keep their balances
small, reconcile them, and return unused trial funds where practical.
Trial allowance is at most 10 GWD per approved beneficiary including fees; no open
faucet, no operator-registration reward and no claim that operator-funded tests are
organic customer demand. Trial funding is an operations expense, never a disguised
independently verified subsidy. Customers later acquire GWD through actual authorized
sales/transfers or approved programs; no guaranteed market or redemption is promised.

Suggested fee amounts for evaluation: transfer **0.001 GWD**, governance **0.01 GWD**,
contract transaction **0.01 GWD**, compute transaction **0.01 GWD**. Contract execution
and purchased infrastructure still obey their separate execution/resource limits and
accepted prices. These nominal fees require load/abuse testing and actual cost review;
they do not by themselves establish adequate spam resistance or economic sustainability.

### Draft corporate application funding recommendation — November 18

**Superseded budget basis:** the [25-application hyperscaler comparison](HYPERSCALER_APP_BUDGET_DRAFT_2026-11-18.md)
now recommends 20,260 reference GRU per 30 days for DreamBIG's ten apps and 50,650
for the entire 25-app portfolio, including contingency. GWD remains unset pending
accepted provider quotes; the historical 1 GWD/GRU proposal is shown conditionally.
The earlier 10,000/30,000 GWD and $0.10/GWD scenario below is retained for comparison
only and is not the current recommended application-budget basis. All remain draft.

Recommend an initial **10,000 GWD corporate application envelope**, with a maximum
**30,000 GWD over the first 90 calendar days**, funded from DreamBIG's **already
received validator distributions**. These are internal corporate spending limits,
not new protocol-enforced account limits or an additional reserve release. Review
actual accepted quotes and usage after 30 days; do not automatically replenish or
increase token limits when an assumed exchange rate changes.

The ultimate reserve source is **security/emissions through the existing validator
schedule**, not a discretionary security-reserve withdrawal. At the existing aggregate
10 GWD per finalized block, DreamBIG controlling all three validators receives
10,000 GWD after 1,000 blocks, before any other expenditure. At the target minute
per block that is approximately 16 hours 40 minutes, not a guaranteed deadline.
The 90-day envelope uses 3,000 blocks' gross distributions. Funds must actually
arrive and remain available before transfer to application payer accounts.

Keep grants, infrastructure subsidies and general Institute discretionary spending
closed under this draft. Do not use an independent-verification subsidy to fund
self-attested corporate workloads. The separate 1,000 GWD network bootstrap proposal
is not increased or reclassified here. If application operation is required before
validator earnings arrive, use only a separately approved, purpose-specific startup
transfer or defer the deployments; this draft does not authorize an advance.

Illustrative monthly planning basket, **not measured demand or a cloud quote**:

| Use | USD reference allowance | GRU reference allowance |
| --- | ---: | ---: |
| Ten modest applications, assumed $50 each | 500 | 50,000 |
| Named initial client pilots | 250 | 25,000 |
| Incremental storage, backup, egress and contingency | 250 | 25,000 |
| Total | 1,000 | 100,000 |

This resource-value allowance is not another $1,000 cash payment on top of the
reported physical operating costs and is not derived from those costs. Remove
bundled resources before adding storage/egress charges. Application counts do not
establish capacity or demand; databases, replicas, background workers and traffic
must be inventoried before approving final quotes. Client hosting beyond the pilot
allowance should be funded by clients or a separately recorded corporate sponsorship.

`GruQuote` uses a reference convention of 1 whole GRU = $0.01 in its opt-in
reference-pricing path. Production settlement currently uses provider-signed
GWD-per-GRU prices, with explicit resource-manifest rates, not an automatic
hyperscaler price oracle. For a $3,000 / 300,000 whole-GRU 90-day reference basket:

| Hypothetical USD/GWD planning rate | GWD per whole GRU | GWD for 90 days |
| --- | ---: | ---: |
| 0.01 | 1 | 300,000 |
| 0.10 | 0.1 | 30,000 |
| 1.00 | 0.01 | 3,000 |

The recommended 30,000 GWD envelope corresponds to the **illustrative $0.10/GWD
case only**; this is neither a market forecast nor a peg, redemption promise or
approved provider quote. If actual accepted GWD-per-GRU prices differ, change
workload scope or separately review the budget. Whole GRU must be converted using
the quoted `gru_scale`; GWD uses six-decimal integer base units.

Benchmark methodology for the final catalog: select comparable regional Linux
on-demand resource baskets from AWS, Azure and Google Cloud; normalize sustained
CPU performance, RAM, storage durability, egress and included allowances; record
retrieval time and use a documented median/weighting. Burstable VM bundles cannot
be averaged directly with sustained dedicated allocations. Raspberry Pi capacity
and service availability must be measured rather than equated to cloud vCPU counts.
No verified three-provider aggregate or token valuation was produced for this draft.

Reference checks: Google's official general-purpose price listing surfaced
`e2-standard-2` (2 vCPU, 8 GiB) at $0.06701142/hour, approximately $48.92 per
730-hour month before separate resources. AWS's official Lightsail bundle listing
surfaced a compute-optimized 2-vCPU/4-GB bundle at $42/month with bundled storage
and transfer. These are scale checks for the $50 assumption, not matched products
or a complete application estimate. Source search results were available; direct
page retrieval failed during drafting. Azure's dynamic pricing page did not yield
a comparable verified numeric quote, so no three-provider average is asserted.

Sources: [Google general-purpose pricing](https://cloud.google.com/products/compute/pricing/general-purpose),
[AWS Lightsail bundles](https://docs.aws.amazon.com/lightsail/latest/userguide/amazon-lightsail-bundles.html),
[Azure Linux VM pricing](https://azure.microsoft.com/en-us/pricing/details/virtual-machines/linux/).

Record company payer/provider flows separately. Paying DreamBIG's own provider
from its own application account is an internal token flow, not independent
customer demand or new external cash revenue. A client payment in GWD is not cash
available for electricity unless it can actually be converted. Neither GRU resource
valuation nor a fixed token supply establishes market liquidity.

## 5. Governance approvals and custody

Retain the implemented five distinct governance signing keys:

| Action | Protocol requirement | Target-time interpretation |
| --- | --- | --- |
| Ordinary reserve payment | 3 of 5; 4,320 finalized blocks after qualifying approval | About 72 hours |
| Related-beneficiary aggregate at least 100,000 GWD | 4 of 5; 10,080 blocks | About seven days |
| Policy, unpause and governed program/network changes | 4 of 5; 20,160 blocks | About 14 days |

The 100,000-GWD large-payment threshold is fixed in current code; it is not a
recommended single payment or available allowance. The proposed reserve ceilings
are much lower. Extra review of smaller sensitive/related payments is procedural:
the protocol does not automatically elevate them to the large-payment tier.
All nominal days assume one finalized block per minute. Halted finality stops these
clocks. Maintain the stated calendar notice periods operationally as well; current
consensus does not enforce an independent UTC waiting period.

Because the founder is presently the sole custodian, these are **key quorums, not
person quorums**. They may limit compromise of isolated credentials, but cannot
prevent deliberate action by the sole controlling person. Do not publish a claim
of independent multisignature governance. The code verifies distinct public keys,
not independence of their human custodians.

Generate each governance key in a separate operator-controlled vault with separate
credentials. Never put quorum material in the shared infrastructure vault or on
nodes/NFS. As a practical minimum, distribute five keys across three separated
custody locations/contexts in a 2/2/1 arrangement so one location holds no ordinary
quorum. Separate encrypted USB media on one laptop are not independent signing
hardware: a compromised laptop can collect keys during sequential signing. Prefer
isolated offline signing environments; do not assume unverified hardware-wallet
support for Greenwood's custom signing format.

Maintain an offline public-key/custody inventory, test encrypted-backup restoration
quarterly, and review compromise risk after any device/account incident. Backups of
any one key must remain separated from material that would create an unintended
quorum. No AI account, cluster administrator credential, CI job or online automation
may access governance or recovery vaults. Additional non-operator custodians may
be appointed later through the governed signer-rotation process.

## 6. Guardian pause and lost-quorum recovery

Recommend one separately held guardian key and a **20,160-block bounded pause**
(about 14 days) for discretionary reserves during a credible incident. It cannot
spend reserves or mint funds. Current code limits repeated pauses per policy version;
this is not an indefinitely renewable emergency power or a universal network kill
switch. Confirm funded-contract and settlement behavior in physical acceptance.

Recommend explicit genesis-enabled recovery using **three of five separate cold
recovery keys**, with **43,200 blocks** of notice after the recovery approval quorum,
**86,400 blocks** proposal lifetime from proposal creation, and a **three-of-five
ordinary-governance-key veto**. Apply a minimum 30-calendar-day notice operationally
as well. A quorum obtained too late to fit the timelock requires a fresh proposal.
A three-key veto allows a functioning ordinary quorum to stop recovery; fewer
surviving ordinary keys cannot veto alone. Record that tradeoff explicitly.

Triggers: demonstrated loss/compromise of enough ordinary signing material to
prevent safe governance, supported by an incident record whose hash is in the
proposal. Do not use recovery to bypass a rejected payment, expedite spending or
shorten normal timelocks. Publish the incident, replacement public keys, approvals,
notice deadline and final outcome through the official public channel.

Recovery may replace governance signers/guardians only. Current execution preserves
financial policy and invalidates pending/queued treasury proposals. However, anyone
who can replace governance can subsequently control ordinary treasury authority:
recovery is an ultimate control path, not a harmless backup mechanism. New payments
must go through the normal limits and timelocks; recovery itself cannot transfer funds.

Recovery keys must be disjoint from governance/guardian keys and held separately,
with off-site backup arrangements whose failure risks differ from ordinary custody.
With the same person holding all keys, recovery does not provide independent control
or solve that person's incapacity/death. A future lawful successor/custodian appointment
requires a separate formal decision; possession of backup media does not itself confer
organizational authority. Do not invent a seven-person recovery council.

Current recovery authority is genesis-bound, including its keys and parameters.
It cannot be enabled, disabled or rotated by an ordinary policy update. Approve this
long-lived trust explicitly, or require a separately reviewed protocol change before
relying on future rotation. On-chain recovery also needs functioning consensus;
restore validator availability/backups separately if the chain cannot finalize blocks.
Disabling recovery is technically supported, but then loss of governance quorum has
no built-in replacement route and later enablement requires a protocol upgrade.

## 7. Epoch transitions and reporting

Publish monthly reserve balances, commitments, expenditures, bootstrap distributions,
related-party reimbursements, validator receipts, provider receipts and cash costs.
Separate grants, trial funding, customer revenue and emissions in all reports.
Reconcile the cash budget and GWD ledger separately. Publish quarterly custody and
recovery-drill results without secret material or sensitive physical locations.

No epoch transition occurs merely because a server or public key joins. Admit
providers using reviewed registration rules. Authorize independent verification only
after actual organizational independence and qualified evidence are established.
DreamBIG's customer-funded operator-attested services may run under their explicit
Epoch 0 profile with zero independent-attestation subsidy. Preserve existing funded
commitments' accepted verification requirements through every transition.

## 8. Adoption and implementation checklist

This is a prose draft, not a genesis JSON schema or transaction authorization.
Before adoption record: approving authority; founder's designated role; currency and
actual cost schedule; chosen caps and fees; public signer/guardian/recovery identities;
backup/incident procedures; conflict disclosure; signed adoption date and document hash.
No keys are generated or supplied by this draft.

Source inspected at `30b43586ad225db87efa4eebece5b6c370fccf70`:

- [Policy validation](../../../src/Economics/Policy.php): five distinct keys, operations
  ceilings, bootstrap/fee checks, and the currently positive-only reserve limits.
- [Ledger](../../../src/Economics/Ledger.php): threshold/delay rules, commitments,
  operations accounting, emissions, pauses, recovery and permitted policy replacements.
- [Recovery validation](../../../src/Economics/RecoveryPolicy.php): explicit enable/disable,
  disjoint keys, majority approval and bounded timelock/proposal/veto parameters.

Zero-limit source support is covered by a focused keyless regression suite; production
release qualification and policy ratification remain separate. Ordinary policy updates can change
signers, guardians, recipients and reserve limits, but not the fees, operations-account
caps, bootstrap parameters, clocks or recovery authority proposed here. Ratify those
as initial protocol commitments knowingly; do not promise monthly adjustability.
Invoices, USD caps, beneficial ownership, same-person control, calendar notice floors,
and the 90-day review are organizational controls, not automatically enforced code.
No production configuration, source behavior or release approval is changed by this draft.

External context: [NIST SP 800-57 Part 1 Rev. 5](https://csrc.nist.gov/pubs/sp/800/57/pt1/r5/final)
provides key-management, backup and compromise-planning guidance; [OpenZeppelin's
access-control guidance](https://docs.openzeppelin.com/contracts/5.x/access-control)
explains why privileged authority and delayed execution need explicit treatment.
Neither source prescribes these GWD limits, this custody allocation or this recovery
quorum. Those are Greenwood-specific recommendations requiring your review and formal
Institute authorization, not claims of third-party certification.
