# Greenwood Blockchain Institute

> **Historical scope — reviewed November 24, 2026.** This archived proposal/review preserves its original assumptions, recommendations and test reports. It does not independently adopt policy or describe the latest implementation.
> Read the [economics index](../README.md) for current source and policy precedence,
> and [project status](../../PROJECT_STATUS.md) for release/installation and qualification.

## Proposed Mainnet Tokenomics — Economic Design v1.0
**November 14, 2026**

### Executive recommendation

Greenwood should use a **fixed-supply, infrastructure-backed utility economy** in which GWD finances network participation and settles payments, while GRU measures actual infrastructure consumption.

The economic objective should be straightforward:

**Use Greenwood's native token to finance useful infrastructure, make that infrastructure available to developers and businesses, and progressively reduce dependence on Institute-funded incentives as commercial usage increases.**

I recommend five principles:

1. **Fixed supply:** Issue 1 billion GWD at genesis. No additional minting or fee burning.
2. **Protected reserves:** Allocate the entire supply across five protocol-enforced reserves, with no unrestricted master treasury.
3. **Funded security:** Pay validators from a finite emissions reserve, supplemented by transaction fees.
4. **Resource-based demand:** Use GRU to meter CPU, memory, storage, bandwidth, and other verified services, with GWD as a settlement asset.
5. **Controlled distribution:** Release tokens against actual infrastructure participation, approved grants, verified services, and operating obligations—not arbitrary token-unlock schedules.

The design should prioritize sustainable infrastructure revenue over speculative demand or artificial token scarcity.

There is an important distinction: Greenwood can connect token expenditure to real resource consumption, but that does not give GWD an intrinsic dollar value, guaranteed demand, or a redemption right.

---

# 1. Confirmed monetary foundation

The latest Codex report establishes the following implementation decisions.

| Parameter | Configuration |
|---|---|
| Native token | GWD |
| Maximum supply | 1,000,000,000 GWD |
| Decimal precision | 6 |
| Total base units | 1,000,000,000,000,000 |
| Genesis issuance | 100% |
| Additional minting | None |
| Transaction fee burning | None |
| Transaction fee recipients | Eligible validator operators |
| Service settlement | Separate from network transaction fees |
| Resource accounting | GRU |
| Consensus target | 60 seconds per block |
| Treasury governance | 3-of-5 / 4-of-5 approvals |
| Treasury enforcement | Native consensus state transitions |

These are reported as implemented and locally tested, not independently accepted for mainnet.

### Why fixed supply makes sense here

Greenwood does not need continuous token issuance because the genesis reserves already fund scheduled emissions.

Under this model:

\[
\text{GWD Outstanding Supply}=1,000,000,000
\]

Transfers, treasury distributions, and protocol rewards redistribute existing supply.

They do not increase outstanding supply.

This simplifies monetary accounting and makes future token distributions auditable.

However, **fixed supply does not automatically create a sustainable token economy**. It only constrains issuance. Sustainable demand depends on whether people actually use Greenwood's services.

---

# 2. First major finding: the current security allocation is excessive for the implemented schedule

The current candidate allocation reserves 400 million GWD for security and emissions.

Let's compare that to the actual rewards.

At Greenwood's 60-second block target, the economic model uses 525,960 blocks per nominal year.

### Existing emissions schedule

| Period | GWD/block | Four-year emission |
|---|---:|---:|
| Years 1–4 | 10 | 21,038,400 |
| Years 5–8 | 7.5 | 15,778,800 |
| Years 9–12 | 5 | 10,519,200 |
| Years 13–16 | 2.5 | 5,259,600 |
| **Total** | | **52,596,000 GWD** |

After year 16, emissions fall to 1 GWD per finalized block.

### How long different security reserves last

| Initial reserve | Remaining after year 16 | Total nominal reward runway |
|---|---:|---:|
| 80 million | 27.404 million | ~68 years |
| 100 million | 47.404 million | ~106 years |
| 120 million | 67.404 million | ~144 years |
| 150 million | 97.404 million | ~201 years |
| 400 million | 347.404 million | ~677 years |

These are mathematical estimates using continuous production at the target cadence and the exact reported schedule.

Actual elapsed time changes when blocks are delayed.

**Conclusion:** The proposed 40% security allocation is difficult to justify solely from the current emissions schedule.

It would keep 347.4 million GWD restricted after the first 16 nominal years.

That is 34.74% of the entire token supply.

A long-lived reserve can be desirable, but hundreds of years of precommitted rewards are not automatically an efficient use of genesis allocations.

### Recommendation

Reduce the emissions allocation and explicitly separate funding for protocol security from funding for actual compute-service consumption.

The two activities have different economic requirements.

Consensus security pays validators for maintaining the ledger.

Infrastructure-service payments compensate providers for delivering measurable resources.

Greenwood should not treat those as interchangeable.

Research from the Cambridge Centre for Alternative Finance highlights a related long-term risk: assuming transaction fees will eventually replace block subsidies can be economically unsound when fee revenue remains insufficient. That research concerns Bitcoin's proof-of-work security budget, not Greenwood's BFT network, but the revenue-dependence problem is relevant. [Cambridge Judge Business School](https://www.jbs.cam.ac.uk/faculty-research/centres/alternative-finance/publications/funding-bitcoins-future-paying-for-security-as-the-block-subsidy-fades/?utm_source=chatgpt.com)

---

# 3. Recommended Genesis Allocation

I would replace the current 40/20/15/24/1 candidate with a more infrastructure-focused distribution.

## Proposed allocation: 10/25/30/34/1

| Reserve | Allocation | GWD | Primary purpose |
|---|---:|---:|---|
| Security and emissions | **10%** | 100,000,000 | Validator incentives |
| Ecosystem grants | **25%** | 250,000,000 | Developers, applications, research |
| Infrastructure subsidies | **30%** | 300,000,000 | Subsidized resource consumption |
| Institute treasury | **34%** | 340,000,000 | Long-term funding and contingency |
| Working operations | **1%** | 10,000,000 | Routine approved expenses |
| **Total** | **100%** | **1,000,000,000** | |

These percentages are a recommended policy choice, not empirical estimates of Greenwood's future expenses.

### Why this allocation?

**Security — 10%**

One hundred million GWD covers approximately 106 nominal years of the currently implemented reward schedule, assuming uninterrupted target-cadence production and no changes.

It funds the complete initial 16-year decline and retains substantial tail-emission capacity.

This is sufficient under the *current schedule*. It is not proof that the payments will economically support future validators.

**Grants — 25%**

The Institute should be capable of funding applications and infrastructure development.

Eligible expenditures could include software development, new protocol integrations, security audits, provider integrations, and approved ecosystem projects.

Grants should use milestones rather than unrestricted distributions.

**Infrastructure subsidies — 30%**

This is where I would place the greatest emphasis.

Greenwood's differentiator is not simply running another Layer 1. It is combining a finalized ledger with access to usable infrastructure.

The subsidies reserve should finance verified resource consumption, including approved hosting and compute services.

A large allocation does not mean immediate spending. These tokens remain protected until specific subsidy programs meet their authorization and verification requirements.

**Institute treasury — 34%**

The Institute needs flexibility for expenses that are not yet predictable.

That includes security, legal and administrative costs, infrastructure expansion, and future approved strategic programs.

These funds should be subject to the strongest discretionary controls.

**Working operations — 1%**

Ten million GWD remains in a protected working reserve.

Only a small approved operating float becomes available to routine payment accounts.

### Why I prefer this to 40/20/15/24/1

The original allocation heavily favors security emissions even though the implemented reward schedule consumes relatively little of that reserve.

The proposed distribution assigns:

- 10% to protocol security.
- 55% to grants and infrastructure subsidies.
- 34% to the long-term Institute treasury.
- 1% to restricted working operations.

This more closely reflects Greenwood's stated infrastructure mission.

**Important tradeoff:** The grants and subsidies allocations would become difficult to repurpose if future demand is lower than expected. The 34% treasury therefore serves as the flexibility reserve, and every major discretionary class remains subject to governance controls.

The 10/25/30/34/1 allocation should be stress-tested against the original 40/20/15/24/1 configuration before becoming permanent.

---

# 4. Validator Economics

The proposed native reward schedule can remain unchanged.

## First four years

At 60-second blocks:

| Metric | Value |
|---|---:|
| Blocks per day | 1,440 |
| GWD per block | 10 |
| Network rewards per day | 14,400 GWD |
| Annual network rewards | 5,259,600 GWD |
| Genesis validators | 3 |
| Annual GWD per validator | 1,753,200 GWD |

The existing implementation distributes rewards equally among the genesis validators.

At three validators, each receives approximately 1.75 million GWD annually, assuming continuous operation at the target cadence.

## What those rewards are worth

There is no independently established GWD market price in the information available.

The following is a sensitivity analysis, **not a price forecast**.

| Hypothetical GWD price | Monthly reward per validator |
|---|---:|
| $0.001 | $146.10 |
| $0.005 | $730.50 |
| $0.01 | $1,461.00 |
| $0.05 | $7,305.00 |

This shows why token quantities alone cannot establish infrastructure affordability.

At $0.001, a validator receives approximately $146 per month in theoretical market value.

At $0.01, that increases to approximately $1,461.

But those values are only economically meaningful if there is sufficient actual demand and liquidity to realize them.

### My recommendation

Keep the 10 GWD initial block reward.

Do not increase emissions simply to make operator compensation appear larger.

Measure the actual costs of running independent validators and compare those costs against realizable compensation.

There is also a governance concern: if DreamBIG operates all three genesis validators, all initial block rewards go to the same infrastructure organization, despite being distributed among three validator identities.

**Three physical nodes under one operator do not constitute three economically independent validators.**

Greenwood should establish independent validator operation as a future network objective, but the existing genesis-fixed validator implementation requires additional supported membership rules before that can occur.

---

# 5. Transaction Fees: 100% Operator Compensation

Keep the agreed no-burn policy.

```text
Transaction Fee
      |
      v
Protocol Collection
      |
      v
100% Operator Distribution
      |
      v
Eligible Validators
```

Transaction fees should compensate operators for maintaining the ledger.

Compute-service payments are separate.

A provider receiving payment for application hosting should not automatically receive a share of network consensus fees unless it also qualifies under the validator policy.

## The important economic question

Can transaction fees eventually replace scheduled emissions?

Suppose the network produces 5,259,600 GWD in annual security rewards during its first four years.

| Average fee per transaction | Transactions/day needed to match annual emissions |
|---|---:|
| 0.01 GWD | 1,440,000 |
| 0.05 GWD | 288,000 |
| 0.10 GWD | 144,000 |
| 1.00 GWD | 14,400 |

These figures assume every transaction pays the stated average fee and all collected fees reach the eligible validator pool.

They are not transaction-throughput projections.

For example, at 0.10 GWD per transaction, Greenwood would need approximately 144,000 fee-paying transactions per day merely to match its initial annual emission quantity.

That is about 1.67 transactions per second.

Greenwood's demonstrated block count does not establish that transaction capacity.

### Recommended fee strategy

Maintain the implemented genesis-configured fee categories, but set the final amounts using measured validation costs, transaction sizes, spam resistance, and representative workloads.

The fee design should:

- Charge more for resource-intensive transaction categories.
- Keep ordinary transfers accessible.
- Compensate operators for finalized valid transactions.
- Preserve independent provider-service billing.
- Prevent abuse through admission limits and resource budgets.

Because the current implementation uses flat category fees, it also needs separate protection against excessive admission traffic and expensive rejected transactions.

No final fee amounts should be embedded in permanent genesis until those performance tests are complete.

### Why fee revenue cannot be assumed

Cambridge's October 2026 research on Bitcoin illustrates how difficult it can be for transaction fees to replace security subsidies. Its findings should not be transferred numerically to Greenwood, but they support treating fee sustainability as something to demonstrate rather than assume. [Cambridge Judge Business School](https://www.jbs.cam.ac.uk/faculty-research/centres/alternative-finance/publications/funding-bitcoins-future-paying-for-security-as-the-block-subsidy-fades/?utm_source=chatgpt.com)

**For Greenwood, actual commercial infrastructure usage should be an additional source of operator income, not merely a means of generating more blockchain transactions.**

---

# 6. GRU: Greenwood's Commercial Infrastructure Economy

This is the most important component for long-term commercial adoption.

I would retain the proposed GRU framework:

| Property | Policy |
|---|---|
| Name | Greenwood Resource Unit |
| Symbol | GRU |
| Transferable | No |
| Blockchain token | No |
| Separate circulating supply | No |
| Redeemable | No |
| Purpose | Pricing and accounting for services |
| Settlement | GWD under an authorized quote |
| Resource prices | Versioned service catalog |

GRU should describe service consumption.

GWD should handle monetary settlement.

## How other networks approach compute pricing

Akash is a useful comparison.

Its infrastructure marketplace allows providers to advertise capabilities and compete on pricing, with deployments represented by resource requirements and leases.

Its documentation also describes escrow-funded compute credits and automatic provider payments during a lease. [Akash Network](https://akash.network/docs/learn/core-concepts/providers-leases/?utm_source=chatgpt.com)

Greenwood does not need to copy Akash's token model.

But the underlying commercial structure is relevant:

**Infrastructure providers must know what resources they are committing, what compensation they will receive, and how payment is secured.**

## GRU pricing should be cost-informed

Earlier Greenwood proposals used the following illustrative reference prices:

| Resource | Proposed GRU rate | Reference USD equivalent |
|---|---:|---:|
| CPU | 1.20 GRU/vCPU-hour | $0.012 |
| RAM | 0.15 GRU/GiB-hour | $0.0015 |
| Object storage | 1.00 GRU/GB-month | $0.01 |
| Persistent storage | 1.50 GRU/GB-month | $0.015 |
| Egress | 2.00 GRU/GB | $0.02 |

These prices should not be adopted without comparison against actual infrastructure costs.

### Comparison with existing providers

For example, AWS t4g.small provides two burstable vCPUs and 2 GiB of memory at a reported US East on-demand rate of $0.0168/hour.

Under Greenwood's earlier reference rates, charging two vCPU-hours and two GiB-hours would total $0.027/hour.

That is approximately 61% higher than the AWS instance price. The CPU performance and included services are not directly equivalent, but the comparison exposes a pricing issue. [Usage AI](https://instances.usage.ai/aws/ec2/family/t4g?utm_source=chatgpt.com)

For storage, Cloudflare R2 Standard publishes a storage rate of $0.015/GB-month, excluding its separate operation charges.

Greenwood's proposed $0.01 object-storage reference would be below that cost if the service were directly backed by paid R2 capacity. [Cloudflare Docs](https://developers.cloudflare.com/r2/pricing/?utm_source=chatgpt.com)

### Recommendation

Do not impose one universal price on every provider.

Use a versioned GRU catalog with:

- Standard resource definitions.
- Provider-specific quoted rates.
- Minimum service commitments.
- Supported hardware and capacity classes.
- Explicit performance and availability requirements.
- Metering and verification methods.

A provider with surplus hardware, cheap electricity, and low network costs may offer lower prices.

A provider offering dedicated capacity, stronger availability, and geographic redundancy may charge more.

The catalog should make those differences visible.

**GRU should standardize measurement—not eliminate competitive pricing.**

---

# 7. The GRU-to-GWD Exchange Mechanism

I recommend removing any permanent assumption that:

**1 GRU = 1 GWD.**

That can be an illustrative launch quotation, but not a protocol peg.

Instead:

\[
\text{GWD Payment}=
\text{Verified GRU Consumption}
\times
\text{Accepted GWD/GRU Rate}
\]

The actual implementation must perform this calculation using checked integers, deterministic rounding, and approved rate precision.

A quote must identify the provider, payer, service, resource catalog, authorized maximum, expiry, and the applicable chain and genesis.

## Two settlement modes

### Mode A — Provider-priced GWD

A provider offers a service at a stated GWD-per-GRU rate.

The customer accepts that rate.

Escrow secures the corresponding GWD.

Verified consumption determines the payment.

This does not require Greenwood to establish an external GWD/USD price.

The provider accepts the market risk of receiving GWD.

### Mode B — Reference-indexed pricing

The service is quoted against an externally referenced price, with GWD calculated from an approved market reference.

This requires:

- Reliable observable price data.
- Independent quote authorities.
- Maximum quote age.
- Explicit deviation limits.
- Deterministic settlement.
- Protection against manipulated or illiquid markets.

The implemented GRU quote-authority mechanism provides part of this foundation.

However, quote signatures alone do not prove that the quoted exchange rate represents a liquid market.

**My recommendation is to begin with provider-accepted GWD quotations and introduce reference-indexed pricing only when reliable market data exists.**

For providers requiring dollar-denominated compensation, external invoicing or separately supported settlement assets can be used until the corresponding on-chain mechanisms are verified. Such arrangements must not be represented as existing Greenwood protocol functionality.

---

# 8. Infrastructure Subsidies: The Main Adoption Mechanism

Under the recommended allocation:

**300 million GWD is reserved for infrastructure subsidies.**

These tokens should not be given to users as unrestricted, transferable GWD.

Instead, users receive authorized allowances measured in GRU.

The reserve pays approved providers when verified services are delivered.

## Proposed introductory program

| Parameter | Initial pilot |
|---|---:|
| Eligible developers | 100 |
| Monthly allowance per developer | 1,000 GRU |
| Total monthly consumption allowance | 100,000 GRU |
| Subsidy percentage | 80% |
| User responsibility | 20% |
| Reference service value | $1,000/month |
| Reference subsidy value | $800/month |

The dollar figures are based solely on the illustrative $0.01-per-GRU accounting reference, not an assurance of market value.

### Example settlement

Assume a provider accepts a quotation of one GWD per GRU.

The program consumes 100,000 GRU.

The authorized settlement would be:

| Source | GWD |
|---|---:|
| Developers | 20,000 |
| Subsidy reserve | 80,000 |
| Provider compensation | 100,000 |

If the accepted quotation instead required five GWD per GRU, the subsidy requirement would rise to 400,000 GWD.

The spending policy must reject the payment if that exceeds the program's token budget.

This demonstrates why subsidy programs need two ceilings:

**GRU consumption limit + GWD expenditure limit.**

### Recommended anti-abuse controls

Credits should be nontransferable and bound to an approved beneficiary.

Charges require verified resource delivery.

A developer cannot cash out unused GRU allowances.

A provider cannot submit the same usage receipt twice.

Unused escrow returns to its original funding source.

Beneficiary and provider identities must be checked against the approved program policy.

An additional engineering distinction matters: reserving a vCPU for one hour is easier to verify contractually than proving exactly how many CPU cycles an untrusted provider executed. Initial billing should favor verifiable reserved-capacity intervals and externally observable availability. More difficult metrics, such as GPU execution and network egress, require stronger attestation designs.

**The subsidy program must remain inactive until Greenwood's GRU metering, escrow, receipt verification, and settlement implementation is complete.**

---

# 9. Institute Treasury and Spending Controls

I would retain the tested approval structure rather than redesigning treasury governance again.

## Authorization thresholds

| Action | Approval | Nominal delay |
|---|---|---|
| Ordinary withdrawal | 3-of-5 | 72 hours |
| Large withdrawal | 4-of-5 | 7 days |
| Security-policy change | 4-of-5 | 14 days |
| Emergency discretionary pause | Authorized guardians | On finalization |
| Direct withdrawal from emissions | Prohibited | — |

One clarification is necessary.

The implemented delays are measured in finalized block heights:

- 4,320 blocks.
- 10,080 blocks.
- 20,160 blocks.

These correspond to the stated elapsed periods only at the target 60-second cadence.

If blocks stop, withdrawal eligibility stops advancing. Faster-than-target finalization could shorten elapsed time. They should therefore be described publicly as **height-based timelocks with nominal durations**, not guaranteed wall-clock delays.

## Recommended launch spending caps

Rather than immediately allowing the earlier 0.5% monthly release ceiling across major reserves, I would begin with tighter limits.

| Reserve | Allocation | Initial rolling 30-day ceiling |
|---|---:|---:|
| Grants | 250,000,000 | 250,000 GWD |
| Subsidies | 300,000,000 | 300,000 GWD |
| Institute treasury | 340,000,000 | 340,000 GWD |
| Working operations | 10,000,000 | 100,000 GWD |
| **Total discretionary ceiling** | | **990,000 GWD** |

These are maximum permitted releases, not expenditures that must occur.

The subsidy ceiling remains unusable until verified settlement exists.

The grant and treasury ceilings require separately approved budgets and payment purposes.

### Why use conservative initial ceilings?

There is no established commercial GWD price, no demonstrated steady-state fee revenue, and no validated infrastructure-service demand forecast.

It would be premature to authorize large token releases based solely on percentage allocations.

The Institute can approve larger budgets later through the implemented delayed policy-change mechanism.

The original proposed 100,000-GWD large-payment threshold can remain the starting point, with the existing related-beneficiary aggregation preventing obvious payment splitting.

### Working wallet

The ordinary operational wallet should not contain the entire working reserve.

I would use:

- Maximum liquid working balance: 100,000 GWD.
- Maximum rolling 30-day replenishment: 100,000 GWD.
- Routine payment ceiling: 5,000 GWD.
- Larger expenses: Institute approval.

These GWD figures are provisional authorization limits, not dollar operating budgets.

### Signer independence

Five public keys are not enough by themselves.

The Institute should verify that signers are independently controlled, avoid common recovery dependencies, and cannot all be compromised through one organization or administrator.

The missing lost-quorum recovery mechanism must also be addressed before permanently locking substantial value.

---

# 10. Circulating Supply

This is one of the most important accounting distinctions.

**Genesis issuance is not circulating supply.**

Under the proposed model, the full billion GWD exists immediately, but nearly all of it begins under spending restrictions.

Circulation increases through:

1. Validator rewards.
2. Approved grants.
3. Verified subsidy payments.
4. Authorized treasury expenditures.
5. Working-wallet expenditures.

Transfers between ordinary wallets redistribute circulating tokens.

They do not necessarily increase circulating supply.

### First-year circulation illustration

If Greenwood produces blocks continuously at the target rate:

**5,259,600 GWD enters validator-controlled balances during year one.**

That represents approximately 0.526% of total issuance.

Additional tokens may enter circulation through approved reserve releases.

No grant or subsidy tokens need to become liquid simply because a calendar date arrives.

### Recommended circulation reporting

The network should distinguish:

| Supply category | Meaning |
|---|---|
| Total issuance | Fixed 1 billion GWD |
| Protected reserves | GWD still subject to reserve restrictions |
| Pending withdrawals | Approved but not yet executable |
| Escrowed balances | GWD committed to service settlement |
| Restricted or vested balances | Not freely transferable |
| Freely circulating GWD | Unrestricted, transferable balances |

These categories must be mutually exclusive in accounting reports to avoid double counting.

**Do not describe all one billion genesis-issued GWD as circulating.**

Also, do not assume validator rewards are automatically sold or spent. Becoming transferable does not guarantee entry into an exchange market.

---

# 11. Financial Sustainability Model

The Institute needs to distinguish token-denominated activity from actual revenue.

I would report four economic metrics.

| Metric | Purpose |
|---|---|
| Actual provider operating cost | Measures resources required to deliver services |
| Paid GRU consumption | Measures demand funded by customers |
| Subsidized GRU consumption | Measures adoption financed by reserves |
| Realized provider revenue | Measures actual compensation converted to usable economic value |

A network can distribute millions of GWD while generating almost no external revenue.

Likewise, a high volume of subsidized GRU activity does not necessarily demonstrate a sustainable business.

## The critical ratio

\[
\text{Customer Funding Share}=
\frac{\text{Customer-Funded Service Value}}
{\text{Total Verified Service Value}}
\]

For the proposed initial pilot, customers fund 20% of the service value.

The Institute subsidizes 80%.

That is acceptable as an acquisition strategy, but not a permanent assumption.

A practical graduation policy could be:

| Program stage | Institute subsidy | Customer share |
|---|---:|---:|
| Initial adoption | 80% | 20% |
| Established developer | 50% | 50% |
| Commercial application | 20% | 80% |
| Fully commercial | 0% | 100% |

These are proposed program tiers, not evidence-based retention or conversion rates.

Graduation should be driven by verified utilization, customer retention, and actual payment behavior rather than an automatic universal timetable.

The Institute should also maintain a separate financial budget in USD or another conventional accounting currency for obligations such as electricity, equipment, connectivity, and legal services.

GWD treasury balances should not be treated as equivalent to cash reserves.

---

# 12. Mainnet Economic Acceptance Criteria

Before adopting the new allocation, I would require Codex to run five scenario models.

| Scenario | What must be established |
|---|---|
| Zero commercial adoption | Institute can sustain its approved obligations without assumed fee revenue |
| Low GWD purchasing power | Rewards and subsidies do not create unsupported affordability claims |
| Rapid application growth | GRU budgets, escrow, and provider capacity prevent uncontrolled spending |
| Validator expansion | Reward economics remain viable when operators become independent |
| Treasury stress | Signer loss, malicious approvals, pauses, and policy changes cannot bypass protections |

The existing 94 economic tests provide important protocol evidence.

They do not establish business affordability.

A simulation should also compare the original 40/20/15/24/1 allocation against the proposed 10/25/30/34/1 distribution using identical service-demand, revenue, and operating-cost assumptions.

Changing the allocation template requires rerunning the economic tests and reconciling all genesis hashes, policy commitments, and dependent client configurations.

---

# 13. Recommended Final Architecture

```text
                   GREENWOOD BLOCKCHAIN
                      1 BILLION GWD
                            |
              GENESIS PROTECTED ALLOCATION
                            |
         +------------------+------------------+
         |                  |                  |
         v                  v                  v
   SECURITY RESERVE    INSTITUTE RESERVES   WORKING RESERVE
      100M GWD            890M GWD            10M GWD
         |                  |
         |             +----+------------------+
         |             |           |          |
         |             v           v          v
         |           GRANTS     SUBSIDIES   TREASURY
         |            250M        300M       340M
         |             |           |          |
         v             v           v          v
    VALIDATORS     DEVELOPERS     VERIFIED   APPROVED
                                RESOURCES   EXPENSES
         |             |           |
         |             +-----+-----+
         |                   |
         |                   v
         |              GRU METERING
         |                   |
         |             VERIFIED USAGE
         |                   |
         |              GWD ESCROW
         |                   |
         +-------------------+
                             |
                             v
                  INFRASTRUCTURE OPERATORS
                             |
                    COMMERCIAL SERVICES
                             |
                  CUSTOMER-FUNDED REVENUE
```

There are three distinct flows in this design.

**Security compensation:** Existing GWD moves from the emissions reserve to eligible validators according to the protocol schedule.

**Ecosystem funding:** Institute governance approves and releases funds for legitimate development and operating purposes.

**Infrastructure commerce:** Customers and approved subsidy programs finance verified resource consumption, with payment going to providers under accepted settlement terms.

The third flow is what can eventually create a commercially sustainable infrastructure marketplace.

It is also the main piece that remains incomplete in the reported implementation.

---

# Final Tokenomics Recommendation

| Component | Recommended policy |
|---|---|
| Supply | 1 billion GWD |
| Precision | 6 decimals |
| Inflation | None |
| Burning | None |
| Block cadence | 60 seconds |
| Initial validator reward | 10 GWD/block |
| Security allocation | 10% |
| Grants allocation | 25% |
| Subsidies allocation | 30% |
| Institute treasury | 34% |
| Working operations | 1% |
| Normal treasury approval | 3-of-5 |
| Large treasury approval | 4-of-5 |
| Treasury timelocks | Height-based |
| Network transaction fees | 100% to eligible validator operators |
| Infrastructure prices | GRU-denominated |
| Commercial settlement | Provider-accepted GWD quotations |
| Subsidy settlement | Verified consumption, budget limits, escrow |
| Initial external liquidity allocation | None |
| Automatic contributor allocation | None |
| Production activation | Blocked pending acceptance |

### What I would lock now

The one-billion-GWD issuance, six-decimal precision, no-burn policy, reserve-enforced custody, and separation between GRU accounting and GWD settlement are coherent design commitments.

The current emissions schedule also has a clear, testable mathematical profile.

### What I would change

**Reduce the proposed security allocation from 40% to 10% and reassign the difference toward infrastructure adoption, grants, and Institute flexibility.**

That recommendation follows directly from the emissions arithmetic, although the final percentages still require demand and operating-budget evidence.

### What remains financially unproven

The token's purchasing power, commercial infrastructure demand, provider profitability, required liquidity, and eventual fee-supported security budget.

Those cannot be established through protocol tests.

**My conclusion:** Greenwood should be designed as a fixed-supply settlement network with a governed infrastructure endowment—not as a token whose economic thesis depends on appreciation.

The strongest long-term economic outcome is one in which developers pay for useful services, providers earn sustainable revenue, the Institute funds measurable adoption, and GWD facilitates those exchanges.

The proposed **10/25/30/34/1** distribution is the candidate I would take into the next budget simulation and governance review. It is not yet a justified permanent genesis allocation.