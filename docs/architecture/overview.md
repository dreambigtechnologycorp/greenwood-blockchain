# Architecture overview

Greenwood is split along a strict deterministic boundary. `greenwoodd` decides
and records canonical state. `greenwood-provider` handles finalized compute
assignments and separately authorized hosted applications. Host observations, OCI lifecycle, logs, and
timing do not participate in block validation.

```text
client / signer                     operator
      |                                |
      v                                v
 public /v1 API                 private Unix-socket API
      |                                |
      +------------> greenwoodd <-------+
                         |
          +--------------+--------------+
          |              |              |
          v              v              v
     canonical codec   BFT driver   chain executor
          |              |              |
          +--------------+--------------+
                         |
       finalized block files + rebuildable SQLite index
                         |
                         v
                 finalized compute state
                         |
                         v
                greenwood-provider (Linux)
                         |
                    containerd / OCI
```

## Processes and trust boundaries

### `greenwoodd`

The node loads a genesis-fixed validator set, replays authoritative block
files, rebuilds its SQLite index, and runs the consensus driver. A validator
also loads its Ed25519 key and a separate durable `signing.sqlite` journal. The
journal is not derived state: it prevents a restarted validator from signing a
conflicting message for the same height, round, and phase.

The public API is an adapter over finalized `Chain`/`State` data. Optional
operator routes are served from a separately configured Unix socket and require
a bearer token; they are never exposed by the public listener.

### Contract runtime

Contract deployment and calls are regular signed transactions. The state
executor validates the transaction, runs the approved Wasmtime configuration,
and applies a speculative `ExecutionOverlay` only on success. Native host
access is constrained to the Greenwood ABI—no WASI, filesystem, clock,
networking, or process access. See [WASM ABI v1](../GREENWOOD_WASM_ABI_V1.md).

### Provider runtime

The provider supervisor is deliberately outside consensus. It authenticates
its root-owned identity, observes finalized request/assignment state from a
node, enforces configured resource ceilings, and asks local containerd to run
the exact digest-pinned image. It submits a canonical receipt only after local
execution reaches a terminal outcome. See [provider operations](../provider-operations.md).

## Data ownership

Hosted application orchestration adds a separate control plane: finalized registry
authority, signed allocation generations, receiver ACKs, provider reservation and
runtime inspection, and managed edge content readiness. It is not part of block
validation. See [current functionality](../CURRENT_FUNCTIONALITY.md) for its
components, durable stores and enablement boundaries.

| Data | Owner | Authority | Rebuildable? |
|---|---|---|---|
| Genesis | operator-distributed file | Protocol input | No; trust and distribute it explicitly. |
| `blocks/*-block.bin` | node | Canonical finalized history | No; gaps or conflicts fail closed. |
| `blocks/*-cert.bin` | node | Finality evidence | Re-fetch only from trusted finalized evidence. |
| `state.sqlite` | node | Query/index cache | Yes; reconstructed from genesis and blocks. |
| `signing.sqlite` | validator | Anti-equivocation safety journal | No; loss after initialization fails closed. |
| provider registry/runtime files | provider | Local orchestration state | Reconciled against finalized state. |

## Deterministic execution path

Every validator independently decodes canonical bytes, verifies the signed
transaction, validates its state transition on a cloned state, calculates the
ordered transaction commitment and logical state root, then verifies header
links, expected proposer, roots, and finality evidence. Only then does a block
enter local authoritative history and refresh the derived SQLite index.

The exact byte rules and limits belong to [Protocol v1](../GREENWOOD_PROTOCOL_V1.md),
not to PHP object serialization or JSON representation.

## Further reading

- [Blockchain layer](blockchain-layer.md)
- [Consensus layer](consensus-layer.md)
- [State model](state.md)
- [Chain history](chain-history.md) and [recovery](recovery.md)

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Architecture overview](overview.md) · [Core concepts](../concepts/overview.md)
<!-- /docs-nav -->
