# Greenwood Blockchain

**Experimental PHP reference implementation.** Source availability does not
imply independent security review or production readiness.

[Contributing](CONTRIBUTING.md) | [Security](SECURITY.md) |
[Release notes](CHANGELOG.md) | [Source releases](docs/RELEASING.md)

> **A deterministic Layer 1 for finalized state, programmable contracts, and
> verifiable off-chain compute.**

Greenwood is a PHP reference implementation of a deliberately small L1: a
fixed-validator BFT chain, a deterministic WebAssembly contract runtime, and a
provider network that executes digest-pinned OCI workloads. Compute jobs follow
finalized on-chain assignments; hosted applications use finalized registry releases
plus independently authorized, acknowledged physical assignments.

The project began with a simple conviction: useful infrastructure should not
force teams to choose between a trustworthy ledger and useful computation. In
Greenwood, the chain establishes the canonical request, assignment, and receipt
history; providers do the Linux/container work outside consensus. The result is
a system whose consensus surface remains deterministic while applications can
still reach real workloads. Contract state is queriable without a signed
transaction — `POST /v1/contracts/{id}/call` executes the contract's
`greenwood_call` export against finalized state and returns the result with
no state mutation, no nonce consumption, and no consensus overhead.

## What Greenwood delivers

- **Finalized chain state** — Ed25519 identities, canonical binary encoding,
  domain-separated BLAKE2b-256 hashing, Merkle commitments, filesystem-backed
  block history, and a rebuildable SQLite state index.
- **BFT finality** — a genesis-fixed, equal-power validator set; proposal, prevote,
  and precommit phases; a strict `> 2/3` quorum (3-of-3 for three validators,
  3-of-4 for four); and a
  durable signing-safety journal.
- **Programmable state** — deterministic, fuel-metered WebAssembly contracts
  executed through Wasmtime with a small Greenwood host ABI, transactional
  overlays, persistent storage, events, and rollback on failure. Contract state
  is **queriable without a transaction** via `POST /v1/contracts/{id}/call` —
  the eth_call equivalent for Greenwood.
- **Compute without consensus leakage** — finalized compute requests are
  scheduled to registered providers, which execute exact OCI image digests via
  containerd and submit canonical receipts. Containers never run during block
  validation.
- **Operator-ready boundaries** — a versioned public RPC API, a bearer-token
  operator API on a private Unix socket, a pure RPC CLI, and deployment assets
  for Linux deployment. New-node reproducibility and physical acceptance remain
  separate from source implementation and local tests.

## Current Functionality

The [source functionality guide](docs/CURRENT_FUNCTIONALITY.md) covers hosted
applications, registry authorization, signed assignments, provider reservation,
managed edge and Ansible staging. The Python deployment CLI is a separate
repository; `bin/greenwood` here remains the PHP RPC/operator client. Use the
[physical handoff](deploy/ansible/CONTROL_PLANE_HANDOFF.md) for new hardware.

## Architecture

```text
                         signed transactions / RPC
                                      |
                                      v
                         +-----------------------+
                         |      greenwoodd       |
                         |  PHP reference node   |
                         +-----------+-----------+
                                     |
              +----------------------+----------------------+
              |                      |                      |
              v                      v                      v
       chain & consensus       WASM contracts        finalized compute state
       blocks + SQLite         Wasmtime + ABI        requests, assignments,
       state index             deterministic fuel    provider capabilities
              |                      |                      |
              +----------------------+----------------------+
                                     |
                               finality proof
                                     |
                                     v
                         +-----------------------+
                         |  greenwood-provider   |
                         |  Linux supervisor     |
                         +-----------+-----------+
                                     |
                                containerd
                                     |
                           digest-pinned OCI work
```

The authoritative record is the finalized block history. SQLite accelerates
current-state queries and is rebuilt from genesis plus that history; it is not
the source of consensus truth. The protocol is the durable product boundary —
PHP is its first implementation, not its definition.

## Repository map

| Path | Purpose |
|---|---|
| [`src/`](src/) | Node, consensus, transactions, persistence, contracts, RPC, provider, and containerd integration. |
| [`bin/`](bin/) | `greenwoodd` node daemon, `greenwood-provider` supervisor, `greenwood` RPC CLI, and local signing utility. |
| [`docs/`](docs/README.md) | Protocol specifications, architecture, API, operations, testing, and examples. |
| [`deploy/`](deploy/README.md) | Terraform topology, Ansible playbooks, Linux release build, and testnet verification assets. |
| [`config/`](config/) | Provider and containerd configuration examples. |
| [`tests/`](tests/) | Unit, integration, consensus, CLI, operator, and contract coverage. |

## Start here

Greenwood is intended for Linux operation. The reference runtime requires PHP
8.5, Composer, sodium, PDO SQLite, FFI, gRPC, `pcntl`, and `posix`; contract
execution also needs the pinned Wasmtime runtime, and provider operation needs
containerd. See the [Linux execution manifest](docs/linux-execution-manifest.md)
before preparing a host.

```bash
composer install

# Create a disposable four-validator local genesis and its development keys.
php bin/greenwood-cli genesis ./local-genesis 9201

# Start validator A. Start B, C, and D in separate terminals with their own
# data directories and matching node/key arguments.
php bin/greenwoodd \
  --genesis=./local-genesis/genesis.json \
  --data-dir=./local-data/A \
  --node=A \
  --key=./local-genesis/keys/A.key
```

The generated genesis contains the local endpoints for validators `A` through
`D`; finality requires three of the four nodes. Generated keys and data folders
are local-development material — never use or commit them for a network.

For command syntax and the client/operator split, read the
[CLI reference](docs/cli.md). For node, provider, socket, and containerd
requirements, start with [provider operations](docs/provider-operations.md).

## Documentation guide

### Learn the model

- [Getting started](docs/getting-started/overview.md)
- [Core concepts](docs/concepts/overview.md)
- [Architecture overview](docs/architecture/overview.md)
- [Blockchain and state](docs/architecture/blockchain-layer.md),
  [consensus](docs/architecture/consensus-layer.md), and
  [recovery](docs/architecture/recovery.md)

### Build against Greenwood

- [Public RPC v1](docs/rpc-v1.md) and [operator API](docs/operator-api.md)
- [Smart-contract overview](docs/contracts/overview.md) and
  [contract quickstart](docs/contracts/quickstart.md)
- [WASM ABI v1](docs/GREENWOOD_WASM_ABI_V1.md)
- [Storage fixture](test/fixtures/reference_storage.wat) and
  [value fixture](test/fixtures/reference_value.wat)

### Run infrastructure

- [Provider operations](docs/provider-operations.md)
- [Application restart recovery](deploy/application-recovery/README.md)
- [Compute protocol v1](docs/GREENWOOD_COMPUTE_V1.md) and
  [compute security profile](docs/compute-security-v1.md)
- [Testnet deployment foundation](deploy/README.md)
- [Release builder](deploy/build-validator-release.sh)

### Verify and contribute

- [Testing overview](docs/testing/overview.md)
- [Current functionality](docs/CURRENT_FUNCTIONALITY.md)
- [Implementation inventory](docs/IMPLEMENTATION-INVENTORY.md)
- [Protocol v1](docs/GREENWOOD_PROTOCOL_V1.md)

## Protocol commitments

The v1 compatibility boundary is explicit:

| Specification | Defines |
|---|---|
| [`GREENWOOD_PROTOCOL_V1`](docs/GREENWOOD_PROTOCOL_V1.md) | Canonical encoding, hashing, transactions, blocks, genesis, and consensus messages. |
| [`GREENWOOD_WASM_ABI_V1`](docs/GREENWOOD_WASM_ABI_V1.md) | Contract runtime policy, deterministic execution limits, and host imports. |
| [`GREENWOOD_COMPUTE_V1`](docs/GREENWOOD_COMPUTE_V1.md) | Provider identities, assignments, resource commitments, and receipts. |

Any future implementation must reproduce the same protocol bytes, hashes,
state transitions, contract effects, and consensus decisions. Changes at that
boundary require a new version, vectors, and compatibility review.

## Verification

Run the suite appropriate to the runtime you have installed:

```bash
composer run test

# Source integration and contract execution need Wasmtime and PHP FFI.
composer run test:contracts

# Separate live Linux/containerd suite; use a disposable provider host.
composer run test:native

```
## Platform-dependent Tests

This broad selection is not a guaranteed macOS-only or hermetic suite. Contract
tests can require Wasmtime/FFI; provider and integration tests can require Linux,
sockets or native runtime dependencies. Select tests for the available host:

```bash
php vendor/bin/phpunit --bootstrap vendor/autoload.php \
  tests/Unit \
  tests/Integration \
  tests/Contracts \
  tests/Rpc \
  tests/Cli \
  tests/Operator \
  tests/Consensus
```

The deployment directory also contains the Terraform/Ansible workflow and
cluster verification tooling used for the reference testnet. Treat its topology,
credentials, state files, and generated artifacts as environment-specific —
begin with [its README](deploy/README.md), then adapt it to your own network.

## Security posture

This repository prioritizes fail-closed protocol validation and operational
separation. Private validator and provider keys are local-only; provider workers
run under a dedicated Linux identity; operator controls are separate from the
public listener; and immutable image digests are mandatory for provider work.

Before exposing a node or provider beyond a controlled test environment, review
the [provider operations guide](docs/provider-operations.md),
[compute security policy](docs/compute-security-v1.md), and
[operator API](docs/operator-api.md) in full.

## License

Original Greenwood code is licensed under the [Apache License 2.0](LICENSE).
Copyright 2026 DreamBIG Technology Corporation. See [NOTICE](NOTICE) and
[third-party notices](THIRD_PARTY_NOTICES.md) for attribution, and the
[trademark policy](TRADEMARKS.md) for Greenwood name and logo usage.
