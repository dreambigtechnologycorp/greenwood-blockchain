# Greenwood — Hosted Settlement Implementation Review

> **Historical scope — reviewed November 24, 2026.** This archived proposal/review preserves its original assumptions, recommendations and test reports. It does not independently adopt policy or describe the latest implementation.
> Read the [economics index](../README.md) for current source and policy precedence,
> and [project status](../../PROJECT_STATUS.md) for release/installation and qualification.

**November 14, 2026 | Production Readiness Assessment**

**Verdict: Both compute settlement and hosted recurring settlement are now implemented locally. The remaining critical work is operational verification, independent metering, and production governance.**

This is a significant architectural milestone. Greenwood now has a unified financial settlement engine supporting two distinct infrastructure workloads without duplicating the provider system.

## 1. What is now functional

| Component | Status |
|---|---|
| Native GWD accounting | Implemented |
| Protected Institute reserves | Implemented |
| GRU pricing and quotations | Implemented |
| Finite compute settlement | Implemented |
| Hosted recurring settlement | **Implemented locally** |
| Application Registry authorization | Integrated |
| Provider assignment funding checks | Integrated |
| Native escrow and refunds | Implemented |
| Subsidized service payments | Implemented |
| Billing dispute resolution | Implemented |
| Application funding enforcement | Implemented; physical behavior unverified |
| Independent physical metering | Incomplete |
| Production deployment | Not performed |

The hosted adapter now reuses `ServiceSettlement`, validates actual Application Registry state through WASM, binds funding to approved assignments, and uses the existing provider/controller infrastructure. Pasted text(20261114-154649)

**The original architectural objective has been achieved at the source-implementation level: Greenwood's economics are integrated with its existing provider infrastructure.**

---

## 2. The most important implementation improvement

The system now checks finalized funding before allowing a hosted application to start.

The sequence is:

```text
Application Registry
        |
        v
Approved Deployment
        |
        v
Provider Quote
        |
        v
GWD Escrow
        |
        v
Finalized Funding
        |
        v
Deployment Controller
        |
        v
Provider Application Start
        |
        v
Runtime Monitoring
        |
        v
Interval Usage Attestation
        |
        v
GWD Settlement / Refund
        |
        v
Next Funded Interval
```

The controller checks funding before assignment distribution and runtime startup.

The provider independently checks the existing receiver's committed assignment journal.

That separation matters because a developer cannot simply submit a fabricated financial authorization to start an application.

The system also prevents overlapping billable intervals for the same authorized application/service scope. Pasted text(20261114-154649)

---

# 3. The major remaining problem is metering integrity

Greenwood can now calculate and authorize payment.

It still needs to prove that the reported usage was actually delivered.

The existing design requires independent attestation, but the operational collection and reconciliation system has not been qualified.

### What needs to happen

I would introduce a dedicated **Greenwood Metering Service** within the existing provider/control-plane architecture.

It should not become a separate provider daemon or financial ledger.

Its responsibilities would be:

1. Collect independently observable usage evidence.
2. Bind measurements to the actual provider runtime and funded interval.
3. Reconcile discrepancies between provider measurements and independent observations.
4. Generate bounded, signed usage attestations.
5. Submit evidence to the existing settlement process.
6. Report unresolved measurement conflicts.

**Important:** A second process reading measurements supplied by the same untrusted provider is not an independent witness. The trust model must establish what each attestor can actually observe.

The immediate target should remain CPU metering.

Memory-hours, storage, networking, and GPU accounting require separate measurement definitions.

---

# 4. One economic issue: CPU-only billing is insufficient for hosted applications

This is the biggest remaining commercial-design concern.

Consider a hosted application that stays online continuously but receives almost no traffic.

It still consumes or reserves:

- Memory.
- Storage.
- Network availability.
- Container/runtime capacity.
- Provider management overhead.

Yet its measured CPU consumption may be negligible.

**If Greenwood charges only for consumed CPU, providers can incur costs that the billing model does not recover.**

I recommend eventually supporting two components for hosted-service pricing.

| Charge | Purpose |
|---|---|
| Reserved-capacity charge | Compensates the provider for committed infrastructure |
| Metered-consumption charge | Compensates for verified additional resource usage |

For example, a hosted application could pay for a reserved CPU/memory allocation over an authorized interval, plus actual CPU usage according to the accepted quotation.

This requires clear rules against charging twice for the same capacity.

The current `hosted_cpu_v1` adapter is an appropriate initial settlement implementation, but it should not be presented as comprehensive hosting economics.

---

# 5. Application lifecycle enforcement needs physical testing

The report states that expired or unavailable funding triggers network cleanup and application stopping.

However, enforcement currently depends on polling.

That creates several questions requiring physical acceptance:

| Scenario | Required outcome |
|---|---|
| Funding expires during normal operation | Application becomes restricted according to policy |
| Provider loses blockchain connectivity | Stale financial authorization cannot continue indefinitely |
| Runtime exceeds CPU authorization | Workload is bounded or stopped according to the funded policy |
| Provider process crashes | Existing authorization and billing identities survive recovery |
| Application restarts | No duplicate billing interval |
| Registry ownership changes | New deployments lose unauthorized access |
| Funding is revoked | Existing contractual obligations follow explicit settlement rules |
| Network cleanup fails | Failure is reported and retried, not treated as successful restriction |

The implementation report acknowledges that CPU cutoffs, watchdog behavior, and actual runtime cleanup still need qualification. Pasted text(20261114-154649)

The distinction between authorization and physical enforcement is critical.

A validator can reject an invalid settlement, but it cannot directly guarantee that a remote container stops consuming resources.

---

# 6. Test evidence is stronger, but not production proof

The new results are meaningful.

| Validation | Reported result |
|---|---|
| Core economics | 64 passing checks per allocation |
| Finite compute settlement | 68 passing checks per allocation |
| Hosted settlement | 32 passing checks per allocation |
| Certified-chain foundation | 25 passing checks per allocation |
| Hosted boundary tests | 17 passed |
| Fresh-chain identity | 9 passed |
| Deployment/registry regression | 12 suites passed |
| PHP syntax checks | 131 passed |

The most significant result is the complete hosted-history test.

**24,487 certified blocks were reconstructed with an identical post-restart state root.**

That included program authorization, Application Registry activity, two funded hosted intervals, attested payment, and outage refunds.

However, the full-history test used the original 40/20/15/24/1 allocation. The proposed 10/25/30/34/1 configuration passed the separate hosted transition tests, not that complete certified-history sequence.

These tests establish local deterministic behavior, not production network operation. Pasted text(20261114-154649)

---

# 7. What I would prioritize next

At this point, I would stop expanding the economic architecture until the implemented features have undergone operational acceptance.

### Priority 1 — Independent CPU metering

Implement and validate the existing `INDEPENDENT_CPU_METERING_PLAN.md`.

Acceptance requires genuine independent observations, conflict handling, outage behavior, and resistance to inflated provider measurements.

### Priority 2 — Physical provider integration

Use the existing disposable Greenwood cluster to exercise:

- Actual containerd execution.
- Funded application deployment.
- Receiver assignment verification.
- Running CPU workloads.
- Collection of measurement evidence.
- Signed usage attestations.
- Financial settlement.
- GWD payment finality.
- Refund processing.
- Funded interval renewal.
- Application shutdown on funding expiration.

This is the first complete physical proof of the integrated infrastructure economy.

### Priority 3 — Durable settlement-state management

The current 1,000-program and 10,000-commitment limits remain.

Introduce authenticated archival and pruning without losing replay protection or breaking state reconstruction.

### Priority 4 — Treasury governance

Finalize the lost-quorum recovery design.

The Institute should not permanently initialize hundreds of millions of protected GWD before its signer-recovery policy has been established and independently reviewed.

### Priority 5 — Economic affordability

Complete the operating-budget model before selecting the permanent reserve distribution.

Both 40/20/15/24/1 and 10/25/30/34/1 remain candidates.

---

# 8. Updated production readiness

| System | Status |
|---|---|
| Greenwood consensus | Existing implementation; production acceptance pending |
| GWD tokenomics | Implemented locally |
| Institute treasury | Implemented locally; recovery incomplete |
| GRU quotation | Implemented locally |
| GRU finite compute settlement | Implemented locally |
| GRU hosted recurring settlement | Implemented locally |
| Application Registry integration | Tested locally |
| Existing provider integration | Tested locally with runtime fixtures |
| Independent metering | **Not qualified** |
| Physical execution and settlement | **Not verified** |
| Infrastructure recovery | **Not qualified** |
| Production SDK integration | Incomplete |
| Permanent allocations | Not ratified |
| Mainnet activation | **HOLD** |

## Final assessment

Greenwood now has a coherent, locally tested implementation of its core infrastructure economy:

**GWD reserves → GRU quotations → funded leases → provider execution → verified usage claims → GWD settlement.**

The remaining work is less about inventing new financial mechanisms and more about proving the existing mechanisms under real operating conditions.

**The next milestone should be an end-to-end physical test demonstrating that an application can be funded, deployed, metered, paid for, renewed, and stopped using the existing Greenwood infrastructure.**

Until independent metering and physical enforcement are proven, the production funding gates should remain disabled.