# Greenwood Mainnet — Institute Treasury and Token Security

> **Historical scope — reviewed November 24, 2026.** The burn proposal, unset percentages and missing-implementation findings below are superseded: current policy uses no burn, six decimals and the selected 15/25/30/25/5 allocation.
> Read the [economics index](README.md) for current source and policy precedence,
> and [project status](../PROJECT_STATUS.md) for release/installation and qualification.

**Decision: Adopt the proposed 1 billion GWD genesis supply and protected-reserve architecture as the production design, subject to implementation and security validation.**

This resolves the central question of how Greenwood's initial token issuance should work.

The Institute does not receive one billion freely spendable tokens in a single wallet. Instead, the entire supply is created at genesis and distributed among independently governed reserves.

**The important distinction is that governance authorizes spending, but consensus enforces spending restrictions.**

## 1. Supply model

| Parameter | Proposed configuration |
|---|---|
| Native asset | GWD |
| Initial supply | 1,000,000,000 GWD |
| Maximum issuance | 1,000,000,000 GWD |
| Issuance event | Genesis only |
| Post-genesis minting | Disabled |
| Security rewards | Released from existing emissions reserve |
| Grants and subsidies | Released from designated reserves |
| Transaction fee burns | Permanently reduce outstanding supply |
| Governance | Greenwood Blockchain Institute |
| Enforcement | Deterministic validator state transitions |

One accounting distinction matters: **one billion GWD is the maximum gross issuance, not necessarily the permanently outstanding supply.** Once protocol fee burns occur, outstanding supply decreases.

The primary supply invariant should be:

\[
\text{Outstanding Supply} =
1{,}000{,}000{,}000-\text{Cumulative Burns}
\]

Every GWD must remain accounted for as a protected reserve balance, circulating account balance, or other valid on-chain balance until it is burned.

## 2. Proposed reserve architecture

| Reserve | Spending authority | Required enforcement |
|---|---|---|
| Security and emissions | Protocol only | Reward schedule, eligibility, reserve exhaustion |
| Grants | Institute governance | Milestone authorization, approvals, delay |
| Subsidies | Institute governance | Program allocation, budget ceilings |
| Institute treasury | Independent signers | Multisig, timelock, spending limits |
| Working operations | Restricted operational authority | Balance ceiling, replenishment restrictions |
| Contributor vesting | Vesting rules | Time-based release and beneficiary restrictions |
| Liquidity | Separate governance mandate | Approved assets, counterparties, spending policy |

The optional reserves should only be included once their funding and governance requirements are established.

No percentages should be assigned yet.

---

## 3. Security policy

The proposed security policy creates three levels of authority.

| Action | Approval | Delay |
|---|---|---|
| Normal discretionary payment | 3-of-5 | 72 hours |
| Large payment | 4-of-5 | 7 days |
| Signer or security-policy change | 4-of-5 | 14 days |
| Security emissions | Protocol authorization | According to reward rules |
| Emergency pause | Authorized guardians | Immediate pause only |

Thresholds must be enforced using signed approvals over a canonical transaction or proposal identifier.

Approvals must bind the chain ID, reserve, recipient, amount, nonce, and policy version.

Timelocks must use consensus-defined time or height semantics, not an individual node's wall clock.

Payment splitting must not bypass spending limits. Limits must aggregate all relevant withdrawals over the applicable period.

Emergency guardians must not be able to transfer funds or silently alter policy.

**A multisignature wallet alone is insufficient.** The ledger must recognize protected reserves as accounts with restricted transition rules.

## 4. Repository review: the economic protections are not implemented yet

I inspected the current GitHub source, including:

- `src/Genesis/Genesis.php`
- `src/State/State.php`
- `src/Transaction/Transfer.php`

The findings matter for the production cutover.

| Requirement | Current source finding |
|---|---|
| Initial allocation | Genesis supports accounts with initial balances |
| Fixed initial supply | Can be configured; explicit supply invariant not verified |
| Separate reserve accounts | Possible through genesis allocation |
| Protected reserve spending | Not enforced by the ordinary transfer path |
| 3-of-5 approvals | Not implemented in the inspected transfer path |
| Withdrawal timelocks | Not implemented in the inspected transfer path |
| Protocol-only emissions | Not established in the inspected state transitions |
| Fee burn and operator distribution | Not established in the inspected state transitions |
| Automatic vesting | Not established |
| Reserve exhaustion rules | Not established |

There is a specific implementation issue: `Transfer.php` serializes payment amounts as unsigned 32-bit integers, while genesis balances use unsigned 64-bit encoding.

That does not prevent a 1-billion-token genesis, but token precision and maximum payment size need explicit review before introducing fractional units.

The current ordinary transfer path verifies the sender's signature, nonce, and available balance. It does not inspect Institute approval thresholds or reserve spending policy.

**Consequently, funding separate genesis addresses today would not automatically create protected Institute reserves.**

That is the principal economic security gap.

## 5. Required implementation architecture

I would divide the economics into three mechanisms.

**Genesis allocation:** Creates the one-billion-GWD supply and assigns the approved amounts to distinct reserves. The reserve allocations must be represented as consensus-enforced protected balances, not merely ordinary accounts with different names.

**Protocol emissions:** Releases existing GWD from the security reserve according to the deterministic schedule. Rewards must identify eligible recipients, handle missed or disputed rewards, and stop or follow an approved fallback when the reserve is exhausted. Rewards must never create additional supply.

**Institute governance:** Authorizes discretionary spending through a deterministic multisignature and timelock state machine. Every validator must independently verify approvals, delays, thresholds, limits, and beneficiary restrictions.

A reserve transaction should follow this sequence:

```text
Proposal created
       |
       v
Validate reserve policy
       |
       v
Collect signed approvals
       |
       v
Verify required threshold
       |
       v
Apply consensus timelock
       |
       v
Recheck policy and budget
       |
       v
Execute authorized transfer
       |
       v
Update balances and audit state
```

A proposal should execute only once. Signatures must be bound to the specific chain, reserve, proposal, and policy version.

The governance rules must also define what happens if approvals are collected before a signer rotation or policy change.

## 6. Impact on the production reinstall

The operational plan now has two separate acceptance gates.

| Gate | Work | Status |
|---|---|---|
| **Infrastructure acceptance** | Node 4 reliability, NFS, cooling, network, runtime installation | Pending |
| **Protocol economics acceptance** | Fixed supply, reserves, emissions, multisig, timelocks, fees | Implementation required |

The infrastructure can be prepared while the economic security work proceeds.

The irreversible genesis cutover must wait until both pass.

### Updated implementation order

1. Finalize token precision and the reserve-allocation budget model.
2. Implement reserve types and consensus-enforced restrictions.
3. Implement protocol-funded emissions and fee accounting.
4. Implement Institute approvals, timelocks, limits, and guardian controls.
5. Verify supply conservation and adversarial transaction behavior.
6. Build a disposable three-validator network using the proposed economics.
7. Reinstall Greenwood using the approved release and new genesis.
8. Deploy the Application Registry and required contracts.
9. Activate the supporting services and complete end-to-end acceptance.

## 7. Final decision

The proposed Institute architecture is internally coherent: fixed genesis issuance, restricted reserves, controlled circulating supply, and governance separated from protocol enforcement.

It also establishes an important boundary between DreamBIG Technology Corporation and the Greenwood Blockchain Institute.

DreamBIG can operate infrastructure, develop software, and receive approved compensation without possessing unrestricted access to the network's entire token supply.

The Institute can govern allocations without being able to bypass the protocol's spending rules.

**The remaining task is turning that governance model into enforceable consensus rules.**

Do not generate the permanent one-billion-GWD genesis until the reserve implementation, supply invariants, and emissions behavior have passed the required tests.