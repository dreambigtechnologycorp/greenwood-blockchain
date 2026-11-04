# Public API guide

The public API is versioned under `/v1` and is a read/submit adapter over a
node's finalized chain state. It does not expose the node's SQLite files,
validator signing journal, or provider host telemetry.

The normative route index is [Public RPC v1](../rpc-v1.md). This guide explains
how to use it safely.

## API contract

- Identifiers are 64-character lowercase hexadecimal strings.
- Values that may exceed JavaScript's safe integer range are decimal strings.
- Chain/account/contract reads use finalized state. Transaction reads can report
  local mempool status; health and metrics describe the responding process.
- `POST /v1/transactions` acknowledges admission, not finality. Poll the
  transaction status route until it is `FINALIZED`.
- Unknown resources return a structured `404`; malformed resource identifiers
  return `400` rather than being treated as a different missing resource.
- List endpoints accept only `cursor` and `limit`; `limit` is 1 through 200.

The common error body is:

```json
{"error":{"code":"TRANSACTION_REJECTED","message":"…","details":{}}}
```

## Basic client flow

1. Read `GET /v1/chain/status` and verify its `chain_id` against the trusted
   network configuration before using it as transaction-signing context.
2. Read an account nonce from `GET /v1/accounts/{id}/nonce`.
3. Create and sign a canonical envelope locally.
4. Submit `{ "type": "transaction", "payload": "<base64 envelope>" }` to
   `POST /v1/transactions`.
5. Poll `GET /v1/transactions/{hash}/status`. Treat `PENDING` and `UNKNOWN` as
   non-final; only `FINALIZED` is canonical inclusion.

The `bin/greenwood` CLI handles queries and submission without reading local node
state. Signing uses the separate local helper or Python developer CLI; see
[CLI](../cli.md). An uncertain submission must be reconciled by hash, account and
finalized state, not blindly retried.

## Liveness versus readiness

`GET /v1/health` answers whether the process responds. `GET /v1/ready` answers
whether the node is ready for its role and returns `503 NOT_READY` when required
checks fail. Monitoring should record both. Prometheus text is available from
`GET /v1/metrics` without unbounded identifier labels.

## Authentication boundary

Read endpoints are public. Transaction submission is classified as
`AUTHENTICATED` in the route registry but is open on private test networks in
the current node wiring; deployers must put appropriate network policy in front
of a public endpoint. Operator routes are a separate API on a private Unix
socket and are never served from this listener.

## Detailed references

- [Chain, block, and account responses](public-api-endpoints-1.md)
- [Transaction submission and status](public-api-transactions.md)
- [Compute query responses](public-api-compute.md)
- [Operator API](../operator-api.md)

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Public API guide](public-api.md) · [Public RPC v1](../rpc-v1.md)
<!-- /docs-nav -->
