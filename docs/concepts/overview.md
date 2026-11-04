# Greenwood concepts

Greenwood is a finality-first Layer 1 with a deliberately narrow separation of
concerns: canonical state transitions happen on the fixed-validator chain;
WebAssembly contracts extend that state deterministically; OCI work happens
off-chain only after the chain has finalized an assignment.

## Accounts and transactions

Accounts are Ed25519 public-key identities represented by a 32-byte,
domain-derived address. A signed envelope specifies one transaction type, a
sender, an exact nonce, a chain ID, a canonical payload, and an Ed25519
signature. Validators reject malformed bytes, wrong chain IDs, invalid sender
derivation, invalid signatures, and nonce/state violations.

Transfers, contract deploy/call actions, and compute protocol actions are all
canonical transactions. API admission is not inclusion; a transaction becomes
state only in a finalized block. See [accounts](accounts.md) and
[transactions](transactions.md).

## Blocks and finality

A block links the prior tip and state root, commits to its ordered transaction
list, and carries the candidate resulting state root. The proposer is selected
deterministically from the genesis-fixed validator set. Validators execute
the candidate independently, then use proposal, prevote, and precommit phases.
A strict `> 2/3` quorum is `intdiv(2*n,3)+1`: three of three validators in the
physical profile, or three of four in the local fixture profile.

Finality evidence is persisted beside the binary block history. The signing
journal prevents a validator from emitting conflicting phase signatures after a
restart. See [blocks](blocks.md), [consensus](consensus.md), and
[chain history](../architecture/chain-history.md).

## Logical state

The chain commits to normalized logical records for accounts, contracts, and
compute. SQLite is a local derived index; it is rebuilt from genesis and
authoritative block files. Consequently, editing a database is never a protocol
operation and cannot change a finalized root. See [state architecture](../architecture/state.md).

## Contracts

Contracts are restricted WebAssembly modules executed by Wasmtime. They use the
small `greenwood` host ABI for input, caller/contract identity, storage,
balances, events, and output. Execution uses deterministic fuel and a
transactional overlay; an invalid module, trap, out-of-fuel result, or fatal
host error discards the contract effects.

The ABI—not an application-specific JSON function convention—defines contract
inputs and results. See [contracts](../contracts/overview.md) and
[WASM ABI v1](../GREENWOOD_WASM_ABI_V1.md).

### Querying contracts (read-only execution)

Contracts can also be **queried** without a signed transaction via
`POST /v1/contracts/{id}/call`. The node executes the contract's
`greenwood_call` export against finalized state, allows storage reads, and
discards all side effects — no state mutation, no nonce consumption, no
consensus. Fuel is metered internally to protect the node but no blockchain fee
is charged. This is the Greenwood equivalent of Ethereum's `eth_call`. See
[RPC v1](../rpc-v1.md#contract-query-endpoint) for the request/response format.

## Compute

Compute requests, provider capabilities, assignments, and receipts are
finalized chain records. A provider supervisor watches that finalized state,
pulls the exact OCI image digest, applies configured limits through containerd,
and later reports a receipt. The provider's execution does not affect a block's
validity, and mutable image tags or arbitrary input URLs are not accepted.

See [compute protocol v1](../GREENWOOD_COMPUTE_V1.md) and
[provider operations](../provider-operations.md).

## Where guarantees come from

| Guarantee | Mechanism |
|---|---|
| Identical transaction/block identity | Canonical bytes plus domain-separated BLAKE2b-256. |
| No account replay | Exact account nonce and Ed25519 authorization. |
| No conflicting local finality record | Fixed quorum plus verified certificates and durable signing journal. |
| Rebuildable current state | Verified replay from genesis and immutable block history. |
| Deterministic contract effects | Restricted Wasmtime profile, fuel, ABI, and execution overlays. |
| No provider behavior in consensus | Finalized assignment/receipt protocol boundary. |

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Core concepts](overview.md) · [Architecture overview](../architecture/overview.md)
<!-- /docs-nav -->
