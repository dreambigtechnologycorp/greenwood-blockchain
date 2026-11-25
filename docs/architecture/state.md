# State model

Greenwood distinguishes **logical consensus state** from its **derived SQLite
representation**. Validators calculate a logical state root from normalized
in-memory records; SQLite exists for persistence and API queries, not as the
protocol's serialization format.

## Logical state

The `State` object contains accounts; contract code, storage, balances, and
execution receipts; compute requests, assignments, receipts, and provider
capabilities; genesis-derived provider eligibility; and, when enabled by genesis,
the native economics ledger, resource policy and service commitments. Contract
execution receipts are derived evidence, not state-root records. The state root is a
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

`StateSqlite` persists an inspected snapshot of finalized logical state. In the
current source, [SqliteDurability](../../src/Persistence/SqliteDurability.php)
requires a disk-backed database, `journal_mode=DELETE` and `synchronous=EXTRA`. An
existing WAL database fails startup with `SQLITE_OFFLINE_ROLLBACK_MIGRATION_REQUIRED`;
startup does not silently checkpoint or discard WAL files. Use the reviewed,
stopped-service migration in [state storage](../../deploy/ansible/STATE_STORAGE.md).
Do not infer that older immutable releases contain this change or that storage
continuation has completed; see [project status](../PROJECT_STATUS.md).

| Table | Content |
|---|---|
| `accounts` | balances and nonces keyed by raw 32-byte address. |
| `chain_state`, `validators` | finalized tip metadata and fixed validator set. |
| `contract_code`, `contract_storage`, `contract_balances` | deterministic contract data. |
| `contract_receipts` | indexed contract execution receipts. |
| `compute_records`, `provider_eligibility` | compute records and eligibility. |
| `economics_state` | optional native economic snapshot. |
| `transactions`, `transaction_index_progress` | canonical transaction locators and index completeness anchor. |

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
