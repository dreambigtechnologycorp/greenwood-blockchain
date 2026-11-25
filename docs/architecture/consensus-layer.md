# Consensus layer

[ConsensusDriver](../../src/Consensus/Runtime/ConsensusDriver.php) implements the
proposal/prevote/precommit state machine around the shared
[Chain](../../src/Node/Chain.php) executor. Consensus is restricted to the trusted,
genesis-fixed equal-power validator set. Current physical acceptance and operator
topology are recorded in [project status](../PROJECT_STATUS.md).

## State machine

At each height, the driver tracks round, proposals, known blocks, authenticated
votes, locks and valid quorum proofs. The proposer is selected from validators
sorted by ID using `(height + round) % validator_count`. Each candidate is executed
against cloned state before votes can authorize finality.

A matching strict quorum of prevotes supplies the proof for a durable lock. The
lock is persisted before the precommit signature is emitted. A matching strict
quorum of precommits finalizes a known block. Nil votes and timeouts permit round
progress; they do not create a finalized block. A higher-round proof can permit
voting beyond an earlier lock under the implemented validation rules.

[Certificate::quorum()](../../src/Consensus/Messages/Certificate.php) computes
`intdiv(2*n,3)+1`. This is 3-of-3 for three validators and 3-of-4 for the local
four-validator fixture. Liveness requires the quorum and protocol timing
assumptions; safety also depends on the assumed fault bound and durable signing
state. A count of administered machines does not establish independent validator control.

## Timers and cadence

The proposal gate uses `ChainConfig::blockTimeSeconds()` (default 60 seconds).
Round phase timers are separate: the current base unit is
`min(10, 1 + round * 0.5)` seconds. The driver also has authenticated proposer
catch-up and phase-hold logic; these do not reset the height-level proposal gate.
A configured interval is not a finality-latency guarantee. Consult the driver and
its [consensus tests](../../tests/Consensus/) before changing timing behavior.

## Canonical messages and transport

The actual signed type is [Message](../../src/Consensus/Messages/Message.php),
not separate illustrative Proposal/Prevote PHP classes. It binds kind, chain,
genesis, height, round, validator, target, block and proof. Signature digests use
phase-specific domains. Nil targets are represented by empty bytes; a block target
is 32 bytes. [Certificate](../../src/Consensus/Messages/Certificate.php) canonically
orders votes and verifies distinct identities and matching vote context.

[Transport](../../src/Consensus/Network/Transport.php) sends HTTP envelopes with
`type` and a base64 `payload` of canonical bytes. Internal POST routes are
`/consensus/proposal`, `/consensus/prevote`, `/consensus/precommit`,
`/consensus/finality` and `/transactions`; sequential finality synchronization uses
`GET /sync/{height}`. Finality payloads contain the block and its certificate.
These internal transport routes are separate from the public `/v1` DTO contract.

## Signing and recovery

[SafetyJournal](../../src/Consensus/Journal/SafetyJournal.php) exposes `sign()`,
`messages()`, `lock()` and `getLock()`. Its identity binds genesis, validator ID
and public key. Startup verifies recorded message signatures. A repeated identical
signing request returns its persisted message; conflicting bytes for the same
height, round and phase are rejected. Preserve `signing.sqlite` and
`validator.initialized`; loss of initialized safety state must not be repaired by
creating a blank journal.

The current source uses the shared SQLite rollback-journal durability policy;
see [state storage](state.md) for the offline migration boundary. That source
behavior is not evidence of completed physical storage qualification.

Post-genesis history replay verifies certificates and runs the same deterministic
executor used for live blocks. It rebuilds the derived state index and resumes the
next height. See [chain history](chain-history.md) and [recovery](recovery.md).

## Inspection

Public reads: `GET /v1/chain/status`, `/v1/chain/finality` and
`/v1/chain/validators`. Authenticated local operator reads additionally expose
validator status and consensus state. See [RPC v1](../rpc-v1.md) and
[operator API](../operator-api.md). Health, membership and certificate-presence
fields each have narrower meanings than full physical acceptance.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Architecture overview](overview.md) · [Core concepts](../concepts/overview.md)
<!-- /docs-nav -->
