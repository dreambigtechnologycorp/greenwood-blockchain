# Consensus

Greenwood uses a fixed-validator BFT protocol with proposal, prevote and precommit
phases. Membership and equal voting power come from trusted genesis. More provider
hosts or assignment receivers do not add validators or independent operators.
The current administrative and physical topology is in
[project status](../PROJECT_STATUS.md).

## Quorum and finality

The certificate quorum is `intdiv(2*n,3)+1`, strictly greater than two thirds of the
validator set. The implementation requires at least three validators. Three
validators need all three for progress; a four-validator local fixture needs three.
Safety depends on the protocol's Byzantine-fault assumptions and preserved signing
state; machine count alone is not evidence of independent control.

1. The deterministic proposer broadcasts a block for a height and round.
2. Peers validate canonical bytes, signatures, state transitions and commitments,
   then prevote subject to their current lock.
3. A matching quorum of prevotes establishes a durable lock before precommit.
4. A matching quorum of precommits finalizes the block and advances to the next
   height. Timeouts can advance rounds without finalizing a block.

The proposer uses validators sorted by genesis ID and index
`(height + round) % validator_count`. Timers and lock/proof handling are implemented
in [ConsensusDriver](../../src/Consensus/Runtime/ConsensusDriver.php); this page is
not a substitute for the full state machine.

## Signed messages and persistence

[Message](../../src/Consensus/Messages/Message.php) binds kind, chain ID, genesis
hash, height, round, validator ID, target, optional block and proof to an Ed25519
signature. The HTTP transport wraps canonical binary bytes as
`{"type":"prevote","payload":"<base64 canonical bytes>"}`; message fields are not
signed as JSON and contain no wall-clock timestamp.

[Certificate](../../src/Consensus/Messages/Certificate.php) verifies distinct
member signatures for the same chain, genesis, phase, height, target and round.
The daemon checks finality certificates while replaying post-genesis history.

The validator's separate [SafetyJournal](../../src/Consensus/Journal/SafetyJournal.php)
persists signed messages and locks. An identical signing request reuses recorded
bytes; a conflicting one fails. Preserve this journal and `validator.initialized`
with the matching identity. It cannot be reconstructed from finalized block files.

## Inspection and restart

```sh
php bin/greenwood chain status --rpc=http://127.0.0.1:9201
php bin/greenwood chain finality --rpc=http://127.0.0.1:9201
php bin/greenwood chain validators --rpc=http://127.0.0.1:9201
```

These examples assume a local listener. Validator `active: true` in RPC describes
genesis membership, not a heartbeat. `syncing: false` is currently a constant and
is not a peer catch-up proof. On restart the daemon validates history, rebuilds
derived state, verifies its safety journal and resumes the next height.
See [consensus architecture](../architecture/consensus-layer.md) and
[recovery](../architecture/recovery.md).

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Core concepts](overview.md) · [Architecture overview](../architecture/overview.md)
<!-- /docs-nav -->
