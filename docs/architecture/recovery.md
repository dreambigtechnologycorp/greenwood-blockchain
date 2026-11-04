# Recovery and restart

Greenwood recovers by replaying trusted finalized history. It does not use
snapshots or an index as a consensus shortcut. This favors a small, auditable
v1 recovery path over a faster but more complex synchronization system.

## Normal startup

When `greenwoodd` starts, it:

1. Loads and validates the genesis-fixed equal-power validator set; certificate
   quorum is derived from its size, not assumed to be four.
2. Opens the block directory and creates deterministic genesis history if it is
   empty.
3. Rejects stored genesis bytes that differ from the trusted genesis-derived
   block.
4. Rejects gaps and replays every finalized block through live validation
   rules.
5. Rebuilds `state.sqlite` from the replay result.
6. On a validator, opens and verifies the separate safety journal before it
   can sign another consensus message.

`--replay` performs this verification path and prints recovered height, tip
hash, and state root instead of starting the consensus driver.

## Failure behavior

| Condition | Expected behavior |
|---|---|
| Missing or stale `state.sqlite` | Rebuild from genesis and blocks. |
| Gap in block heights | Fail closed; history is not contiguous. |
| Block/header/root mismatch | Fail closed; no repair is guessed. |
| Missing certificate when verification is enabled | Fail closed. |
| Missing `signing.sqlite` after validator initialization | Fail closed to avoid equivocation risk. |
| Journal identity or signature corruption | Fail closed. |

Do not delete files preemptively when diagnosing a failed startup. Preserve the
data directory, copy it for investigation, and use `--replay` against a copy
once genesis provenance is known.

## Restoring a node

1. Obtain the exact trusted genesis and contiguous finalized block history.
2. Restore them into a fresh data directory with restrictive permissions.
3. For a validator, restore only its matching key and its own safety journal.
   Never copy another validator's journal.
4. Run `greenwoodd … --replay` and compare the resulting height, tip hash, and
   state root with a trusted peer or operator record.
5. Start consensus only after that check succeeds.

## What v1 deliberately does not do

There is no snapshot fast-sync and no automatic repair of corrupt history. New
nodes synchronize by verified sequential replay. Recovery time grows with
history, but every reconstructed root has clear provenance and follows the
same deterministic execution path.

## Related documents

- [Chain history](chain-history.md)
- [Consensus safety](consensus-layer.md)
- [Protocol recovery rules](../GREENWOOD_PROTOCOL_V1.md)

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Architecture overview](overview.md) · [Core concepts](../concepts/overview.md)
<!-- /docs-nav -->
