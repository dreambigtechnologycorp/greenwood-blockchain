# Getting started

Greenwood has three core PHP executable roles. Keeping those roles distinct is
the quickest way to understand a deployment:

| Program | Role | State it owns |
|---|---|---|
| `bin/greenwoodd` | Validator or non-signing node; consensus, block validation, and public API. | Finalized blocks, SQLite index, and—on validators—the signing journal. |
| `bin/greenwood-provider` | Linux provider supervisor; observes finalized assignments and runs OCI work. | Provider job registry and local runtime state. |
| `bin/greenwood` | First-party RPC client. | None; it deliberately does not read node databases or block files. |

`bin/greenwood-cli` is intentionally narrower: it creates development keys and
locally signs selected transaction types. It never sends private keys over RPC.

The separate Python CLI v0.3 manages developer keys, application registration,
OCI publishing and signed deployment operations. It talks to the registry issuer
and deployment controller, not the private provider operator API. For hosting,
start with the [control-plane guide](../../deploy/ansible/CONTROL_PLANE_HANDOFF.md).

## Choose a path

### Local protocol exploration

Use a disposable four-validator genesis. The node's genesis helper produces
four validator keys and endpoints on consecutive local ports. Start every
validator with its own data directory and matching key; three of the four are
required to finalize a block.

See the [local quickstart](quickstart.md). This path is for protocol and API
development, not a production topology.

### Validator operation

Validators require PHP 8.5 and the Composer dependencies, plus sodium and PDO
SQLite. A validator's private key must match the public key assigned to its
`--node` identity in genesis. Its signing journal is safety-critical: if a
previously initialized data directory has lost `signing.sqlite`, the daemon
fails closed rather than recreating signing history.

Read [recovery](../architecture/recovery.md), [consensus](../concepts/consensus.md),
and the [operator API](../operator-api.md) before operating a validator.

### Provider operation

Providers are Linux-only. They need containerd, a dedicated worker UID/GID,
the configured Wasmtime/containerd runtime, and a root-owned signing key. A
provider executes only work derived from finalized state; it never influences
block validation by starting a container.

Follow [provider operations](../provider-operations.md) and the
[Linux execution manifest](../linux-execution-manifest.md). The
[`config/provider.example.json`](../../config/provider.example.json) file is a
template, not a ready-to-run network configuration.

## Dependencies

The Composer package requires PHP `^8.5`, `ext-sodium`, `ext-pdo_sqlite`, and
`ext-ffi`, together with protobuf and gRPC packages. Production Linux nodes
also need `pcntl` and `posix`; provider operation additionally requires
containerd, runc, and `setpriv`. Contract tests and execution require the
supported Wasmtime shared library with FFI enabled.

Install project dependencies with:

```bash
composer install
```

Do not treat a successful dependency install as a readiness check for a
provider. Provider readiness also verifies the runtime, the containerd socket,
configured capacity, identity pins, and finalized chain access.

## What to read next

- [Quickstart](quickstart.md) — create a disposable local four-validator network.
- [Architecture](../architecture/overview.md) — understand state and process boundaries.
- [Public RPC v1](../rpc-v1.md) — integrate an application against finalized data.
- [Contracts](../contracts/overview.md) — write deterministic WebAssembly.
- [Compute protocol](../GREENWOOD_COMPUTE_V1.md) — understand provider work and receipts.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Getting started](overview.md) · [Local quickstart](quickstart.md)
<!-- /docs-nav -->
