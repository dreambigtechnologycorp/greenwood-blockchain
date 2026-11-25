# Blocks

A block is a canonical binary header followed by an ordered list of signed
transaction envelopes. Its hash is the domain-separated BLAKE2b-256 hash of the
header, whose transaction root commits to the full ordered transaction list.
See [protocol hashing](hashing.md) and [Protocol v1](../GREENWOOD_PROTOCOL_V1.md).

## Header and execution

[Header](../../src/Block/Header.php) contains version, chain ID, height, round,
parent hash, previous state root, transaction root, resulting state root,
proposer account, genesis hash and timestamp. Version is `u8`; height, round and
timestamp are `u32`. The current chain requires timestamp **zero**, not a Unix
clock reading. There is no single validator-signature field in a block.

[Chain](../../src/Node/Chain.php) selects the expected proposer from validators
sorted by genesis ID, using `(height + round) % validator_count`. Each validator
checks authorization and executes transactions in their encoded order on cloned
state. On an economic genesis it also applies the native end-of-block economic
transition before comparing the resulting state root. Host container execution
and wall-clock telemetry are outside this deterministic path.

The block bounds are 1,000 transactions and 1 MiB serialized bytes. The current
proposer iterates its local mempool and selects transactions that execute and fit
its byte budget; it does not sort by a user-supplied fee. Ordering is committed by
the proposed block and independently validated by peers.

## Finality and timing

Proposal, prevote and precommit messages establish a strict `> 2/3` certificate
quorum: `intdiv(2*n,3)+1` for `n` equal-power genesis validators. Three validators
require all three signatures; a four-validator development fixture requires three.
A finalized certificate is stored separately from the block. API admission of a
transaction is not finality. See [consensus](consensus.md).

The default proposal cadence is 60 seconds in
[ChainConfig](../../src/Node/ChainConfig.php); local fixtures may configure another
interval. This is a proposal gate, not a promise of wall-clock finality. Round
progress, peer availability and execution still determine when finality occurs.

## Persistence and recovery

```text
<data-dir>/blocks/
  0000000000000000-block.bin
  0000000000000001-block.bin
  0000000000000001-cert.bin
```

Genesis is deterministic and has no post-genesis precommit certificate. Later
blocks must link to the trusted genesis and previous tip. Existing block files
are immutable: an identical write is allowed, conflicting bytes fail closed.
SQLite is rebuilt from verified history; it cannot replace missing history or a
validator's separate safety journal. Follow [recovery](../architecture/recovery.md)
instead of deleting databases to diagnose startup failure.

## Querying blocks

Against a deliberately configured local node, from the repository root:

```sh
php bin/greenwood block get 1 --rpc=http://127.0.0.1:9201
php bin/greenwood chain finality --rpc=http://127.0.0.1:9201
```

The block RPC DTO contains `height`, `round`, `block_hash`, `parent_hash`,
`state_root`, `previous_state_root`, `transactions_root`, `proposer`, transaction
hashes and `finality_certificate_present`. It is a query representation, not the
canonical binary encoding. See [RPC responses](../rpc/public-api-endpoints-1.md).
Current deployment evidence is maintained in [project status](../PROJECT_STATUS.md).

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Core concepts](overview.md) · [Architecture overview](../architecture/overview.md)
<!-- /docs-nav -->
