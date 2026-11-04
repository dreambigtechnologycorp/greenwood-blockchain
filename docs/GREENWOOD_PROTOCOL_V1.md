# GREENWOOD_PROTOCOL_V1

> Implementation reconciliation, October 20, 2026: the original specification
> below describes a four-validator profile. Current `Certificate::quorum()` and
> `ConsensusDriver` derive quorum/proposer selection from the genesis set size
> (minimum three for certificate verification), and the physical profile uses
> three validators. Do not interpret `validator_count=4`, `mod 4` or fixed vote
> counts below as a current source-enforced universal limit. This annotation
> records the divergence; it is not a new protocol-version or compatibility
> approval. Local four-validator vectors retain their original meaning.

This is the repaired PHP reference protocol for Passes 1, 2 and 3C. The old prototype
encodings are incompatible and are preserved under `archive/php-prototype` only.
Never mix prototype data with this format; incompatible genesis/history fails
closed. Protocol changes require new vectors and compatibility review.

## Primitives and limits

`u8`, `u16`, `u32`, `u64` are unsigned, big-endian integers. Values in u64 are
restricted to 0..2^63-1. Floating-point arithmetic is prohibited in consensus
identity, balances and voting power. `bytes(x) = u32(len(x)) | x`. `short(x) =
u16(len(x)) | x`. `label(x) = u8(len(x)) | x`. No implicit padding or terminators.
String ordering is unsigned bytewise lexical order. All decoders reject
truncation, overflow, oversized fields and trailing bytes.

Chain IDs are nonempty and at most 128 bytes. Validator IDs match
`[A-Za-z][A-Za-z0-9_-]{0,31}`. Four unique public keys, equal power 1, are required.
Maximum transaction size: 600,000 bytes. Maximum block: 1,048,576 bytes and 1000
transactions. Maximum consensus message: 1,100,000 bytes. Maximum quorum proof:
4096 bytes. Maximum HTTP body: 1,500,000 bytes; headers: 16,384 bytes; concurrent
connections: 128; connection deadline: 2 seconds. These transport limits do not
authorize any protocol message. Pending transactions are capped at 1000.

## Cryptography

`H(domain, payload) = BLAKE2b-256(short(ASCII(domain)) | payload)`.
Ed25519 public keys are 32 bytes, signatures 64 bytes. Display uses lowercase hex;
protocol fields use raw bytes. Domains:

```
GREENWOOD_ACCOUNT_V1       GREENWOOD_GENESIS_V1
GREENWOOD_TX_SIGN_V1       GREENWOOD_TX_V1
GREENWOOD_BLOCK_V1         GREENWOOD_CONTRACT_V1
GREENWOOD_PROPOSAL_V1      GREENWOOD_PREVOTE_V1
GREENWOOD_PRECOMMIT_V1
GREENWOOD_TX_LEAF_V1       GREENWOOD_TX_NODE_V1       GREENWOOD_TX_EMPTY_V1
GREENWOOD_STATE_LEAF_V1    GREENWOOD_STATE_NODE_V1    GREENWOOD_STATE_EMPTY_V1
```

Account address is `H(GREENWOOD_ACCOUNT_V1, public_key)`.
ContractId is `H(GREENWOOD_CONTRACT_V1, complete_wasm_bytes)`. This identity is
frozen; contract transaction execution is specified in the 3C section below.

## Transactions

Common signed transaction envelope, in exact order:

```
u8 version=1
short(chain_id)
sender_address[32]
sender_public_key[32]
u32 nonce
u8 type                            # 1=Transfer, 2=DeployContract, 3=CallContract
bytes(payload)                     # Transfer payload is exactly 36 bytes
u16 signature_length=64
signature[64]
```

Payload is `recipient_address[32] | u32 amount`. Unsigned bytes end after payload
(no empty signature prefix). Signature is Ed25519 over
`H(GREENWOOD_TX_SIGN_V1, unsigned_bytes)`. Transaction hash is
`H(GREENWOOD_TX_V1, complete_signed_envelope)`.

Verify expected chain ID, public-key-to-address binding, signature, exact current
nonce, positive amount, sufficient funds, recipient balance overflow, and nonce
exhaustion before any mutation. Successful execution subtracts amount from
sender, adds to recipient, and increments sender nonce. Self-transfer preserves
balance and increments nonce. A missing recipient is created with nonce zero.
Unknown/unsupported types reject; types 2 and 3 are specified below; 4..6 remain reserved.

## Merkle commitments

Transaction leaves are `H(GREENWOOD_TX_LEAF_V1, transaction_hash)` in block order.
Each parent is `H(GREENWOOD_TX_NODE_V1, left | right)`. Duplicate the final node
at every odd-width level. A single leaf is the root. Empty root is
`H(GREENWOOD_TX_EMPTY_V1, empty)`.

State uses the same tree algorithm with GREENWOOD_STATE_* domains. Leaf preimage
is `bytes(namespace) | bytes(key) | bytes(value)`. Sort records by namespace,
then key, both bytewise. Active records:

| Namespace (ASCII) | Key | Value |
| --- | --- | --- |
| accounts | address[32] | u64 balance, u32 nonce |
| validators | validator ID | public_key[32], u32 power=1 |

Zero-balance accounts remain once created. The complete fixed validator set is
included. Local journal/lock state, height metadata, endpoints, logs, SQLite
internals, clocks and paths are excluded. Genesis, speculative execution,
SQLite inspection and sequential replay use this same commitment. Future
provider/workload record schemas must be frozen before their passes. Contract
record schemas are frozen in the 3C section below.

## Genesis

Canonical genesis configuration:

```
u32 protocol_version=1
u32 wasm_abi_version=1
u32 compute_version=1
short(chain_id)
u32 validator_count=4
repeated validators sorted by ID:
    label(id), public_key[32], u32 power=1
u32 initial_account_count
repeated accounts sorted by genesis account label:
    label(account_label), address[32], u64 initial_balance
```

Account labels are 1..32 bytes. Duplicate account public keys reject in runtime
configuration. Maximum initial account count is 10,000. Nonces start at zero.
Genesis hash is `H(GREENWOOD_GENESIS_V1, canonical_genesis_configuration)`.
Endpoints are configured separately and do not change the genesis hash.

The genesis block has height and round zero, zero parent and previous-state
hashes, an empty transaction tree, the complete initial logical state root,
the first sorted validator's account address as proposer, the configuration
genesis hash, and timestamp zero. The genesis block hash and configuration hash
are distinct identities.

## Blocks and independent execution

Header, in exact order:

```
u8 version=1
short(chain_id)
u32 height
u32 round
parent_hash[32]
previous_state_root[32]
transactions_root[32]
state_root[32]
proposer_address[32]
genesis_hash[32]
u32 timestamp=0
```

Block bytes are header, u32 transaction count, then self-delimiting signed
transaction envelopes. Block hash is `H(GREENWOOD_BLOCK_V1, header_bytes)`.
`round` and `proposer_address` describe the block's original construction. A
later consensus round can repropose the exact same block only with a prior
prevote quorum proof; its block identity must not change.

Every validator and syncing node independently checks chain/genesis, next
height, parent hash, previous state root, original deterministic proposer,
transaction tree, transaction authorization/state transitions and resulting
state root. Execution uses a deep speculative copy. Invalid blocks do not mutate
finalized state. Timestamp is fixed at zero and has no application semantics.

## Consensus messages and transport

Canonical unsigned Proposal, Prevote and Precommit use one format:

```
u8 kind                          # proposal=1, prevote=2, precommit=3
short(chain_id)
genesis_hash[32]
u32 height
u32 round
label(validator_id)
bytes(target)                    # empty=nil, or exactly 32-byte block hash
bytes(block)                     # full block only for Proposal
bytes(proof)                     # prior prevote certificate only for Proposal
```

Signature is Ed25519 over `H(kind_domain, unsigned_message)` using the respective
PROPOSAL/PREVOTE/PRECOMMIT domain. Signed bytes append signature[64] without a
length prefix. Proposal requires non-nil target and block. Vote block/proof
fields must be empty. Every ID resolves exclusively through trusted genesis.
A valid signature never excuses wrong chain, genesis, position or proposer.

Quorum certificate: `u32 vote_count | repeated bytes(signed_vote)` sorted by
validator ID. Count must be 3 or 4. All signatures must be valid, signers unique,
and chain/genesis/height/round/phase/non-nil target identical. Claimed voting
power is never transmitted or trusted. A FinalityCertificate contains Precommits;
a proposal's prior-round proof contains Prevotes. Certificate round cannot
precede the block's original construction round.

HTTP transport uses exactly one JSON envelope:

```
{"type":"proposal|prevote|precommit|finality|transaction","payload":"BASE64"}
```

BASE64 is canonical padded standard base64 of canonical binary bytes. Extra
fields, invalid base64 and nested JSON payloads reject. Type must match route
and signed message kind. Finality binary payload is `bytes(block) |
bytes(finality_certificate)` so receivers and sync can independently execute.
HTTP framing runs over persistent TCP connections between peers, with
nonblocking I/O and periodic rebroadcast; cryptographic signatures authorize
consensus. Requests are serialized per peer connection with bounded queues; external clients
may request connection close. Production transport encryption can terminate at a
configured proxy.

## Rounds, locks and signing recovery

Sort genesis validator IDs bytewise. Proposer is
`validators[(height + round) mod 4]`. Finality requires strict >2/3, exactly
three unique votes with this fixed set.

1. Proposer builds a block or reproposes its highest valid block with its
   prior-round prevote proof. New blocks have the current round/proposer.
2. Validators independently execute. Prevote the target when unlocked, locked
   to the same target, or presented with a valid earlier-round proof strictly
   newer than the local lock. Otherwise prevote nil. A proof must predate the
   proposal round and cannot predate the block construction round.
3. Three same-round prevotes for a known valid non-nil block establish a valid
   block/proof. Persist its height-scoped lock before signing a precommit.
4. Three unique precommits for a known block produce a FinalityCertificate and
   trigger the same independent execution/persistence path on every node.
5. Missing proposals/vote quorums cause nil votes and timed round advancement.
   Locks survive round changes and restarts. Round advancement never unlocks.
   Two distinct authenticated next-round validators justify catching up by one
   round, but do not authorize any vote by themselves.

Local phase timing unit is `min(10, 1 + round/2)` seconds: proposal timeout at
one unit, nil precommit at two, next round at three. Proposers wait at least
250ms after entering a height/round. Timing affects only scheduling, never
signed timestamps or state. Messages rebroadcast every 350ms; sequential sync
polls every 500ms. Runtime retains a bounded window of consensus rounds, plus
separate durable lock/valid proof. Old messages may reject; valid finality can
always arrive through verified sequential sync.

Signing position is `(genesis, validator, height, round, phase)`. Before a signed
message can escape, store unsigned and signed bytes durably in a separate SQLite
journal with synchronous FULL and a unique position constraint inside
BEGIN IMMEDIATE/COMMIT. Same bytes return the prior signature; different bytes
at the position reject. Never prune safety records. On startup verify journal
identity and persisted signatures, restore locks/proofs and current-height
signed messages, and resume the highest signed round. Missing initialized
validator journals fail closed. The derived state rebuild never deletes journals.
Duplicate network votes are idempotent; conflicting votes reject and neither can
increase a signer's power beyond one.

## Persistence, sync and crash recovery

One daemon holds a data-directory file lock. For a finalized block:

1. Verify certificate and independently execute speculatively.
2. Write certificate temp, fsync, rename, fsync directory.
3. Write block temp, fsync, rename, fsync directory (history commit point).
4. Replace accounts, validators, contracts, derived receipts and tip metadata in SQLite with
   BEGIN IMMEDIATE/COMMIT and synchronous FULL.
5. Publish finalized state; gossip finality afterward.

Conflicting authoritative block files reject. An orphan certificate/temp file is
ignored. A committed block without a valid certificate fails closed. Startup
replays every block from trusted genesis before rebuilding the index, so a crash
between steps 3 and 4 cannot lose finalized history. Missing/corrupt authoritative
files or gaps fail closed. A corrupt derived index is quarantined and rebuilt
only after verified history replay; unrelated I/O errors fail closed.

A fresh non-validator needs trusted genesis and configured endpoints, no validator
key. It requests exactly its next finalized height, validates certificate and
executes, commits locally, then requests the next. No snapshots or fast sync.

## Golden vectors and acceptance

Machine-readable golden vectors: `tests/Fixtures/protocol-v1.json`. Includes
seed recipe, public keys/address, genesis bytes/hash, signed transaction bytes/hash,
block bytes/hash, initial/final state roots, Proposal/Prevote/Precommit digests and
signed bytes, FinalityCertificate, and ContractId for an empty WASM module.
`tests/Fixtures/verify_vectors.py` independently verifies hashes and both logical
state roots using Python's standard library. Vectors are static, not regenerated
by tests.

Run `php composer.phar test` and `python3 tests/Fixtures/verify_vectors.py`.
Environment-specific acceptance scripts and their output are local-only under
ignored `docs/acceptance/`, separate from maintained regression tests.
Multi-node contract acceptance, compute and full client APIs remain later gates.

## Contract transactions and logical state (Pass 3C)

Only transaction types **1 Transfer, 2 DeployContract and 3 CallContract** are
active. The common authenticated envelope, signing digest, transaction hash,
transaction commitment and block header are unchanged. Contract payloads use the
same `bytes(x) = u32(len(x)) | x` encoding and unsigned big-endian integers.
Decoders consume their entire payload and reject trailing or truncated bytes.

DeployContract (type 2), exact order:

```
bytes(wasm_bytes)
bytes(init_calldata)
u64 attached_value
u64 gas_limit
```

CallContract (type 3), exact order:

```
contract_id[32]
bytes(method)
bytes(calldata)
u64 attached_value
u64 gas_limit
```

Method is case-sensitive ASCII matching `[A-Za-z_][A-Za-z0-9_]{0,63}` (1..64
bytes). No NUL, UTF-8 extension, punctuation or normalization. WASM is 1..524,288
bytes; native validation must also succeed. Init calldata is 0..65,536 bytes.
Call calldata is 0..65,464 bytes, leaving room for a maximum-length method and
two u32 prefixes within the accepted 65,536-byte host input limit. Attached value
is 0..2^63-1. Gas limit is 0..1,000,000,000, including zero. Monetary values above
the signed 64-bit range reject instead of wrapping or converting to float.
The complete signed envelope is limited to 600,000 bytes. Blocks remain limited
to 1 MiB / 1000 transactions; candidate selection skips transactions that would
exceed the remaining block byte budget. There are no separate contract signatures.

`ContractId = H(GREENWOOD_CONTRACT_V1, complete_raw_wasm_bytes)`. Internally it is
32 raw bytes; display is lowercase hex. Caller, nonce, height, timestamps and
hex-encoded code are not included. One ID represents one immutable instance.
A duplicate ID fails with CONTRACT_ALREADY_EXISTS and never reinitializes code.
No upgrade, replacement or deletion operation exists.

### Execution and failure/nonce semantics

`State::applyTransaction` authenticates chain ID, address/public-key binding,
signature and exact sender nonce before dispatch. Nonce 0xffffffff is exhausted.
Payload/limit checks and code lookup precede effects. Deployment validates the
host-enabled integer-only Wasmtime profile, derives the ID, creates a detached
state view and runs optional `greenwood_init`. Input is raw init calldata. With
no init export, valid deployment still applies attached value and installs code;
instantiation and active-data initialization still run and consume fuel. A
present init export must be a function with no parameters and one integer result.
An invalid export/signature or failed instantiation is not treated as absence.

Calls require deployed code, build `bytes(method) | bytes(calldata)` as input,
and execute mandatory `greenwood_call`. Both init/call use the 3B host ABI.
The export's scalar result remains uninterpreted, as in 3B; a normal return is
successful regardless of scalar value. Contracts signal transaction failure by
trapping; recoverable host statuses may be handled. Return bytes use return_value.

On success, the execution layer atomically applies code (deployment only), storage
writes/deletes, account deltas and contract deltas to a detached candidate State,
increments sender nonce exactly once, then publishes that candidate. The host and
overlay never commit themselves. The committer rejects unsealed/discarded overlays,
validates contract existence, storage limits and resulting balances, and computes
the resulting root before publishing any change to its target State.

**Failure rejects the transaction and leaves the entire pre-state unchanged,
including sender nonce.** This deliberately follows existing Transfer semantics.
A block containing such a transaction is invalid; failed calls are not finalized
as nonce-consuming transactions. No code, storage, balance, attached value, event
or output survives. A failed transaction can be retried at the same nonce if its
preconditions change. There are no gas-price or fee economics: gas_limit is the
Wasmtime fuel budget, gas_used is fuel consumed, with no additional host tariff.

Build, independent block execution, finalization and replay all use this same
state transition. A rejected candidate never changes live finalized State or
SQLite. No proposer-supplied result is trusted.

### Unified state-root records

Add these records to the existing sorted logical-record Merkle tree; there is no
separate contract root. The existing namespace/key sorting, `bytes(namespace) |
bytes(key) | bytes(value)` leaf preimage and GREENWOOD_STATE_* domains are unchanged.

| Namespace (ASCII) | Key | Value |
|---|---|---|
| contract_code | contract_id[32] | u32 abi_version=1, code_hash[32] |
| contract_storage | contract_id[32], bytes(storage_key) | raw storage_value bytes |
| contract_balances | contract_id[32] | u64 balance |

`code_hash` is the same domain-separated code digest as ContractId. Actual immutable
WASM bytes plus ABI version are logical State data; the root commits to bytes
through this digest, and loading a code record verifies that digest equals its ID.
Every deployed contract has a balance record, including zero. Empty storage values
are retained and differ from missing keys. Deletes remove the corresponding record.
A contract without storage contributes no storage records. ABI version is committed
alongside code identity. PHP map keys, SQLite schemas/row order and serialization
of implementation objects never enter the root.

### Derived receipts and event context

Receipts are **derived execution data**, not block-header fields or state-root
records. There is no receipt root or new consensus hash domain. Successful receipt
fields are status, gas_used, raw return_data, ordered events, raw contract_id and
raw transaction_hash. Storage and balance effects are committed through state_root;
signed inputs are committed through transactions_root. Receipts and events can be
regenerated by replay and are not trusted as substitutes for execution.

Each successful event adds transaction_hash to 3B's contract_id/topic/data/event_index.
Ordering is block transaction order, then event_index (zero-based within a call).
Failed execution has no surviving events or output. Fixed failure statuses are:
CONTRACT_ALREADY_EXISTS, CONTRACT_NOT_FOUND, INVALID_WASM, INSUFFICIENT_BALANCE,
BALANCE_OVERFLOW, TRAP, OUT_OF_GAS, MISSING_EXPORT and EXECUTION_ERROR. SUCCESS
identifies a committed contract transition. Fatal host/runtime or incorrect export
signature errors use EXECUTION_ERROR. Early rejection consumes zero execution fuel;
actual VM failures report available consumed fuel. Malformed payload/authorization/
nonce errors are validation exceptions, not executed contract receipts. Failed
receipt objects are available to the rejecting caller but are not finalized/indexed.

Native exception text, paths, hostname, time measurements and pointer values never
enter deterministic receipts. SQLite's receipt JSON is only a derived index/display
encoding: binary fields are lowercase hex; integers remain exact. It is not used
as canonical transaction/state encoding.

### Derived persistence and replay acceptance

SQLite stores contract code, storage, balances and ordered receipts along with
accounts, validators and tip metadata in the same BEGIN IMMEDIATE transaction.
An insertion failure rolls back all tables. Startup still replays trusted genesis
and every finalized block/certificate before replacing the index. No WASM machine
state is persisted. Re-execution reconstructs code, storage, balances, nonces,
events/receipts and the same single Greenwood state root.

Single-process acceptance builds and independently executes six blocks containing
a Transfer, storage deployment, set/get calls with attached value, native-value
deployment and a contract-to-account payment. Fixture validators sign real 3-of-4
certificates in that process; restart and deletion of only SQLite both reproduce
height/hash/root, all logical records, actual code bytes and ordered receipts.
An injected SQLite write failure proves atomic index rollback. This is not the
multi-node contract finality/catch-up acceptance reserved for 3E.

Frozen compatibility vectors are in `tests/Fixtures/contracts-v1.json`: full WASM,
pre-state records/root, canonical payload/unsigned/signed transaction bytes,
signing digest, transaction hash, ContractId, gas limit, deterministic receipt and
post-state records/root. Twelve executions cover deployment, storage, events,
value transfer, constructor state and failed execution. The PHP vector test checks
real execution against the frozen file; `verify_contract_vectors.py` independently
checks encodings, hashes and both state roots without importing Greenwood code.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](README.md) · [Architecture overview](architecture/overview.md) · [WASM ABI v1](GREENWOOD_WASM_ABI_V1.md) · [Compute protocol v1](GREENWOOD_COMPUTE_V1.md)
<!-- /docs-nav -->
