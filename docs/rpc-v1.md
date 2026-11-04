# Greenwood Public RPC v1

Served by the node listener (`ConsensusDriver` + `Transport`). Versioned under
`/v1`; protocol versions (`GREENWOOD_PROTOCOL_V1`, `GREENWOOD_COMPUTE_V1`) are
independent of this API version. Chain and contract queries use **finalized**
state. Transaction lookups also inspect the local mempool, while health and
metrics report local process observations rather than finalized chain facts.

## Conventions

- Identifiers (accounts, providers, tx/block hashes, request/receipt/contract
  IDs): 64-character **lowercase hex**.
- u64 values that may exceed the JS safe-integer range are **decimal strings**:
  `balance`, `memory_limit_bytes`, `max_payment`, `memory_capacity_bytes`,
  `memory_peak_bytes`, `cpu_time_ms`, `duration_ms`, `fuel_used`,
  `output_bytes_observed`, `ephemeral_storage_limit_bytes`.
- Pagination on list endpoints: `?cursor=<hex64>&limit=<1..200>` (default 50).
  Response carries `next_cursor` (null when exhausted).
- Submission endpoints return identity/status immediately; never block on
  finality. Poll the status route.

## Errors

```json
{ "error": { "code": "COMPUTE_REQUEST_NOT_FOUND", "message": "...", "details": {} } }
```

HTTP mapping: 400 INVALID_REQUEST · 401 UNAUTHENTICATED · 403 FORBIDDEN ·
404 NOT_FOUND (+ specific codes: BLOCK_NOT_FOUND, ACCOUNT_NOT_FOUND,
TRANSACTION_NOT_FOUND, COMPUTE_REQUEST_NOT_FOUND, PROVIDER_NOT_FOUND,
RECEIPT_NOT_FOUND) · 409 CONFLICT · 422 TRANSACTION_REJECTED ·
429 RATE_LIMITED · 503 NOT_READY · 500 INTERNAL_ERROR.
Raw exceptions/stack traces are never returned.

## Auth classes

`PUBLIC`: all read routes below, `/v1/contracts/{id}/call`, `/health`, `/ready`, `/metrics`.
`AUTHENTICATED`: `POST /v1/transactions` (classified in `HttpApi::ROUTES`;
open on private test networks).
`OPERATOR_ONLY`: see `docs/operator-api.md` — never on this listener.

## Routes

| Route | Method | Notes |
|---|---|---|
| /v1/chain/status | GET | chain_id, height, finalized_height, block_hash, state_root, validator_count, syncing |
| /v1/chain/finality | GET | height, round, block_hash, certificate_present, precommit_count, validator_count |
| /v1/chain/validators | GET | bare array of validator_id, public_key, voting_power, active; no private keys/journals |
| /v1/chain/state-root | GET | state_root, finalized_height |
| /v1/blocks/{height} | GET | header fields, tx hashes, finality_certificate_present |
| /v1/blocks/hash/{hash} | GET | same, lookup by hash |
| /v1/accounts/{id} | GET | balance (string), nonce, finalized_height |
| /v1/accounts/{id}/balance · /nonce | GET | single field |
| /v1/contracts/{id} | GET | contract metadata: abi_version, wasm_hash, balance (string), finalized_height. 404 CONTRACT_NOT_FOUND if not deployed. |
| /v1/contracts/{id}/call | POST | **Read-only contract execution (eth_call equivalent)**. Body `{"method":"...","calldata":"base64..."}`. Executes the contract's `greenwood_call` export against **finalized state** — no transaction, no signing, no nonce, no state mutation, no consensus. The method + calldata are encoded as the contract's invocation input. Internally metered via fuel; the `fuel_used` field reports computation cost but no blockchain fee is charged. |
| /v1/transactions | POST | body = signed envelope `{type:"transaction",payload:base64}`; 200 `{accepted, tx_hash, status:"PENDING"}`; duplicates idempotent; 422 on rejection |
| /v1/transactions/{hash} | GET | 404 unknown; PENDING (in mempool) or FINALIZED with height/block_hash/type/sender/nonce |
| /v1/transactions/{hash}/status | GET | UNKNOWN / PENDING / FINALIZED (no durable REJECTED index exists) |
| /v1/compute/requests/{id} | GET | request fields, trust_class, lifecycle (PENDING/ASSIGNED/TERMINAL), current_generation, current_provider, assignment_deadline, terminal_receipt |
| /v1/compute/requests/{id}/history | GET | request_id, lifecycle, ordered assignments: generation, provider_id, height, status (ASSIGNED/REASSIGNED/STALE/SETTLED); reconstructed from finalized blocks |
| /v1/compute/requests/{id}/receipt | GET | canonical receipt DTO (404 until terminal) |
| /v1/compute/receipts/{receipt_id} | GET | same DTO by receipt ID |
| /v1/compute/providers | GET | paginated; status, trust_classes, architectures, flattened capacity fields, reserved, available, capability_nonce |
| /v1/compute/providers/{id} | GET | single provider DTO |
| /v1/compute/providers/{id}/reservations | GET | provider_id, aggregate reserved counters, finalized_height; no per-job array or host telemetry |

Receipt DTO: receipt_id, request_id, generation, provider_id, execution_id,
status (COMPLETED/FAILED/TIMED_OUT/TERMINATED/RUNTIME_ERROR), exit_code,
fuel_used, cpu_time_ms, memory_peak_bytes, duration_ms, output_digest,
output_bytes_observed.

## Contract query endpoint

`POST /v1/contracts/{64-hex-id}/call` runs the deployed contract's
`greenwood_call` export in a **read-only** fashion against finalized state.

### Request body

```json
{
  "method": "balanceOf",
  "calldata": "base64-encoded-bytes"
}
```

The node constructs the contract input as `bytes(method) . bytes(calldata)`,
where each `bytes` field has a four-byte big-endian length prefix followed by
raw bytes (not bare concatenation),
matching the same encoding that `CallContract::invocationInput()` uses for
signed transactions. `calldata` is raw bytes, base64-encoded in JSON.

### Successful response

```json
{
  "contract_id": "<64-hex>",
  "method": "balanceOf",
  "status": "SUCCESS",
  "return_data": "<hex-encoded-return-bytes>",
  "fuel_used": 44,
  "finalized_height": 607
}
```

`return_data` is the value the contract wrote via `return_value` during
execution. `fuel_used` is the Wasmtime fuel consumed — used for internal
node protection, not a blockchain transaction fee.

### Error responses

| Error code | HTTP | Meaning |
|---|---|---|
| `CONTRACT_NOT_FOUND` | 404 | Contract ID not deployed |
| `INVALID_REQUEST` | 400 | Malformed JSON, missing fields, or invalid base64 |
| `CONTRACT_EXECUTION_ERROR` | 422 | WASM validation or runtime failure |

When execution itself succeeds but the contract signals failure, the response
carries the runtime status but still returns 200:

```json
{
  "contract_id": "<64-hex>",
  "method": "balanceOf",
  "status": "EXECUTION_ERROR",
  "error": "Trap: wasm trap: out of bounds memory access",
  "fuel_used": 123,
  "finalized_height": 607
}
```

Status values: `SUCCESS`, `TRAP`, `OUT_OF_GAS`, `MISSING_EXPORT`,
`EXECUTION_ERROR`.

### What this endpoint is NOT

- **Not a transaction** — no signature, no nonce, no mempool, no block
- **Not state-mutating** — all storage/balance changes are discarded after
  execution (the `ExecutionOverlay` is unconditionally discarded)
- **Not free of node cost** — fuel is metered internally to protect the node,
  but no account is charged and no blockchain fee exists

### Architecture separation

```
READ PATH:
GET /v1/chain/*, /v1/accounts/*, /v1/blocks/*
POST /v1/contracts/{id}/call
  → finalized state → no signing → free

WRITE PATH:
POST /v1/transactions
  → signed envelope → mempool → consensus → block → finality → state mutation
```

## Health, readiness, metrics

- `GET /health` (+ `/v1/health`): liveness. 200 means the process responds.
- `GET /ready` (+ `/v1/ready`): role readiness. 200 `{ready, role, checks}` or
  503 NOT_READY with failing checks.
- `GET /metrics` (+ `/v1/metrics`): Prometheus text
  (`text/plain; version=0.0.4`): greenwood_finalized_height,
  greenwood_validator_count, greenwood_pending_transactions,
  greenwood_consensus_round, greenwood_compute_requests_total,
  greenwood_compute_assignments_total, greenwood_compute_receipts_total,
  greenwood_compute_reassignments_total, greenwood_providers_registered.
  No unbounded labels (no IDs).

## Compatibility

Internal routes `/health /status /sync/* /consensus/* /transactions` are
unchanged. Transaction lookup requires a complete finalized transaction index,
then validates its locator against the canonical block and envelope. An incomplete
index fails closed with NOT_READY; it is not an UNKNOWN transaction. Block history
remains authoritative and the index remains rebuildable.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](README.md) · [Public API guide](rpc/public-api.md) · [Transaction endpoints](rpc/public-api-transactions.md) · [Compute endpoints](rpc/public-api-compute.md)
<!-- /docs-nav -->
