# Greenwood Compute Protocol V1

Source reference reviewed November 24, 2026. The request/assignment V1 and receipt
V2 formats below map to `src/Compute/Protocol`; this documentation introduces no
wire change. See [project status](PROJECT_STATUS.md) for release/qualification
state and [economics](economics/README.md) for protocol versus adopted policy.

## Execution paths and roles

Finite jobs use finalized `ComputeRequest` → `ComputeAssign` → provider execution
→ `ComputeReceiptV2`. Validators check authorization, deterministic state,
funding and receipts. They do not execute OCI workloads or read remote cgroups.
`bin/greenwood-provider` supervises off-chain workers and containerd execution.
The supervisor builds/signs receipts from bounded worker facts; workers do not
hold its signing key.

Hosted applications instead use Application Registry authority, approved assignment
documents, the deployment controller/receiver and `ProviderApplicationOperator`.
These are distinct from finite-job `ComputeAssign` transactions. Recurring hosted
financial intervals share the native `ServiceSettlement` engine. Containerd
resource-GC leases, capacity reservations and financial commitments are different
identities and must not be interchanged.

## Transaction map

All use the signed [V1 envelope](GREENWOOD_PROTOCOL_V1.md).

| Type | Payload | Purpose |
| --- | --- | --- |
| 7 | `ComputeRequest` | Request identity and bounded execution terms |
| 8 | `ComputeAssign` | Provider and assignment generation |
| 9 | Signed `ComputeReceiptV2` | Terminal execution evidence |
| 10 | `ProviderCapability` V1 | Base capacity advertisement |
| 13 | Canonical native service action | Quote acceptance, escrow, claims, settlement/refund |
| 14 | `ProviderCapabilityV2` | Hardware/resource capability extension |

Types 4–6 (`DeployWorkload`, `UpdateWorkload`, `StopWorkload`) have reserved
constants but are rejected by the current envelope constructor. Earlier generic
workload examples were not the accepted protocol.

## Frozen finite-job encodings

Integers are unsigned big-endian; supported `u64` values fit signed 64-bit PHP.
`bytes(x)` is `u32(length) | x`. Identities/digests below are raw bytes, not hex.
Decoders reject trailing data.

### ComputeRequest (type 7)

```text
u16 protocol_version = 1
requester[32]
u32 request_nonce
workload_digest[32]
bytes(workload_ref)             # retrieval metadata, at most 256 bytes
input_digest[32]
bytes(input_ref)                # retrieval metadata, at most 256 bytes
u8 trust_class                 # OPEN=0, TRUSTED=1, SOVEREIGN=2
u32 cpu_limit_millis            # milli-cores, not elapsed CPU milliseconds
u64 memory_limit_bytes
u32 pid_limit
u64 execution_timeout_ms
u32 output_retention_bytes
u64 max_payment                # GWD base units on economic chains
u64 deadline_height
```

`ComputeRequestId = H(GREENWOOD_COMPUTE_REQUEST_V1, canonical_request_bytes)`.
The outer transaction authenticates the requester; no second request signature
exists. `max_payment` is a ceiling, not escrow. See the
[request codec](../src/Compute/Codec/ComputeRequestCodec.php) and
[request object](../src/Compute/Protocol/ComputeRequest.php).

### ComputeAssign (type 8)

```text
u16 protocol_version = 1
request_id[32]
provider_id[32]
provider_public_key[32]
u32 generation                 # starts at 1
u64 deadline
```

Provider ID is derived from its public key under `GREENWOOD_PROVIDER_V1`.
The [assignment](../src/Compute/Protocol/ComputeAssign.php) binds a generation;
stale-generation receipts cannot finish a replacement assignment. On an economic
chain, matching funding must exist in an earlier finalized block for the request,
provider, generation and deadline.

### ComputeReceiptV2 (type 9)

V2 starts with `u16(2)`, request ID and `u32 generation`, followed by the remaining
frozen V1 body fields:

```text
provider_id[32], execution_id[32]
workload_digest[32], input_digest[32], output_digest[32]
bytes(output_ref)
u8 status
u8 exit_code_present, u32 exit_code if present
u64 cpu_time_ms, u64 memory_peak_bytes, u64 runtime_duration_ms
u8 fuel_used_present, u64 fuel_used if present
u64 output_bytes_observed
u8 stdout_truncated, u8 stderr_truncated
u16 signature_length = 64, signature[64]
```

Provider signing digest: `H(GREENWOOD_RECEIPT_SIGN_V2, unsigned_receipt_bytes)`;
the provider signs this digest with Ed25519.
Receipt ID: `H(GREENWOOD_COMPUTE_RECEIPT_V2, unsigned_receipt_bytes)`.
The enclosing transaction is authenticated separately. The
[codec](../src/Compute/Codec/ComputeReceiptV2Codec.php) and
[verifier](../src/Compute/Verification/ComputeReceiptVerifier.php) define exact
status, optional-field, assignment, signature and bounds checks. Peak RAM is not
RAM-hours; output size is not egress. A signed counter establishes attribution,
not independent proof of consumption.

## Capacity, eligibility and operator onboarding

[ProviderCapability](../src/Compute/Protocol/ProviderCapability.php) binds ID/key,
ACTIVE/DRAINING/SUSPENDED status, increasing nonce, supported architectures,
CPU/memory/PID capacity and maximum concurrent jobs. Capability registration does
not grant TRUSTED/SOVEREIGN eligibility. Those execution classes select providers
without weakening the finite-workload sandbox.

[ProviderCapabilityV2](../src/Resources/ProviderCapabilityV2.php) adds signed assets
and resource profiles without rewriting V1. A
[ResourceManifest](../src/Resources/ResourceManifest.php) fixes quoted assets,
units, reservation/consumption basis, rates, maxima and coverage. An advertisement
is not physical qualification. Unsupported meters remain nonpayable; overlapping
positive-price coverage cannot charge twice for the same resource.

A homelab provider needs approved Linux runtime/artifacts, its own provider identity,
trusted chain/genesis and RPC configuration, protected state, truthful capacity,
and the required network eligibility, program and funding authorizations. Physical
placement is an operator choice subject to isolation and durability requirements.
Joining as a provider does not add a validator vote, treasury signer, independent
witness or subsidy entitlement. Consensus membership remains genesis-fixed.
See [provider operations](provider-operations.md),
[operator onboarding](operations/ROLE_BASED_ONBOARDING.md) and
[Linux requirements](linux-execution-manifest.md).

Execution classes and economic attestation profiles are separate. Fresh production
requires explicit V2 resource programs. An approved Epoch 0 operator-attested
program is customer-funded with zero subsidy and disclosed common control.
Independent programs require qualified sources/domains and finalized eligibility;
separate processes, nodes or keys under one operator do not establish independence.

## Funding and runtime boundaries

Finite assignment requires prior-finalized escrow. Hosted start/recovery checks the
receiver's COMMITTED assignment journal, exact approved document/generation,
registry authority, provider, chain/genesis and active funded interval. Each renewal
needs funding; runtime restarts do not create new billing entitlements. Missing
protected funding configuration fails `HOSTED_SERVICE_SETTLEMENT_NOT_QUALIFIED`.
A deployment flag cannot supply missing funds or establish qualification.

Usage evidence and challenge/dispute rules govern payment after execution.
Settlement is an explicit signed action. Receipt finality and provider journal
`SETTLED` mean execution reconciliation, not GWD payment. Unused escrow returns to
its original source. See [current financial rules](economics/README.md).

Workloads use exact OCI digests, verified input identity, bounded CPU/memory/PID,
timeout and output policies. Finite V1 workloads have a private network namespace
without external connectivity. Hosted networking/storage and governed system-service
mounts use separate reviewed paths, not extra fields in the finite request.
Read [compute security](compute-security-v1.md) before runtime changes.

Expiry/stale-head handling is polling based. Financial ceilings do not prove hard
physical cutoffs. Historical local tests/replays do not certify deployed isolation,
independent metering, distributed operation or production acceptance.

## Verification

Follow [testing prerequisites](testing/overview.md). Codecs/verifier tests live in
`tests/Compute`; funding, hosted and resource tests live in `tests/Economics`.
Native containerd/cgroup acceptance requires the approved Linux profile. Preserve
frozen vectors and replay compatibility when changing implementation.

**Continue reading:** [Documentation hub](README.md) ·
[Provider operations](provider-operations.md) ·
[Resource metering](guides/RESOURCE_METERING.md) · [Economics](economics/README.md)
