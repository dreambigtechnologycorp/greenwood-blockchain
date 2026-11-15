# Greenwood GRU Settlement — Implementation Review
**November 14, 2026**

**Verdict: Finite compute settlement is implemented locally. Full infrastructure settlement is incomplete. Production HOLD remains appropriate.**

The new implementation addresses the most important architectural requirement: **GRU settlement now integrates directly with Greenwood's existing compute requests, assignments, receipts, and native GWD ledger.**

It does not introduce a second provider system or a separate settlement currency.

## 1. What is now implemented

The completed financial path is:

```text
ComputeRequest
      |
      v
Approved Service Program
      |
      v
Provider-Signed GRU Offer
      |
      v
Customer Acceptance
      |
      v
Native GWD Escrow
      |
      v
Funded ComputeAssign
      |
      v
Existing Provider Execution
      |
      v
ComputeReceiptV2
      |
      v
Independent Usage Attestations
      |
      v
Challenge Period
      |
      v
Provider Payment + Refunds
```

The implementation adds native transaction type 13 while preserving the frozen compute-request, assignment, and receipt formats.

Financial state is committed to consensus, included in state roots, persisted through SQLite, and processed atomically with relevant GWD balances and reserve accounting. Pasted text(20261114-082746)

### Important technical improvements

| Component | Assessment |
|---|---|
| Existing compute integration | Implemented locally |
| Native financial escrow | Implemented locally |
| Provider-signed quotations | Implemented locally |
| Prior-block funding requirement | Implemented locally |
| Independent attestation verification | Implemented locally |
| Automatic provider payment | Implemented locally |
| Customer refunds | Implemented locally |
| Subsidy reserve accounting | Implemented locally |
| Duplicate-settlement prevention | Reported tested |
| Financial state persistence | Reported tested |
| Hosted application billing | Not implemented |
| Operational measurement independence | Not established |

**This is the right foundation.**

The existing provider system remains responsible for execution. The economic layer is responsible for determining what can be paid.

---

## 2. The strongest decision: funding before assignment

The new implementation rejects compute assignments unless matching funding was finalized in an earlier block.

That matters because the existing provider supervisor executes finalized assignments.

Without this restriction, a provider could perform work before the customer or subsidy reserve had committed the funds.

The new sequence addresses that exposure:

**Funding finality → Assignment finality → Execution.**

It also prevents a customer from opening parallel financial commitments against the same request.

This should remain a protocol invariant.

---

## 3. The largest remaining security issue: independent metering

The system now requires at least two distinct usage attestors, excluding the provider and customers from attesting their own usage.

That is useful authorization separation.

However, **two signatures do not necessarily mean two independent measurements.**

If both attestors simply sign the provider's reported cgroup statistics, they do not independently establish the underlying consumption.

The next implementation must define:

- How attestors independently obtain evidence.
- What resource measurements they can actually verify.
- How they detect inflated CPU usage.
- How conflicting measurements are handled.
- Whether attestors share infrastructure or administrative control.
- What happens when an attestor becomes unavailable.
- How attestor misconduct is detected and penalized.

For CPU execution, a credible initial design could use a controlled runtime with independently observable execution boundaries and measurements. Merely collecting duplicate signatures over provider-supplied counters is insufficient for independent measurement assurance.

The report acknowledges that operational collectors and reconciliation are still missing. Pasted text(20261114-082746)

### Billing scope

The current adapter supports consumed CPU.

That is appropriate for an initial finite-compute settlement implementation.

It should not automatically claim support for:

- RAM-hours.
- Persistent storage.
- Network egress.
- GPU utilization.
- Long-running application availability.

Each requires a defined measurement standard and verification method.

---

# 4. Next major component: Hosted Service Settlement

This is now the most important missing product capability.

Greenwood has two execution paths:

**Finite compute:** A request executes, produces a terminal receipt, and can settle.

**Hosted applications:** A service starts, remains running, and must be billed over successive intervals.

The first path now has a local financial implementation.

The second does not.

The report confirms that hosted deployments on economic chains deliberately reject activation with:

`HOSTED_SERVICE_SETTLEMENT_NOT_QUALIFIED`

That safeguard should remain until the hosted adapter is complete. Pasted text(20261114-082746)

## Recommended hosted billing integration

```text
Application Registry
        |
        v
Authorized Deployment
        |
        v
Accepted Provider Quote
        |
        v
Funded Service Interval
        |
        v
Deployment Controller
        |
        v
Existing Assignment Distribution
        |
        v
ProviderApplicationOperator
        |
        v
Running Application
        |
        v
Interval Measurement
        |
        v
Usage Attestation
        |
        v
Native GWD Settlement
        |
        v
Next Funded Interval
```

The hosted adapter must reuse the new native `ServiceSettlement` engine.

It should not create another escrow implementation.

### Required hosted capabilities

| Capability | Requirement |
|---|---|
| Service identity | Bound to Application Registry authority |
| Provider assignment | Bound to the actual approved assignment generation |
| Pricing | Accepted provider GWD/GRU quote |
| Funding | Finalized before service activation |
| Recurring billing | Independent, bounded settlement intervals |
| Recovery | Retain interval identity across application restarts |
| Metering | Verify applicable resource consumption |
| Payment | Native GWD settlement |
| Subsidies | Existing protected program budgets |
| Expiration | Stop or restrict service when funding expires |
| Overlap protection | Prevent duplicate billing for the same authorized resources |

The controller should never interpret successful application deployment as evidence that payment has occurred.

Deployment readiness and financial settlement remain distinct states.

---

## 5. Review the challenge and refund model

The candidate implementation introduces:

| Event | Mechanism |
|---|---|
| Verified usage claim | 4,320-block challenge period |
| Disputed claim approved | New delay before payment |
| Unattested commitment | Refund after deadline plus 4,320 blocks |
| Unresolved dispute | Refund after 43,200 blocks |
| Completed settlement | Provider payment and unused-fund refunds |

These rules are deterministic, but their economic consequences need testing.

A legitimate provider could execute a workload and receive no compensation if independent attestors disappear.

Conversely, colluding attestors could authorize fraudulent consumption.

The economic model needs explicit answers for both scenarios.

I would require tests for attestor outages, malicious claims, disputed measurements, provider crashes, and customer attempts to delay settlement.

---

# 6. Two production issues that should not be postponed

### A. State growth

The implementation currently stops admitting new programs at 1,000 and financial commitments at 10,000.

Those limits prevent unbounded growth, but they are not a permanent production retention strategy.

The protocol needs pruning or archival that preserves enough authenticated history to prevent old commitments and settlements from being replayed.

Deleting old records without durable replay protection would create a financial-security vulnerability.

### B. Lost-quorum recovery

The Institute still lacks a ratified mechanism for recovering from permanently lost treasury signing authority.

A financial settlement engine does not resolve governance custody failures.

Recovery must remain incapable of directly spending reserve funds.

Both issues are explicitly outstanding in the implementation report. Pasted text(20261114-082746)

---

# 7. Validation assessment

The report records the following local results:

| Test category | Passing checks |
|---|---:|
| Core economics, per allocation | 64 |
| Compute settlement | 65 |
| Certified-chain foundation, per allocation | 25 |
| Fresh-chain identity | 9 |
| Deployment/registry regression | 12 |

The reported syntax checks also passed for 91 PHP files.

These results support the implementation's local correctness claims.

They do **not** establish distributed execution, full service-history replay, independently verified metering, or real GWD payment finality. Pasted text(20261114-082746)

---

# 8. Updated Greenwood production roadmap

| Priority | Component | Status |
|---|---|---|
| P0 | Native GWD accounting | Implemented locally |
| P0 | Protected Institute treasury | Implemented locally |
| P0 | Provider-signed GRU quotations | Implemented locally |
| P0 | Finite compute escrow and settlement | Implemented locally |
| **P0** | **Hosted application recurring settlement** | **Not implemented** |
| **P0** | **Operational independent usage measurement** | **Not implemented** |
| P1 | Full service-history replay and state retention | Incomplete |
| P1 | JavaScript SDK financial transaction support | Incomplete |
| P1 | Institute lost-quorum recovery | Incomplete |
| P1 | Real infrastructure cost model | Not established |
| P1 | Physical distributed acceptance | Not completed |
| P1 | Independent security qualification | Not completed |

## Final assessment

Greenwood now has a coherent local implementation connecting:

**GWD → GRU quotation → funded compute request → provider execution → receipt → financial settlement.**

That is a substantial architectural milestone.

The remaining integration should focus on completing the same lifecycle for long-running applications rather than introducing new marketplaces or competing payment systems.

**Next engineering milestone: implement hosted recurring settlement using the existing Application Registry, Deployment Controller, ProviderApplicationOperator, and native ServiceSettlement engine.**

In parallel, operationally independent CPU metering must be demonstrated before Institute-subsidized production payments are enabled.

**Mainnet activation remains blocked.**