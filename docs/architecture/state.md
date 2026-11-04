# State model

Greenwood distinguishes **logical consensus state** from its **derived SQLite
representation**. Validators calculate a logical state root from normalized
in-memory records; SQLite exists for persistence and API queries, not as the
protocol's serialization format.

## Logical state

The `State` object contains accounts; contract code, storage, balances, and
execution receipts; compute requests, assignments, receipts, and provider
capabilities; and genesis-derived provider eligibility. The state root is a
domain-separated Merkle commitment over canonical logical records—not SQLite
pages, timestamps, filesystem paths, runtime logs, or host telemetry.

The exact namespaces and ordering are defined in
[Protocol v1](../GREENWOOD_PROTOCOL_V1.md).

## Transactional execution

Block construction and validation clone state before applying ordered
transactions. A candidate can fail without mutating finalized state. Contracts
add an `ExecutionOverlay` for storage, balance changes, events, and return
data; a trap, fuel exhaustion, or rejected execution discards that overlay.
Compute actions are state transitions, but running a container is not.

## SQLite index

`StateSqlite` persists an inspected snapshot of finalized logical state with
`PRAGMA synchronous=FULL`.

| Table | Content |
|---|---|
| `accounts` | balances and nonces keyed by raw 32-byte address. |
| `chain_state`, `validators` | finalized tip metadata and fixed validator set. |
| `contract_code`, `contract_storage`, `contract_balances` | deterministic contract data. |
| `contract_receipts` | indexed contract execution receipts. |
| `compute_records`, `provider_eligibility` | compute records and eligibility. |

The database may make reads faster but cannot authorize an alternative state
transition. History is replayed and this index rebuilt on startup.

## Operator implications

- Do not edit SQLite to change balances, provider status, contracts, or chain
  metadata. Such changes are not consensus actions and replay overwrites them.
- Do not use a database backup as a substitute for block history.
- A validator's `signing.sqlite` is different: it records safety decisions and
  must be retained. Its loss after initialization causes the daemon to refuse
  to sign.

## Related documents

- [Chain history](chain-history.md)
- [Recovery](recovery.md)
- [Contract storage](../contracts/storage.md)

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Architecture overview](overview.md) · [Core concepts](../concepts/overview.md)
<!-- /docs-nav -->
