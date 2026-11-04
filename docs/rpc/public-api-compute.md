# Compute Query Responses

Checked against src/Rpc/HttpApi.php on October 21, 2026. These are finalized L1
compute views, not the hosted-application controller's reservation or status API.

## Requests and History

GET /v1/compute/requests/{id} returns request_id, requester, trust_class,
workload_digest, workload_ref, input_digest, cpu_limit_millis,
memory_limit_bytes, pid_limit, execution_timeout_ms, max_payment, deadline_height,
lifecycle, current_generation, current_provider, assignment_deadline,
terminal_receipt and finalized_height.

Assignments can be absent, making generation/provider/deadline null.
terminal_receipt is a receipt ID (hex) or null, not an embedded receipt object.
There is no separate state field in this DTO. Memory, timeout and payment are
decimal strings; identifiers/digests are lowercase hex.

GET /v1/compute/requests/{id}/history returns request_id, lifecycle and
**assignments** (not generations). Each assignment has generation, provider_id,
height and status: ASSIGNED, SETTLED, REASSIGNED or STALE.

## Receipts

GET /v1/compute/requests/{id}/receipt and GET /v1/compute/receipts/{receipt_id}
return receipt_id, request_id, generation, provider_id, execution_id, status,
exit_code, fuel_used, cpu_time_ms, memory_peak_bytes, duration_ms, output_digest
and output_bytes_observed.

Status is COMPLETED, FAILED, TIMED_OUT, TERMINATED or RUNTIME_ERROR.
Fuel is nullable; non-null fuel and resource/duration/output counts are decimal
strings, not JSON floating-point values. Before a terminal receipt exists, the
request receipt route returns RECEIPT_NOT_FOUND. An unknown request instead returns
COMPUTE_REQUEST_NOT_FOUND.

## Providers

GET /v1/compute/providers accepts cursor/limit and returns providers plus
next_cursor. GET /v1/compute/providers/{id} returns one provider DTO:

- provider_id, status (ACTIVE, DRAINING or SUSPENDED), capability_nonce.
- trust_classes: OPEN and, when eligible, TRUSTED and/or SOVEREIGN; not LOW/MEDIUM/HIGH.
- architectures, cpu_capacity_millis, memory_capacity_bytes, pid_capacity,
  max_concurrent_jobs.
- reserved and available objects containing cpu_millis, memory_bytes, pids, jobs.

Memory values are decimal strings. Availability is finalized protocol accounting,
not a probe of free host memory or container health.

GET /v1/compute/providers/{id}/reservations returns provider_id, aggregate reserved
(cpu_millis, memory_bytes, pids, jobs), and finalized_height. It is not a per-job
reservations array; use request history for assignment records.

[Public RPC v1](../rpc-v1.md) | [Provider operations](../provider-operations.md)
