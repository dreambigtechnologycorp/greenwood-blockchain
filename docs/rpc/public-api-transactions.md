## Transaction Endpoints

### Submit Transaction

```bash
POST /v1/transactions

Content-Type: application/json

Request:
{
  "type": "transaction",
  "payload": "base64_encoded_envelope"
}

Response: 200 OK
{
  "accepted": true,
  "tx_hash": "tx_hash_hex",
  "status": "PENDING"
}

Response: 422 TRANSACTION_REJECTED
{
  "error": {
    "code": "TRANSACTION_REJECTED",
    "message": "Invalid transaction",
    "details": {}
  }
}
```

### Get Transaction

```bash
GET /v1/transactions/{hash}

Response: 404 NOT_FOUND
       OR
{
  "tx_hash": "tx_hash_hex",
  "status": "FINALIZED",
  "height": 123,
  "block_hash": "block_hash_hex",
  "type": 1,
  "sender": "sender_address",
  "sender_public_key": "sender_public_key_hex",
  "nonce": 5
}
```

### Get Transaction Status

```bash
GET /v1/transactions/{hash}/status

Response:
{
  "tx_hash": "tx_hash_hex",
  "status": "UNKNOWN" | "PENDING" | "FINALIZED"
}
```

The status alternatives above are descriptive, not literal JSON. FINALIZED also
includes height and block_hash. A full PENDING lookup has null height/block_hash;
an unknown full lookup returns TRANSACTION_NOT_FOUND. Index unavailability is a
503 NOT_READY, not proof of absence. Preserve the submitted hash and reconcile an
uncertain result before any retry.

### Get Finalized Contract Receipt

Local B6C implementation; physical availability requires a separately approved versioned release.

`GET /v1/transactions/{hash}/receipt` returns `tx_hash`, `included_height`,
`block_hash`, the responding node's current `finalized_height`, `receipt`, and
`provenance: "derived_finalized_execution"`.

The existing receipt contains `status: "SUCCESS"`, `gas_used`, lowercase hex
`return_data`, `contract_id`, `transaction_hash`, and ordered `events`. Event
fields are `contract_id`, hex `topic`/`data`, `transaction_hash`, and `event_index`.
Gas describes finalized execution, not a later query's fuel consumption.

The lookup requires an index complete and anchored through the current finalized
height, verifies the canonical block/envelope locator, and reads existing derived
execution evidence synchronously. It never replays, repairs, submits, or persists
state. Receipts are not state-root committed or separately certified.

| HTTP | Error code | Meaning |
|---|---|---|
| 400 | INVALID_REQUEST | Malformed hash |
| 404 | TRANSACTION_NOT_FOUND | Complete index miss and not locally pending |
| 409 | TRANSACTION_NOT_FINALIZED | Locally pending |
| 409 | NOT_CONTRACT_TRANSACTION | Finalized transaction is not deploy/call |
| 503 | RECEIPT_INDEX_UNAVAILABLE | Incomplete/unanchored index or unstable observation |
| 503 | RECEIPT_NOT_FOUND | Finalized contract transaction lacks derived evidence |
| 500 | RECEIPT_INCONSISTENT | Canonical locator or receipt integrity mismatch |
| 500 | INTERNAL_ERROR | Unexpected internal failure |

Failed contract execution does not finalize under Physical V1 semantics; absence
must never be interpreted as a finalized failure or inferred successful receipt.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Public API guide](public-api.md) · [Public RPC v1](../rpc-v1.md)
<!-- /docs-nav -->
