# Greenwood Economics — Design Review

> **Historical scope — reviewed November 24, 2026.** This archived proposal/review preserves its original assumptions, recommendations and test reports. It does not independently adopt policy or describe the latest implementation.
> Read the [economics index](../README.md) for current source and policy precedence,
> and [project status](../../PROJECT_STATUS.md) for release/installation and qualification.

**Assessment: The comparison validates both models technically, but neither is ready for permanent genesis ratification.**

The November 14 report establishes three useful results:

1. Both allocations pass the same native economic tests.
2. Reducing security reserves does not change the existing validator reward schedule.
3. The most immediate commercial constraint is GRU settlement and spending authorization, not the size of the subsidy reserve.

The updated implementation also separates protected reserves, pending withdrawals, operations accounts, unrestricted accounts, and contract balances in its supply reporting. Provider-signed GWD/GRU quotations are implemented, but actual settlement remains incomplete. Pasted text(20261114-074409)

## 1. Which allocation should advance?

**Advance 10/25/30/34/1 as the preferred candidate for budget modeling, not as a finalized allocation.**

| Reserve | Candidate allocation |
|---|---:|
| Security and emissions | 100M GWD |
| Grants | 250M GWD |
| Infrastructure subsidies | 300M GWD |
| Institute treasury | 340M GWD |
| Working operations | 10M GWD |
| **Total** | **1B GWD** |

The strongest mathematical argument for this candidate is that 100 million GWD supports approximately 106 nominal years of the existing reward schedule.

However, there is **no demonstrated economic need for 106 years of committed security rewards**, either.

The final allocation should follow a 10–20-year financial model, not simply favor the smaller of two very large reserves.

A useful policy would be to guarantee 16 years of scheduled emissions, establish a security contingency, and keep remaining capital available for other explicitly governed uses.

## 2. The subsidy budget matters more than the subsidy allocation

The proposed model reserves 300 million GWD for subsidies, but permits only 300,000 GWD per rolling 43,200-block window.

At an 80% subsidy and 1,000 GRU per developer:

| Accepted price | Maximum fully subsidized pilot developers per window |
|---|---:|
| 1 GWD/GRU | 375 |
| 5 GWD/GRU | 75 |
| 10 GWD/GRU | 37 |

These limits assume no other subsidy expenditures and sufficient verified provider capacity.

**This is desirable.** The Institute should grow subsidy spending based on actual adoption rather than immediately making hundreds of millions of GWD available.

I would retain the 300,000-GWD initial ceiling and authorize a pilot of 100 developers only when accepted quotations fit the ceiling.

Do not increase spending limits merely because more applications request subsidies.

## 3. Signer recovery is now a critical security-design decision

The stress testing exposes a serious governance problem.

With five treasury signers:

| Available signers | Ordinary spending | Policy rotation |
|---|---|---|
| 5 | Possible | Possible |
| 4 | Possible | Possible |
| 3 | Possible | Blocked |
| 2 | Blocked | Blocked |

If two signing identities are permanently lost, the Institute cannot rotate its signers under the existing 4-of-5 policy.

That could permanently lock hundreds of millions of GWD.

I recommend implementing a **separate emergency recovery authority** with these restrictions:

- Recovery can replace compromised or lost signers, but cannot transfer GWD.
- Recovery requires an independent threshold of recovery authorities.
- Recovery changes have a substantially longer delay than ordinary policy rotation.
- Recovery events are publicly visible.
- Existing uncompromised signers retain a defined challenge or veto mechanism.
- Recovery cannot reduce the protocol's immutable security floors.

For example, a separately constituted 5-of-7 recovery council with a 30-day finalized-height delay is a possible candidate.

This introduces another trust assumption and attack surface, so it requires independent review rather than automatic adoption.

The recovery mechanism must be tested against collusion, compromised recovery keys, and malicious signer replacement.

The November 14 implementation report explicitly identifies lost-quorum recovery as unresolved. Pasted text(20261114-074409)

## 4. GRU settlement is the next protocol implementation milestone

The provider-signed quotation mechanism is now a useful foundation.

A provider can offer a service at an exact GWD/GRU rate without requiring an external USD oracle.

However, Greenwood still needs:

| Component | Required behavior |
|---|---|
| Customer authorization | Explicitly accept a specific quotation |
| Escrow | Reserve user and subsidy funds |
| Metering | Record attributable resource consumption |
| Attestation | Verify authorized usage evidence |
| Settlement | Pay providers for verified consumption |
| Refunds | Return unused balances |
| Disputes | Resolve conflicting usage claims |
| Subsidy accounting | Enforce beneficiary and program budgets |
| Replay prevention | Prevent duplicate payments |

Until those mechanisms work, the subsidies reserve should remain inaccessible.

The current implementation correctly prevents direct subsidy withdrawals. Pasted text(20261114-074409)

## 5. Decisions ready to retain

| Decision | Assessment |
|---|---|
| 1 billion GWD fixed issuance | Retain |
| Six-decimal precision | Retain |
| No fee burning | Retain |
| 60-second target blocks | Retain |
| Existing validator emission schedule | Retain for the current model |
| GRU as nontransferable accounting | Retain |
| Provider-signed GWD quotations | Retain |
| Native protected reserves | Retain |
| Five independent treasury signers | Retain, subject to custody verification |
| Height-based timelocks | Retain, with explicit timing disclosures |
| 10/25/30/34/1 allocations | Preferred candidate, not ratified |
| 300,000-GWD subsidy ceiling | Retain for pilot testing |
| Permanent genesis | Not authorized |

## 6. Recommended next execution sequence

**Phase 1 — Complete GRU settlement.** This establishes the full resource-to-payment lifecycle needed for Greenwood's infrastructure economy.

**Phase 2 — Implement lost-quorum recovery.** This prevents permanently inaccessible Institute reserves while preserving spending restrictions.

**Phase 3 — Establish a real operating budget.** Quantify validator expenses, Institute administrative expenses, GRU provider costs, expected subsidy utilization, and realistically available revenue.

**Phase 4 — Recalculate reserve requirements.** Use those costs to determine whether 10/25/30/34/1 is still the appropriate allocation.

**Phase 5 — Complete distributed acceptance.** Run the integrated economics, contracts, registry, and service settlement on a disposable physical cluster before the permanent genesis ceremony.

### Final judgment

Greenwood has moved beyond basic token allocation design.

The protocol reportedly enforces supply conservation, treasury approvals, emissions, budgets, and fee distribution. Both allocation candidates now pass the same reported consensus-level economic regression suite.

The unresolved issues are more specific:

- Whether the allocations reflect real economic obligations.
- Whether the Institute can recover from permanent signer loss.
- Whether GRU consumption can result in secure, verified provider payments.
- Whether the entire system works under production network conditions.

**Keep the proposed 10/25/30/34/1 allocation as the working candidate. Complete GRU settlement and treasury recovery before authorizing permanent genesis.**