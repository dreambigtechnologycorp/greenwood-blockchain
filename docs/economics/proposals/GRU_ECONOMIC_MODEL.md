# Greenwood Resource Unit (GRU) — How It Fits Into the Economic Model

**GRU is the missing link between Greenwood's blockchain economy and its physical infrastructure economy.**

We already established the distinction in our September 28 design discussions:

- **GWD (Greenwood):** The native blockchain token used for settlement, network compensation, and economic incentives.
- **GRU (Greenwood Resource Unit):** A nontransferable accounting unit used to price computing resources and services.
- **Greenwood Blockchain Institute:** Governs economic policy, subsidies, and infrastructure incentives.
- **Providers:** Supply computing resources and receive GWD for verified work.

GRU is not another cryptocurrency. It is not minted, traded, burned, or included in the one-billion-GWD supply.

It provides a consistent way to measure the economic value of infrastructure.

## 1. The original GRU design

We previously established this reference:

**1 GRU = $0.01 USD of infrastructure service value.**

This is an accounting reference, not a promise that any token can be redeemed for dollars.

We also discussed an initial reference conversion:

**At genesis: 1 GWD = 1 GRU.**

That corresponded to a $0.01 reference price per GWD, but it was not intended to permanently peg GWD to GRU.

GWD can fluctuate independently.

GRU remains a stable accounting denomination unless the Institute formally changes the service-pricing standard.

### Original resource rates

| Resource | GRU pricing | USD reference |
|---|---:|---:|
| CPU | 1.20 GRU/vCPU-hour | $0.012/hour |
| RAM | 0.15 GRU/GiB-hour | $0.0015/hour |
| Object storage | 1.00 GRU/GB-month | $0.01/month |
| Persistent storage | 1.50 GRU/GB-month | $0.015/month |
| Network egress | 2.00 GRU/GB | $0.02/GB |
| NVIDIA T4 GPU | 15.00 GRU/GPU-hour | $0.15/hour |

These were proposed reference prices, not verified sustainable provider costs.

They need validation against actual hardware, electricity, network, and operating expenses before production billing.

---

# 2. How GRU and GWD interact

The model should operate like this:

```text
                    GREENWOOD ECONOMY
                           |
              +------------+------------+
              |                         |
              v                         v
         GWD TOKEN                  GRU METER
       1 Billion Supply           Resource Pricing
              |                         |
              |                         |
       Settlement Asset            CPU / RAM / GPU
       Reserve Funding             Storage / Network
       Provider Rewards            Application Hosting
              |                         |
              +------------+------------+
                           |
                           v
                  RESOURCE QUOTATION
                           |
                           v
                 GRU → GWD CONVERSION
                           |
                           v
                      SETTLEMENT
                           |
                           v
                    GWD TO PROVIDER
```

GRU answers:

> How much infrastructure service was consumed, and what is its reference cost?

GWD answers:

> How is that consumption paid for and settled on Greenwood?

This separates resource pricing from the market price of the settlement token.

---

# 3. GRU conversion into GWD

The conversion model we previously discussed was:

\[
\text{GWD Required} =
\frac{\text{GRU Consumed}\times \$0.01}
{\text{GWD Reference Price in USD}}
\]

For example, suppose an application consumes 1,000 GRU.

That represents $10 of reference service value.

| GWD reference price | GWD required |
|---|---:|
| $0.005 | 2,000 GWD |
| $0.01 | 1,000 GWD |
| $0.05 | 200 GWD |
| $0.10 | 100 GWD |
| $1.00 | 10 GWD |

**The service remains priced at 1,000 GRU, while the required GWD changes.**

This is important for infrastructure economics.

Otherwise, a fluctuating token price could make Greenwood's computing services unpredictably expensive or unprofitable.

### Production implementation

The conversion requires a trustworthy price source.

I would not put an unrestricted administrator-controlled exchange rate directly into consensus.

Instead, the protocol needs a defined quote mechanism:

- Approved price source or reference methodology.
- Maximum quote age.
- Explicit quote expiration.
- Bounded price deviation.
- Signed quote identity.
- Deterministic integer conversion.
- Defined behavior when no trustworthy quote exists.

A quote must become fixed for the authorized settlement operation.

Validators should verify the same signed quote or finalized reference data instead of independently consulting external exchange APIs.

**Until GWD has a reliable market reference, the Institute must distinguish provisional internal pricing from externally established market value.**

---

# 4. Where GRU fits into the 1 billion GWD allocation

GRU does not require a genesis allocation.

It does, however, determine how several existing reserves are consumed.

Using our proposed distribution:

| Reserve | GWD allocation | Relationship to GRU |
|---|---:|---|
| Security and emissions | 400,000,000 | Rewards security and verified infrastructure participation |
| Grants | 200,000,000 | Can fund projects using GRU-denominated services |
| Subsidies | 150,000,000 | Pays for approved infrastructure consumption |
| Institute treasury | 240,000,000 | Can fund infrastructure obligations and operating costs |
| Working operations | 10,000,000 | Pays routine operational expenses |
| **Total** | **1,000,000,000** | |

The most direct integration is the **150-million-GWD subsidies reserve**.

### Example: subsidized application hosting

A developer deploys an application to Greenwood.

The application consumes 5,000 GRU during a billing period.

At the $0.01 reference:

**5,000 GRU = $50 of service value.**

Suppose the Institute has approved an 80% hosting subsidy.

| Participant | Resource value | At $0.01/GWD |
|---|---:|---:|
| Developer | 1,000 GRU | 1,000 GWD |
| Subsidy reserve | 4,000 GRU | 4,000 GWD |
| Provider receives | 5,000 GRU equivalent | 5,000 GWD |

The provider receives payment corresponding to the entire approved service charge.

The developer pays only the unsubsidized portion.

The Institute's subsidy reserve covers the difference.

This is the mechanism behind your earlier idea of granting developers access to infrastructure that would otherwise require capital.

**The Institute funds actual computing consumption rather than simply distributing unrestricted tokens.**

That is a much stronger use of the subsidy reserve.

---

# 5. GRU and protocol emissions are different

This distinction needs to be explicit.

The security reserve and the subsidies reserve serve different economic purposes.

### Security reserve

Compensates qualifying validators and infrastructure participants according to protocol rules.

For example:

- Validator participation.
- Consensus security.
- Verified infrastructure contributions.
- Eligible network services.

### Subsidies reserve

Pays for approved consumption of computing resources.

For example:

- Free application-hosting allowances.
- Developer infrastructure grants.
- Compute credits.
- Storage credits.
- Bandwidth credits.

A validator can earn protocol rewards without selling application hosting.

A compute provider can earn service revenue without receiving validator emissions.

Where both rewards apply, the protocol must prevent duplicate compensation for the same claimed work unless the two rewards deliberately compensate different obligations.

---

# 6. GRU should be Greenwood's infrastructure pricing layer

I would formally define three economic layers.

| Layer | Component | Responsibility |
|---|---|---|
| Layer 1 | GWD | Settlement, balances, reserves, economic security |
| Layer 2 | GRU | Resource metering, quotations, service valuation |
| Layer 3 | Provider marketplace | Capacity offers, resource delivery, verified receipts, compensation |

The workflow becomes:

**1. Provider registers capacity.**

A provider advertises CPU, memory, storage, networking, or GPU availability.

**2. Greenwood estimates usage.**

The application requests resources, and the system calculates an estimated GRU cost.

**3. Application accepts the quote.**

The quote specifies the resources, unit prices, maximum charges, and expiration.

**4. Payment authorization occurs.**

The application funds the service directly or uses an approved subsidy.

**5. Provider performs work.**

The infrastructure executes the workload.

**6. Greenwood verifies consumption.**

Receipts and permitted verification mechanisms establish the billable service.

**7. Settlement occurs in GWD.**

The provider receives the approved amount, with adjustments or refunds according to the service agreement.

The key principle is that **GRU measures resource value; GWD moves economic value**.

Neither the provider's self-reported consumption nor an unverified receipt should automatically authorize reserve spending.

---

# 7. How this changes Institute spending limits

Our previous proposed spending caps were denominated entirely in GWD.

That creates a weakness.

A subsidy budget of 750,000 GWD per month has different purchasing power depending on the token price.

For infrastructure programs, I recommend enforcing two limits simultaneously.

### Example: subsidies reserve

| Control | Proposed rule |
|---|---|
| Total allocation | 150 million GWD |
| Maximum monthly token release | 750,000 GWD |
| Service budget | Separately approved GRU ceiling |
| Per-developer allowance | Program-specific GRU limit |
| Per-service allowance | Resource-specific GRU limit |
| Conversion | Authorized, expiring settlement quote |
| Provider payment | Verified consumption |
| Unused allowance | Expires or follows approved rollover rules |

A subsidy must satisfy **both** the GRU consumption authorization and the GWD reserve release limit.

This prevents unexpected exchange-rate movements from draining the reserve.

It also prevents developers from consuming an unlimited quantity of infrastructure simply because they qualify for a subsidy.

The Institute can publish its actual commitments in resource units while keeping token spending bounded.

---

# 8. Should GRU be transferable?

**No.**

The original design treated GRU as a nontransferable accounting denomination.

I would preserve that decision.

The system may issue resource allowances expressed in GRU, but those allowances are not equivalent to issuing a new currency.

For example:

A developer receives an allocation of 10,000 GRU for application hosting.

That allowance:

- Is usable only for approved services.
- Has a defined expiration.
- Cannot be transferred or sold.
- Cannot be redeemed for unrestricted GWD.
- Is charged according to verified resource consumption.
- Remains subject to program and provider limits.

The allowance is an authorization to consume services, not an additional blockchain asset.

This keeps the original one-billion-GWD supply intact.

---

# 9. What needs to be implemented before production

The GRU layer should be built around Greenwood's existing Execution Manifest, Service Receipt, provider system, and resource estimator.

It does not need to be an independent blockchain token or an entirely separate economic subsystem.

| Component | Implementation requirement |
|---|---|
| Resource catalog | Define standard billable resources |
| GRU precision | Fixed-point integer accounting |
| Rate schedules | Versioned, approved resource prices |
| Resource estimator | Calculate expected GRU consumption |
| Quote service | Produce signed, expiring quotations |
| GWD conversion | Convert approved GRU value into settlement units |
| Subsidy authorization | Apply program allowances and caps |
| Consumption verification | Validate attributable service receipts |
| Settlement | Transfer existing GWD to providers |
| Reporting | Record GRU consumption, GWD payments, and reserve balances |

One additional rule matters: **the GRU accounting scale should be independent of GWD's six-decimal precision.**

For example, Greenwood can represent one GWD as one million base units while representing GRU consumption using a separately defined fixed-point scale.

This prevents fractional CPU time or storage consumption from being rounded incorrectly.

---

# 10. Revised Greenwood economic architecture

With GRU integrated, the model becomes:

```text
                 GREENWOOD BLOCKCHAIN INSTITUTE
                              |
                     Economic Governance
                              |
             +----------------+----------------+
             |                                 |
             v                                 v
       GWD RESERVES                       GRU POLICY
       1 Billion GWD                    Resource Pricing
             |                                 |
      +------+------+                   +------+------+
      |             |                   |             |
      v             v                   v             v
   Security      Subsidies           Metering       Estimates
   Rewards       Programs            & Rates        & Quotes
      |             |                   |             |
      |             +---------+---------+             |
      |                       |                       |
      v                       v                       v
   Validators           Verified Services        Applications
      |                       |                       |
      +-----------------------+-----------------------+
                              |
                              v
                     GWD SETTLEMENT
                              |
                              v
                    INFRASTRUCTURE PROVIDERS
```

## Final recommendation

Preserve GRU exactly as the resource-accounting layer we originally designed.

**GWD:** Fixed-supply settlement asset.

**GRU:** Stable infrastructure-pricing denomination with an initial reference of $0.01 per unit.

**Security reserve:** Compensates protocol security and eligible network participation.

**Subsidies reserve:** Covers approved GRU-denominated resource consumption.

**Grants reserve:** Funds approved ecosystem development, including infrastructure allowances.

**Institute:** Governs budgets, program eligibility, pricing standards, and spending authorization.

**Validators:** Enforce GWD reserve restrictions and any settlement rules implemented at consensus level.

**Providers:** Deliver and meter infrastructure services, with compensation based on authorized, verified work.

This creates the economic structure Greenwood was intended to support: **a fixed-supply blockchain that finances real, measurable computing infrastructure without issuing a second speculative token.**

One previous decision also remains relevant: on October 20, we discussed eliminating scheduled GWD burns entirely. That conflicts with the more recent candidate 20% burn policy. The final protocol specification must choose one. GRU itself requires neither burning nor inflation.

**The key remaining design decision is the GRU-to-GWD pricing and settlement mechanism.** It must preserve predictable infrastructure charges without introducing an untrusted price oracle or an unrestricted path to spending the Institute's protected reserves.