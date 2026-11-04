# Core Chain Features

Greenwood implements a complete Layer 1 blockchain with these key features.

## Accounts

- **Ed25519 cryptography** - Industry-standard signatures
- **Deterministic addresses** - 64-character hex addresses
- **Balances** - Transferable token balances
- **Nonces** - Replay protection and ordering

## Transactions

- **Signed envelopes** - Type + payload + signature
- **Deterministic encoding** - Same bytes = same hash
- **Gas-like pricing** - Fuel-based execution limits
- **Finality guarantees** - strict `>2/3` quorum derived from the genesis set

## Blocks

- **Fixed validator set** - 4 nodes by default
- **Prevote/Precommit** - Tendermint-style consensus
- **Filesystem storage** - Immutable block files
- **Rebuildable state** - State can be reconstructed

## Consensus

- **BFT finality** - 3-of-3 for the physical three-validator profile; 3-of-4 for local four-validator fixtures
- **Proposer rotation** - Round-robin proposer selection
- **Safety journal** - Byzantine fault detection
- **Restart recovery** - Resume from last finality

## Smart Contracts

- **WASM execution** - Deterministic, sandboxed
- **State persistence** - Contract storage across blocks
- **Events** - Emit indexed events
- **Balances** - Attach tokens to contracts

## Compute Workloads

- **Container execution** - OCI container support
- **Resource limits** - CPU, memory, PIDs limits
- **Receipts** - Deterministic execution results
- **Security isolation** - Containerd isolation

## RPC API

- **Public endpoints** - Query finalized state
- **Operator endpoints** - Node management
- **Rate limiting** - Protection against abuse
- **Health checks** - Liveness and readiness

## Testing

Run the acceptance tests:

```bash
# Run all integration tests
php tests/Integration/Pass1Test.php

# Run contract tests
php tests/Integration/Pass3ATest.php

# Run consensus tests
php tests/Consensus/TetheredPacemakerModelTest.php
```

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Getting started](overview.md) · [Local quickstart](quickstart.md)
<!-- /docs-nav -->
