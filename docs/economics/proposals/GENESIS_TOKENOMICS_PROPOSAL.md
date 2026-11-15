# Greenwood Blockchain — Genesis Tokenomics Proposal
**Greenwood Blockchain Institute | Mainnet Economic Policy v1**

**Recommendation: 1 billion GWD, six decimal places, five funded reserves at genesis, and protocol-enforced spending controls.**

I would leave contributor vesting and liquidity allocations at zero until there are specific obligations requiring them.

That avoids allocating tokens to hypothetical expenses while preserving the ability to fund those purposes later through Institute governance.

There are three decisions to make:

1. **Token precision:** How GWD is represented, transferred, and divided.
2. **Genesis allocations:** How the 1 billion GWD is distributed across protected reserves.
3. **Spending limits:** How much can leave those reserves, who approves it, and under what conditions.

The numbers below are a concrete starting policy, not an adopted Institute resolution.

---

# 1. Token Precision

## Recommendation: 6 decimals

| Property | Value |
|---|---|
| Token | Greenwood |
| Symbol | GWD |
| Maximum genesis issuance | 1,000,000,000 GWD |
| Decimals | 6 |
| Smallest unit | 0.000001 GWD |
| Base units per GWD | 1,000,000 |
| Total genesis base units | 1,000,000,000,000,000 |
| Additional minting | Prohibited |

For example:

| GWD | Base units |
|---:|---:|
| 0.000001 | 1 |
| 0.001 | 1,000 |
| 0.01 | 10,000 |
| 1 | 1,000,000 |
| 10 | 10,000,000 |
| 1,000 | 1,000,000,000 |

### Why six decimals?

Greenwood is designed to pay for infrastructure consumption.

That includes CPU time, application execution, storage, bandwidth, and other metered services.

Whole-token accounting is unnecessarily restrictive for small consumption charges.

Six decimals allow fractional accounting without introducing floating-point arithmetic into consensus.

They also fit comfortably within signed 64-bit integers at the proposed total supply.

### Required protocol change

I inspected Greenwood's current GitHub implementation.

`src/Genesis/Genesis.php` uses 64-bit encoding for initial balances, but `src/Transaction/Transfer.php` currently uses 32-bit unsigned transfer amounts.

With six decimals, the existing transfer format permits a maximum individual transfer of approximately **4,294.967295 GWD**.

That is inadequate for Institute treasury operations.

**Before genesis, standard transfers and every other amount-bearing operation need a consistent integer-unit specification.**

I recommend unsigned 64-bit canonical encoding with checked arithmetic, while the PHP implementation remains limited to amounts representable by its signed 64-bit integer type.

This is a consensus-format change. It requires protocol-version handling, updated signing clients, transaction validation, contract accounting, and tests.

No floating-point amounts should enter consensus.

---

# 2. Proposed Genesis Allocations

I would initially divide the 1 billion GWD as follows.

| Reserve | Allocation | GWD |
|---|---:|---:|
| Security and emissions | 40% | 400,000,000 |
| Ecosystem grants | 20% | 200,000,000 |
| Infrastructure subsidies | 15% | 150,000,000 |
| Institute treasury | 24% | 240,000,000 |
| Working operations | 1% | 10,000,000 |
| Contributor vesting | 0% | 0 |
| Liquidity | 0% | 0 |
| **Total** | **100%** | **1,000,000,000** |

### Why this distribution?

**Security receives the largest allocation.** Greenwood needs a funded incentive mechanism for validators and infrastructure providers before it can rely on substantial transaction revenue.

**Grants receive 20%.** The Institute's purpose includes expanding access to computing infrastructure and supporting development in the ecosystem.

**Subsidies receive 15%.** This funds actual service consumption. It can support application hosting, compute, bandwidth, and other approved infrastructure programs.

**The Institute treasury receives 24%.** This is the strategic reserve for long-term sustainability, security, and operational contingencies.

**Working operations receive 1%.** This creates a separately restricted operational reserve. Only a much smaller amount should be released to an ordinary spending wallet.

The allocations preserve a distinction between **resources assigned to a mission** and **tokens authorized for immediate expenditure**.

A reserve holding 200 million GWD does not mean 200 million GWD is circulating.

---

# 3. Security and Emissions Reserve

**Allocation: 400 million GWD**

This reserve cannot be controlled by an ordinary private key.

Its funds are released exclusively through deterministic protocol rules.

## Existing proposed emissions schedule

The schedule you recovered from Codex is:

| Period | Reward |
|---|---:|
| Years 1–4 | 10 GWD/block |
| Years 5–8 | 7.5 GWD/block |
| Years 9–12 | 5 GWD/block |
| Years 13–16 | 2.5 GWD/block |
| Year 17 onward | 1 GWD/block |

The problem is that **block rewards depend on actual block production frequency**.

Assuming an average ten-second block interval and 365-day years:

| Period | Estimated GWD released |
|---|---:|
| Years 1–4 | 126,144,000 |
| Years 5–8 | 94,608,000 |
| Years 9–12 | 63,072,000 |
| Years 13–16 | 31,536,000 |
| **First 16 years** | **315,360,000** |
| Remaining reserve | 84,640,000 |

Under that assumption, the remaining reserve would support another approximately 26.8 years at 1 GWD per block.

However, with six-second blocks, the first 16 years would require approximately 525.6 million GWD, exceeding the proposed 400-million-GWD allocation.

**Therefore, the existing reward schedule cannot be adopted without defining the block cadence and reserve-exhaustion behavior.**

### Recommended emissions policy

I would establish four rules.

**Rule 1 — Rewards are paid from the existing reserve.**

No new GWD is minted when blocks are finalized.

**Rule 2 — Rewards follow finalized block height.**

The emission schedule should use explicitly defined block-height epochs, avoiding disagreement over calendar-year boundaries.

**Rule 3 — The emissions reserve cannot become negative.**

Once the available balance is insufficient for a scheduled reward, the protocol releases only the permitted remaining amount and then ends reserve-funded rewards, or applies another explicitly approved deterministic policy.

**Rule 4 — Unused emissions remain restricted.**

The Institute cannot redirect unspent security allocations into ordinary spending merely because emissions are lower than forecast.

### My preferred adjustment

Use a **400-million-GWD hard emissions budget**, with the reward schedule calibrated to a target block interval and the actual production economics.

If Greenwood finalizes blocks substantially faster than ten seconds, either the epoch boundaries or reward quantities must change to preserve the intended economic runway.

The reward distribution also needs to distinguish block proposers from other participating validators. A reward paid only to proposers creates different incentives from one distributed among all eligible participants.

---

# 4. Institute Spending Limits

I would divide spending into three categories.

## A. Ordinary discretionary spending

Applicable to grants, subsidies, and Institute treasury transfers.

| Rule | Proposed limit |
|---|---|
| Approval threshold | 3-of-5 |
| Withdrawal delay | 72 hours |
| Standard payment ceiling | Less than 100,000 GWD |
| Budget requirement | Must belong to an approved program or budget |
| Recipient | Explicitly identified in the approved proposal |
| Execution | Once, after timelock expiration |
| Cancellation | Authorized before execution |

A payment should not be executable merely because three signers approve it.

It must also satisfy the applicable reserve budget and policy.

## B. Large discretionary spending

| Rule | Proposed limit |
|---|---|
| Large payment threshold | 100,000 GWD or more |
| Approval threshold | 4-of-5 |
| Withdrawal delay | 7 days |
| Budget authorization | Required |
| Additional protection | No bypass of reserve-level limits |
| Execution | Once, after timelock expiration |

Large payments should include aggregate transfers to the same beneficiary or related beneficiaries, preventing threshold evasion through payment splitting.

## C. Governance and security changes

| Action | Requirement |
|---|---|
| Replace a signer | 4-of-5, 14 days |
| Change approval thresholds | 4-of-5, 14 days, subject to immutable security floors |
| Change withdrawal delays | 4-of-5, 14 days, subject to immutable security floors |
| Modify discretionary spending caps | 4-of-5, 14 days |
| Add an emergency guardian | 4-of-5, 14 days |
| Pause discretionary withdrawals | Authorized emergency guardian |
| Resume after emergency pause | 4-of-5, with a defined security delay |
| Redirect emissions reserve | Prohibited under the proposed genesis policy |
| Increase maximum supply | Prohibited |

The ordinary five-signer policy also needs a recovery mechanism for permanently lost keys.

A recovery mechanism must not introduce an unrestricted administrator capable of bypassing the same controls.

---

# 5. Reserve-specific Spending Budgets

The total reserve allocation is not its authorized expenditure budget.

I would establish the following initial limits.

| Reserve | Total allocation | Initial monthly release ceiling |
|---|---:|---:|
| Grants | 200,000,000 | 1,000,000 GWD |
| Subsidies | 150,000,000 | 750,000 GWD |
| Institute treasury | 240,000,000 | 1,200,000 GWD |
| Working operations | 10,000,000 | 100,000 GWD |
| Security emissions | 400,000,000 | Determined by protocol |

The grants, subsidies, and Institute treasury limits are each **0.5% of their original allocations per month**.

These are ceilings, not spending targets.

An Institute program could spend nothing in a particular month.

Unspent monthly capacity should not automatically accumulate into an unlimited future withdrawal allowance.

### Working wallet

I would separate the 10-million-GWD working reserve from the actual operational wallet.

| Property | Initial policy |
|---|---|
| Working reserve | 10,000,000 GWD |
| Maximum operational wallet balance | 100,000 GWD |
| Maximum monthly replenishment | 100,000 GWD |
| Individual routine payment | 5,000 GWD maximum |
| Monthly operational expenditure ceiling | 100,000 GWD |
| Replenishment | Authorized, budgeted transfer |
| Direct access to Institute treasury | None |

The working wallet should have a limited ability to pay routine expenses without collecting three signatures for every small payment.

Its funding and replenishment, however, must remain subject to Institute governance.

A smaller hot-wallet float may be appropriate at launch.

**These token-denominated limits are provisional.** Without a reliable GWD market price or established token-denominated vendor budgets, 100,000 GWD cannot be equated to a particular amount of purchasing power.

The final budget should also specify expected expenses in USD or another stable accounting unit, without making consensus dependent on an untrusted exchange-rate feed.

---

# 6. How the limits must be enforced

The network should not rely on a dashboard or a multisignature wallet application to enforce these rules.

Every validator must evaluate the same reserve policy during transaction execution.

A withdrawal must fail if any of the following conditions is true:

- The approval threshold has not been reached.
- The required timelock has not elapsed.
- The proposal was already executed or canceled.
- The recipient, amount, chain ID, or policy version differs from the approved proposal.
- The transfer exceeds the available reserve balance.
- The applicable spending budget has been exhausted.
- The withdrawal exceeds the account's authorized limits.
- The reserve has been paused.
- The requested operation violates an immutable reserve restriction.

### Budget accounting

I recommend using rolling block-height windows representing approximately 30 days, rather than relying on calendar months.

The implementation must specify exactly how those windows behave.

Pending withdrawals should reserve budget capacity at approval or scheduling time. Otherwise, multiple independently approved withdrawals could collectively exceed the limit when executed.

Cancellation should release reserved capacity. Execution should convert reserved capacity into actual expenditure.

### Large-payment exception

The 4-of-5 approval threshold should not automatically bypass monthly spending limits.

If an approved project requires a larger allocation, governance should first authorize a temporary, bounded program budget through the 14-day policy-change process.

That prevents large-payment authorization from becoming an unrestricted treasury withdrawal mechanism.

---

# 7. Economic sustainability

This is where I would distinguish **token allocation** from **actual operational financing**.

Greenwood has two potential sources of network compensation:

1. Reserve-funded infrastructure incentives.
2. Fees paid by users of the network.

The system should eventually rely increasingly on actual network usage rather than treasury-funded incentives.

But there is no guarantee that fee revenue will replace reserve emissions.

### Proposed fee model

Retain the previously discussed policy as a candidate:

| Fee distribution | Allocation |
|---|---:|
| Operator compensation | 80% |
| Permanent burn | 20% |

Example: If a transaction incurs a fee of 10 GWD, 8 GWD compensates eligible operators and 2 GWD is permanently burned.

This must be implemented as an explicit balance-changing consensus transition.

The fee schedule should distinguish simple transfers, contract execution, and off-chain compute services. CPU fuel metering alone does not establish economic settlement.

Compute-provider compensation may require its own settlement logic rather than treating every service payment as an ordinary blockchain transaction fee.

### Important economic consideration

A fixed supply plus ongoing fee burns means total outstanding supply declines.

That is acceptable if intentional.

However, burns cannot replenish the emissions reserve.

The Institute must therefore publish both:

- Remaining emissions runway.
- Actual operator fee revenue and compensation.

This allows the network to measure whether it is becoming economically self-sustaining.

---

# 8. Proposed Genesis Economic Manifest

This is the policy I would give Codex to implement and test.

It is a **design specification**, not an existing Greenwood configuration file.

```yaml
network:
  name: Greenwood
  chain_id: greenwood-mainnet-v1

asset:
  name: Greenwood
  symbol: GWD
  decimals: 6
  initial_supply: 1000000000
  post_genesis_minting: false

reserves:
  security_emissions:
    allocation: 400000000
    authority: protocol
    ordinary_transfers: false
    governance_withdrawals: false

  grants:
    allocation: 200000000
    authority: institute
    monthly_spending_limit: 1000000

  subsidies:
    allocation: 150000000
    authority: institute
    monthly_spending_limit: 750000

  institute_treasury:
    allocation: 240000000
    authority: institute
    monthly_spending_limit: 1200000

  working_operations:
    allocation: 10000000
    authority: restricted_operations
    monthly_spending_limit: 100000
    hot_wallet_balance_limit: 100000

governance:
  signers: 5

  ordinary:
    approvals: 3
    delay_hours: 72
    large_payment_threshold: 100000

  large:
    approvals: 4
    delay_hours: 168

  policy_changes:
    approvals: 4
    delay_hours: 336

  emergency:
    guardian_pause_enabled: true
    guardian_spending_enabled: false

economics:
  emissions:
    reserve: security_emissions
    post_genesis_minting: false
    exhaustion_policy: stop_at_zero

  fees:
    operator_share_percent: 80
    burn_percent: 20
```

The implementation must convert displayed GWD amounts into integer base units using exact decimal parsing.

It must also replace hours and monthly accounting periods with deterministic consensus rules and define all signer, recipient, and policy identities.

---

# 9. Decisions I would lock versus keep provisional

| Decision | Recommendation | Status |
|---|---|---|
| Genesis issuance | 1 billion GWD | Proposed |
| Token precision | 6 decimals | Ready for implementation review |
| Post-genesis minting | Disabled | Design requirement |
| Security reserve | 40% | Provisional |
| Grants | 20% | Provisional |
| Subsidies | 15% | Provisional |
| Institute treasury | 24% | Provisional |
| Working operations | 1% | Provisional |
| Contributor vesting | 0% initially | Proposed |
| Liquidity | 0% initially | Proposed |
| Discretionary approvals | 3-of-5 | Proposed |
| Large-payment approvals | 4-of-5 | Proposed |
| Policy changes | 4-of-5 | Proposed |
| Withdrawal delays | 72 hours / 7 days / 14 days | Proposed |
| Monthly spending limits | 0.5% for major discretionary reserves | Provisional |
| Working wallet cap | 100,000 GWD | Provisional |
| Fee distribution | 80% operator / 20% burn | Requires implementation |
| Reward schedule | Existing proposed schedule | Requires cadence calibration |

## 10. What remains before final approval

The critical missing input is an actual economic budget.

The proposed allocations are structurally reasonable, but they have not yet been validated against Greenwood's expected infrastructure costs.

The next budget model needs to establish:

| Economic driver | Required estimate |
|---|---|
| Block production | Target seconds per finalized block |
| Validators | Expected active validator count over time |
| Infrastructure rewards | Required GWD compensation per validator |
| Compute providers | Expected workloads and incentive requirements |
| Institute operations | Annual operating expenses |
| Grants | Annual program funding target |
| Subsidies | Expected active users and consumption |
| Network fees | Transaction volume and fee schedule |
| Treasury runway | Minimum number of years of funded operations |
| Token circulation | Expected annual reserve releases |

There is also a distinction between allocating 400 million GWD to security and actually funding validator operating costs. A token-denominated reward has no guaranteed purchasing power. If operators face electricity, connectivity, and hardware costs in dollars, their economic sustainability depends on the token's realized value or another revenue source.

## Final recommendation

I would adopt the following as **Greenwood Economic Policy v1 for implementation and simulation**, without treating it as final genesis authorization:

**Token specification:** 1 billion GWD with six decimal places, exact integer accounting, and no post-genesis minting.

**Reserve distribution:** 40% security, 20% grants, 15% subsidies, 24% Institute treasury, and 1% working operations. Contributor and liquidity allocations remain zero until justified by approved obligations.

**Governance:** Five independent signers, with 3-of-5 ordinary approvals, 4-of-5 elevated approvals, and deterministic withdrawal delays.

**Spending:** Hard reserve budgets, capped working-wallet exposure, protection against payment splitting, and no ordinary spending authority over emissions.

**Production gate:** Finish the consensus accounting changes, calibrate emissions against block cadence, verify spending invariants, and simulate reserve runway before generating permanent genesis keys.

The allocation structure can be established now. The amounts should become permanent only after the budget simulation and security tests demonstrate that Greenwood can sustain its intended validator and infrastructure incentives.