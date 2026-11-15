# Independent CPU measurement — production qualification boundary

Status: **not demonstrated**. Native signatures authorize claims; they do not prove truthful counters. Neither local hosted tests nor certificate replay qualifies Institute-subsidized production usage.

The current adapter bills bounded consumed CPU milliseconds. It does not bill RAM-hours, storage, egress, GPU, application uptime or block-target time. A hosted interval has an explicit accepted CPU budget; 60-second target blocks are not a CPU measurement or a guaranteed wall-clock interval.

## Required measurement boundary

A witness cannot qualify by fetching a provider's JSON, signing its receipt again, or holding a second key under the same administrator. The deployment must document who controls the workload, kernel/cgroup boundary, observer executables, signing keys, clock and evidence storage. If the provider can rewrite both purportedly independent observations, the assurance requirement fails.

A controlled pilot must put the measurement boundary under an administrator independent from the provider payout owner. At least two separately operated observation paths must collect their own attributable evidence. One possible experiment combines witness-controlled cgroup accounting with separately administered task/process CPU observation; their accounting semantics and tolerances must be measured, not assumed equivalent. Hypervisor vCPU run time includes different overhead and requires a separately reviewed catalog; it must not silently replace container CPU time.

## Evidence and reconciliation contract

1. Bind every observation to chain/genesis, program/catalog, financial commitment, full approved assignment generation and exact interval. Capture boot identity, cgroup identity, runtime/task incarnation and counter provenance.
2. Collect start/end boundaries before counters disappear. A restart opens a new uniquely identified segment and closes the previous one. Do not subtract a reset counter or reuse a segment in another interval. A PID alone is not a durable identity.
3. Publish signed raw observations to durable independently accessible storage. Reconciliation must retain all source records, discrepancies, sampling gaps and runtime lineage. The chain's `measurement_hash` and `runtime_lineage_hash` bind the agreed evidence bundle; validators currently check quorum authorization, not the truth of each raw observation.
4. Define quantization, missing-sample rules and any method-specific tolerance in a versioned catalog before funding. Conflicting or unverifiable evidence produces no automatic payable claim. It enters the existing dispute/refund path.
5. Require organizational and infrastructure independence as well as separate keys. Maintain an incident/revocation process. Current code has no automatic attestor slashing, misconduct oracle or proven collusion resistance; do not describe those as implemented.

## Required physical experiment

Run a disposable cluster with known CPU workloads and controlled runtime boundaries. Test idle CPU, bounded load, startup failure, timeout, counter reset, PID reuse, container restart, provider crash, witness outage, conflicting samples and an inflated provider-signed receipt. Independently reproduce the charge and evidence hashes. Demonstrate that each witness can refuse a fraudulent claim without trusting provider-supplied counters. Record machine/operator ownership and actual enforcement privileges.

Also validate runtime budget enforcement and a bounded operational response to stale/unavailable chain heads. Current expiry enforcement polls finalized height; a stalled height can extend an interval in wall-clock time. A physical qualification must establish local CPU-budget cutoffs and halt/watchdog behavior before production hosting is allowed. These cannot be inferred from the 60-second target or from a successful local billing test.

An unavailable council can leave legitimate work unpaid: unattested usage refunds after the evidence deadline, and unresolved disputes refund after the dispute deadline. The implemented rule bounds customer/Institute exposure rather than guaranteeing provider compensation. Model that risk in quoted prices, council availability and program budgets. Do not increase the subsidy ceiling or mint replacement GWD to disguise it.

Until these measurements, custody and enforcement properties are demonstrated and independently reviewed, keep `hosted_settlement_candidate_enabled` false in production and retain the mainnet HOLD. Candidate experiments belong on disposable infrastructure.
