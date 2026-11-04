# Blockchain Layer

Source reference: October 21, 2026. This describes the checkout, not proof that
every current change is deployed on physical nodes.

## Blocks and Execution

[Block](../../src/Block/Block.php) contains a Header and ordered transaction
Envelopes. The header commits to chain/genesis identity, height, round, parent
hash, previous state root, transaction root, resulting state root and proposer.
The current Chain path requires timestamp zero, not wall-clock block time.
Finality evidence is a separate certificate, not a single validator signature
field in a JSON block.

[Chain](../../src/Node/Chain.php) shares execution between proposal validation,
finalization and replay. It checks linkage, commitments, deterministic proposer
and transaction transitions against cloned state before accepting the resulting
root. Current bounds are 1,000 transactions and 1 MiB serialized block size.

## Transactions

[Envelope](../../src/Transaction/Envelope.php) binds version, chain ID, sender
account, public key, nonce, numeric type and canonical binary payload to an
Ed25519 signature. JSON/base64 is a transport wrapper, not the signed format.

| Accepted type | Payload |
| --- | --- |
| 1 | Transfer |
| 2 | DeployContract |
| 3 | CallContract |
| 7 | ComputeRequest |
| 8 | ComputeAssign |
| 9 | ComputeReceipt |
| 10 | ProviderCapability |

Constants for types 4-6 remain in the class but the constructor rejects them.
They do not implement a hosted-application deployment API. Hosted applications
use finalized registry contracts and the separate controller/provider pipeline;
a controller operation is not itself an L1 compute assignment.

See [contracts](../contracts/overview.md) for input, gas and value semantics.
The former illustrative classes with string types and generic fee fields were
not serialization specifications.

## Persistence and Recovery

[BlockFilesystem](../../src/Persistence/BlockFilesystem.php) stores canonical
binary blocks and separate certificates under blocks/, using 16-digit height
filenames. It does not store JSON block files.

SQLite is derived. The actual schema is in
[StateSqlite](../../src/Persistence/StateSqlite.php), not the former illustrative
SQL on this page. Startup replays trusted history and rebuilds derived state.
Validator signing journals are separate, non-rebuildable safety records.

See [chain history](chain-history.md), [state](state.md) and
[recovery](recovery.md) before backup or restoration.

## Inspection

From this repository, the PHP RPC client supports:

```sh
php bin/greenwood chain status
php bin/greenwood block get 1
```

Configure the intended endpoint using the [CLI reference](../cli.md).
[RPC v1](../rpc-v1.md) defines response DTOs, not binary block encoding.
See [current functionality](../CURRENT_FUNCTIONALITY.md) for physical acceptance
boundaries.
