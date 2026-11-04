# Merkle commitments

Greenwood uses ordered, domain-separated binary Merkle trees to commit to
transactions and logical state. They are commitments, not a general-purpose
proof service: v1 does not expose account or contract-storage inclusion-proof
RPC endpoints.

## Tree rules

For a configured leaf, node, and empty-tree domain:

```text
leaf  = H(leaf_domain, raw_item)
node  = H(node_domain, left || right)
empty = H(empty_domain, empty bytes)
```

Leaves retain their input order. If a level has an odd number of nodes, the
last node is duplicated before parent calculation. An empty list has the
domain-specific empty root. These rules apply identically on every validator.

## Transaction commitment

Blocks commit to the ordered list of signed transaction hashes using:

```text
GREENWOOD_TX_LEAF_V1
GREENWOOD_TX_NODE_V1
GREENWOOD_TX_EMPTY_V1
```

During validation the node recomputes this root from the decoded transactions
and rejects a header whose `transactions_root` differs. Reordering two valid
transactions changes the root and can also change state execution, so order is
always consensus-significant.

## State-root commitment

The state root commits to normalized logical state records with the separate
`GREENWOOD_STATE_*_V1` domains. It intentionally excludes SQLite internals,
provider host telemetry, logs, and other local implementation details. A node
that replays the same genesis and finalized blocks must produce the same root
even when its local filesystem layout differs.

## Verification boundary

The block header carries both the transaction root and resulting state root.
`Chain::execute()` recomputes both through canonical execution before a block
is finalized or accepted during replay. For the exact state record namespaces,
see [Protocol v1](../GREENWOOD_PROTOCOL_V1.md); for persistence behavior, see
[the state model](../architecture/state.md).

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Core concepts](overview.md) · [Architecture overview](../architecture/overview.md)
<!-- /docs-nav -->
