# Greenwood — Integrating GRU Settlement With Existing Compute Infrastructure

> **Historical scope — reviewed November 24, 2026.** This archived proposal/review preserves its original assumptions, recommendations and test reports. It does not independently adopt policy or describe the latest implementation.
> Read the [economics index](../README.md) for current source and policy precedence,
> and [project status](../../PROJECT_STATUS.md) for release/installation and qualification.

**The existing provider, assignment, reservation, and receipt systems should be the foundation of GRU settlement. They should not be replaced by a parallel infrastructure marketplace.**

I inspected the published `dreambigtechnologycorp/greenwood-blockchain` repository.

The code already contains a substantial execution lifecycle:

| Existing component | Current responsibility | GRU integration |
|---|---|---|
| `ComputeRequest` | Defines the workload, resource limits, deadline, and maximum payment | Bind an accepted service quote and funding authorization |
| `ComputeAssign` | Identifies the assigned provider, generation, and deadline | Bind settlement to that provider and assignment |
| `ComputeState` | Maintains finalized requests, assignments, receipts, and capabilities | Enforce financial lifecycle transitions |
| `ReservationLedger` | Tracks reserved CPU, memory, PIDs, and job slots | Keep capacity reservations separate from financial escrow |
| `ProviderWorker` | Executes workloads through containerd | Collect resource measurements |
| `ReceiptBuilder` | Constructs provider-signed execution receipts | Supply evidence for billing |
| `ComputeReceiptVerifier` | Checks receipt signatures, assignment linkage, deadlines, and duplicates | Add independent billable-usage verification |
| `ProviderJobRegistry` | Maintains durable local execution and receipt-submission state | Reconcile submissions without double payment |

## 1. Critical distinction: Greenwood has different kinds of leases

The repository contains `src/Compute/Containerd/Service/LeaseService.php`.

**That lease is a containerd resource-management lease, not a commercial compute lease.**

It protects images, snapshots, and related containerd metadata from garbage collection during execution.

The commercial equivalent of a lease is currently spread across:

- The finalized `ComputeRequest`.
- The assigned provider and generation.
- Resource-capacity reservations.
- Execution deadlines.
- The terminal compute receipt.

These objects already provide much of what a billable compute lease needs.

The missing component is a **financial commitment connecting that lifecycle to GRU pricing and GWD settlement**.

I would build that connection rather than repurpose containerd's internal lease IDs.

## 2. Greenwood already has payment-related fields

The existing `ComputeRequest` includes:

```text
requester
request_nonce
workload_digest
input_digest
trust_class
cpu_limit_millis
memory_limit_bytes
pid_limit
execution_timeout_ms
output_retention_bytes
max_payment
deadline_height
```

The important field is:

**`max_payment` — already encoded as a 64-bit native-chain amount.**

This means Greenwood's compute protocol was designed with a payment ceiling before the new Institute economics implementation.

That is the natural place to connect quote acceptance and escrow authorization.

However, the published code does not show that submitting a compute request actually locks `max_payment` or transfers it to a provider.

**A maximum payment field is not an escrow mechanism.**

## 3. Existing receipts are suitable for settlement evidence

The current `ComputeReceiptV2` already records:

- Request ID and assignment generation.
- Provider ID and execution ID.
- Workload and input digests.
- Execution status and exit code.
- CPU time in milliseconds.
- Peak memory consumption in bytes.
- Runtime duration.
- Output commitments.
- Provider signature.

These fields are generated through:

`ContainerdExecutor → WorkerFacts → ReceiptBuilder → ComputeReceiptV2`

The containerd execution path reads Linux cgroup counters through `CgroupAccounting`.

**This is an existing resource-measurement pipeline.**

It should become the input to GRU billing.

However, the measurements have different meanings:

| Measurement | Existing evidence | Billing suitability |
|---|---|---|
| CPU execution time | cgroup CPU accounting | Suitable for CPU-time billing, subject to verification |
| Peak memory | cgroup memory peak | Useful for limits; not proof of GiB-hours |
| Runtime duration | Execution timing | Useful for bounded-duration charges |
| Output bytes | Output commitment | Not equivalent to network egress |
| Storage consumption | No complete billing evidence identified | Requires additional metering |
| GPU utilization | No complete billing evidence identified | Requires additional metering |

For example, charging for 4 GiB of reserved memory over one hour is different from observing a 4 GiB memory peak during a job.

The resource catalog must define which measurement is billable.

### Existing receipt verification

`ComputeReceiptVerifier::verifyV2()` validates the assignment generation, provider identity, signatures, deadlines, and execution identity.

`ComputeState` rejects duplicate terminal receipts.

That provides useful protection against replay and unauthorized receipt submission.

**But a provider signature proves who submitted a measurement—not that the measurement is truthful.**

For Institute-funded subsidies, receipt acceptance and payment authorization must remain separate until the required usage-attestation policy is satisfied.

---

# 4. The existing provider state machine should be preserved

The published supervisor and job registry implement this lifecycle:

```text
DISCOVERED
    |
    v
QUEUED
    |
    v
STARTING
    |
    v
RUNNING
    |
    v
RECEIPT_READY
    |
    v
SUBMITTING
    |
    v
SUBMITTED
    |
    v
SETTLED
```

An important discovery:

**`SETTLED` currently means the provider's terminal receipt was reconciled with finalized chain state. It does not prove that GWD was paid.**

Do not change that behavior without an explicit state-machine migration.

I recommend keeping execution status and financial settlement status independent.

```text
EXECUTION                    FINANCE

DISCOVERED                  QUOTED
    |                          |
ASSIGNED                    AUTHORIZED
    |                          |
RUNNING                     FUNDED
    |                          |
RECEIPT_READY               USAGE_PENDING
    |                          |
SUBMITTED                   VERIFIED
    |                          |
RECEIPT_FINALIZED           PAYMENT_FINALIZED
                               |
                            REFUNDED
```

This separation is necessary because a valid execution receipt might describe a failed, timed-out, or terminated workload.

The applicable billing policy determines whether any resources are chargeable.

It also prevents a failed settlement transaction from making Greenwood incorrectly mark a successfully executed workload as unexecuted.

---

# 5. Recommended unified GRU settlement architecture

I would implement one shared settlement engine with two workload adapters.

```text
                  GREENWOOD APPLICATION / CLIENT
                               |
                      Resource Request
                               |
                               v
                      PROVIDER PRICING
                      Signed GRU Quote
                               |
                               v
                      CUSTOMER ACCEPTANCE
                               |
                               v
                      ECONOMIC STATE
                       GWD ESCROW
                               |
                 +-------------+-------------+
                 |                           |
                 v                           v
           COMPUTE JOB                 HOSTED APPLICATION
                 |                           |
          ComputeRequest              Application Registry
                 |                           |
           ComputeAssign              Deployment Controller
                 |                           |
           ProviderWorker              ProviderApplicationOperator
                 |                           |
          ComputeReceiptV2             Periodic Usage Evidence
                 |                           |
                 +-------------+-------------+
                               |
                               v
                      USAGE VERIFICATION
                               |
                               v
                       GRU CALCULATION
                               |
                               v
                    NATIVE GWD SETTLEMENT
                               |
                    +----------+----------+
                    |                     |
                    v                     v
              PROVIDER PAYMENT       UNUSED REFUND
```

The common settlement engine should handle balances, funding sources, usage authorization, budget accounting, payment finality, and refunds.

The adapters should retain their existing execution and provider-management responsibilities.

## 6. Compute jobs: exact integration points

For finite compute jobs, I recommend this implementation sequence.

| Stage | Existing component | Required addition |
|---|---|---|
| Quote | Provider identity/capability | Bind provider-signed GWD/GRU offer |
| Request | `ComputeRequest` | Bind accepted quote, funding source, and maximum charge |
| Funding | Native economic state | Lock user and authorized subsidy amounts |
| Assignment | `ComputeAssign` | Require finalized funding for the selected provider and generation |
| Execution | `ProviderWorker` | Preserve existing execution lifecycle |
| Measurement | `WorkerFacts` | Supply typed usage evidence |
| Receipt | `ComputeReceiptV2` | Preserve signed terminal execution evidence |
| Verification | `ComputeReceiptVerifier` | Apply separate approved usage-attestation rules |
| Payment | Native economic state | Settle from escrow exactly once |
| Closure | Economic settlement state | Refund remaining balances |

### The key authorization boundary

The existing provider supervisor starts jobs after observing finalized assignments.

Therefore, adding escrow only at the end would create a free-execution vulnerability.

**The provider must not receive an executable assignment until the funding commitment is finalized.**

Funding and assignment should be atomically linked, or the provider-start authorization must explicitly verify a finalized funded commitment.

Because `ComputeRequest` has a frozen version-1 canonical encoding, do not silently append economic fields to its current signed bytes. Use an explicitly versioned request or a separately finalized financial commitment cryptographically bound to the existing request.

---

# 7. Hosted applications require recurring service leases

The application-hosting subsystem is different from a one-time compute job.

It uses:

- `DeploymentCoordinator`
- `AssignmentDocument`
- `ProviderApplicationOperator`
- `ProviderApplicationRegistry`
- `ProviderObservationService`
- Application recovery and edge-admission services

The controller creates and distributes assignments, checks authorization, starts the workload, and verifies runtime and routing state.

These hosted-application assignments are not ordinary on-chain `ComputeAssign` transactions.

The repository explicitly maintains that distinction.

### Recommended addition: Billable Service Lease

Introduce a financial service-lease record associated with the existing hosted-application assignment.

It should contain:

| Field | Purpose |
|---|---|
| Chain/genesis identity | Prevent cross-network replay |
| Application/registry identity | Identify the authorized application |
| Assignment generation | Bind to the approved deployment |
| Provider/payout identity | Identify the compensated operator |
| Accepted quote ID | Fix the resource pricing |
| Resource limits | Define the service commitment |
| Billing interval | Define the chargeable period |
| Maximum GWD charge | Bound financial exposure |
| Funding authorization | Identify escrow and subsidy sources |
| Lease status | Track funding, execution, expiry, and closure |

**Do not reuse `ProviderApplicationRegistry.lease_id` as a financial lease identifier.**

That field currently tracks a containerd lease used to protect runtime resources.

The billable service lease needs a separate identity, with an explicit reference to the underlying application assignment.

### Recurring settlement

For a continuously running application:

1. Establish a funded billing interval.
2. Verify the application is associated with the approved provider assignment.
3. Collect interval-specific resource evidence.
4. Verify the evidence according to the service policy.
5. Calculate GRU consumption.
6. Settle the authorized GWD charge.
7. Refund or carry forward unused authorization according to policy.
8. Fund the next interval before extending the service commitment.

Each interval must have a unique settlement identity.

A provider restart must not create duplicate billable intervals.

A provider must not be able to bill simultaneously for the same reserved capacity through conflicting service leases unless that is explicitly permitted.

---

# 8. Where the Institute subsidy reserve connects

This is the most important treasury integration point.

The Institute's subsidies reserve should not pay providers simply because they submit a signed receipt.

Instead, settlement should reference four independent pieces of authorization:

1. **Approved program:** The Institute authorized the beneficiary, service type, and subsidy budget.
2. **Accepted quote:** The customer accepted the provider's specific rate and maximum charge.
3. **Valid service assignment:** Greenwood recognizes the provider's authorized workload.
4. **Verified usage:** The required evidence supports the billed consumption.

Only then should the native economic state release GWD.

For example:

```text
Service charge: 1,000 GWD
Subsidy: 80%

Developer escrow: 200 GWD
Subsidy commitment: 800 GWD

Verified service charge: 750 GWD

Provider payment: 750 GWD
Developer contribution: 150 GWD
Subsidy contribution: 600 GWD

Refund to developer: 50 GWD
Release unused subsidy commitment: 200 GWD
```

All payments are existing GWD moving between accounts and protected balances.

No new tokens are created.

GRU determines the charge; it is not transferred as currency.

The refund calculation must use a deterministic rounding and allocation policy so that both funding sources reconcile exactly.

---

# 9. Changes I would actually make in the codebase

**Do not introduce a new provider daemon, second scheduler, separate compute marketplace, or duplicate receipt protocol.**

Extend the existing architecture.

| Code area | Required work |
|---|---|
| `src/Compute/Protocol/` | Versioned economic bindings for compute requests and assignments |
| `src/Compute/Verification/` | Usage-attestation validation separate from execution receipt validation |
| Native economics state | Escrow, subsidy commitments, payable balances, refunds, final settlement |
| `src/Provider/` | Usage collection and signed interval evidence |
| `src/Compute/Containerd/` | Metering adapters using the existing runtime and cgroup integrations |
| `system-services/deployment-controller/` | Funding verification before application deployment and recurring service authorization |
| Application recovery | Preserve billing identity across restarts |
| RPC and SDK | Quote acceptance, funding, receipts, settlement status, refunds |
| Tests | Cross-layer financial and execution invariants |

The native settlement engine must be deterministic and replayable.

Off-chain providers collect evidence.

Validators verify the evidence and authorize ledger transitions.

Validators should not execute containers, read local cgroups, or call external price APIs during consensus.

**Financial settlement must be part of the same atomic consensus state transition as any affected protected-reserve or account balances.**

It must not be implemented as an unverified off-chain ledger that is subsequently trusted by validators.

---

# 10. Additional production blocker found in the code

There is an unrelated but significant issue in:

`system-services/deployment-controller/src/Assignment/AssignmentDocument.php`

The published validator contains this condition:

```php
$d['chain_id'] !== 'greenwood-physical-v1'
```

That is a hard-coded historical chain identity.

Your fresh mainnet is intended to use a newly generated chain ID.

If that code remains active, application assignments on the new chain can be rejected even if the rest of the fresh-cluster installation succeeds.

**This needs to become an explicitly configured, validated production chain identity.**

It must also be covered by cross-chain replay tests.

The current GitHub revision does not include the locally implemented November 13–14 economics changes, according to your implementation reports. Consequently, the integration points above are verified against the published compute/provider source, while the new economics interfaces still require a review of the actual local diff.

---

# 11. Required end-to-end tests

The most important acceptance cases are:

| Test | Expected result |
|---|---|
| Compute assignment without finalized funding | Execution blocked |
| Provider quote does not match assignment | Rejected |
| Receipt from wrong provider | Rejected |
| Receipt for stale assignment generation | Rejected |
| Duplicate terminal receipt | Rejected |
| Valid receipt without sufficient usage evidence | No unauthorized payment |
| Provider reports inflated usage | Attestation policy rejects or disputes it |
| Escrow insufficient for quoted maximum | Authorization rejected |
| Subsidy exceeds beneficiary budget | Rejected |
| Compute job fails before execution | No unearned usage charge |
| Compute job starts and times out | Only explicitly billable verified usage is charged |
| Provider crashes after receipt submission | No duplicate payment |
| Application restarts during a billing interval | Existing interval reconciled |
| Provider claims the same usage twice | Second settlement rejected |
| Customer-funded payment succeeds | Provider receives correct GWD |
| Subsidized payment succeeds | User and reserve debits reconcile |
| Unused escrow remains | Returned to the correct funding source |
| Chain replay reconstructs settlement | Identical state root |
| Historical chain ID used in assignment | Rejected |

Tests must distinguish receipt finality from payment finality.

A correctly finalized receipt does not necessarily establish payable service consumption.

---

## Final architectural recommendation

Greenwood already has three valuable systems:

**1. Resource execution:** Containerd, provider workers, cgroups, and workload management.

**2. Resource authorization:** Compute requests, assignments, scheduling reservations, Application Registry permissions, and deployment-controller approvals.

**3. Execution evidence:** Signed compute receipts, durable provider journals, runtime inspection, and authenticated observations.

The new GRU economy should be the **fourth system: resource accounting and financial settlement**.

It should consume evidence produced by the first three, enforce Institute subsidies and user funding, and settle existing GWD through native consensus.

That avoids rebuilding Greenwood's provider infrastructure while preserving the distinction between compute execution, application hosting, and financial authorization.

**The immediate engineering task is a shared native GRU settlement state machine, integrated with existing compute assignments and hosted-application service leases—not a separate marketplace or new provider stack.**