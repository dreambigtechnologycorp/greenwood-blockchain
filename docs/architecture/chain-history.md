# Chain history

Finalized block files are Greenwood's local authoritative history. SQLite is
reconstructed from this history on node startup; deleting or changing an index
must never create new chain state.

## Layout and invariants

`BlockFilesystem` stores binary, canonical records in the node data directory:

```text
<data-dir>/
  blocks/
    0000000000000000-block.bin   # deterministic genesis block
    0000000000000001-block.bin
    0000000000000001-cert.bin    # precommit certificate, when applicable
    …
  state.sqlite                   # derived index
  signing.sqlite                 # validator only; not rebuildable
```

Heights use a zero-padded, 16-digit decimal filename. On startup the node
requires that heights start at zero and contain no gap, then replays them in
order. An existing block file can be written only when its bytes are identical;
a conflicting block at an existing height is a hard error.

## Replay verification

For every post-genesis block, `Chain::execute()` checks chain and genesis
identity, contiguous height and parent hash, prior state root, ordered
transaction Merkle root, the expected deterministic proposer, every transaction
state transition, and the resulting state root. Certificate verification, when
configured, checks persisted precommit evidence against the fixed genesis
validator set. The node never trusts its index to skip these checks.

## Commit and recovery behavior

Finalization verifies candidate state before persistence. The certificate is
written first, the immutable block file is the history commit point, and the
SQLite index is then refreshed from already verified state. An orphaned
certificate is harmless; missing, gapped, or conflicting history is not.

The implementation rebuilds SQLite whenever a `Chain` is constructed. A stale,
missing, or corrupted derived database therefore cannot override history.

## Backup and restore

Back up trusted genesis and the complete data directory while the node is
stopped or using an operator-approved filesystem snapshot. Preserve the
validator safety journal separately from ordinary chain data.

To restore, place trusted genesis and contiguous history in a fresh data
directory and run `greenwoodd … --replay` before starting consensus. Never
combine block files from different genesis files, copy a safety journal between
validator identities, or fill a missing height with a guessed block.

## Related documents

- [Recovery](recovery.md)
- [State model](state.md)
- [Protocol persistence rules](../GREENWOOD_PROTOCOL_V1.md)

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Architecture overview](overview.md) · [Core concepts](../concepts/overview.md)
<!-- /docs-nav -->
