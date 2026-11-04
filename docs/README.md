# Greenwood documentation

This directory documents the Greenwood v1 PHP reference implementation. It is
organized around the boundaries an integrator or operator actually needs to
understand: the protocol, the node, contracts, compute, and the two API
surfaces. Hosted-application control-plane APIs are separate from node RPC and
the local operator API; see [current functionality](CURRENT_FUNCTIONALITY.md).

## Start with the right document

| If you are… | Start here | Then read |
|---|---|---|
| Evaluating Greenwood | [Architecture overview](architecture/overview.md) | [core concepts](concepts/overview.md), [protocol v1](GREENWOOD_PROTOCOL_V1.md) |
| Building a client | [Public RPC v1](rpc-v1.md) | [CLI reference](cli.md), [transactions](concepts/transactions.md) |
| Writing a contract | [Contracts overview](contracts/overview.md) | [WASM ABI v1](GREENWOOD_WASM_ABI_V1.md), [storage](contracts/storage.md) |
| Running a validator | [Getting started](getting-started/overview.md) | [recovery](architecture/recovery.md), [operator API](operator-api.md) |
| Operating a provider | [Provider operations](provider-operations.md) | [compute protocol v1](GREENWOOD_COMPUTE_V1.md), [compute security](compute-security-v1.md) |
| Deploying a testnet | [Deployment foundation](../deploy/README.md) | [Linux execution manifest](linux-execution-manifest.md) |
| Deploying hosted applications | [Current functionality](CURRENT_FUNCTIONALITY.md) | [controller](../system-services/deployment-controller/README.md), [control-plane operations](../deploy/ansible/CONTROL_PLANE_HANDOFF.md) |
| Verifying a change | [Testing overview](testing/overview.md) | [implementation inventory](IMPLEMENTATION-INVENTORY.md) |

## Compatibility and normative documents

The following documents define the v1 compatibility boundary. When an example
or an implementation detail conflicts with one of them, these documents win.

- [**GREENWOOD_PROTOCOL_V1**](GREENWOOD_PROTOCOL_V1.md) — canonical bytes,
  cryptography, genesis, blocks, consensus, state transitions, and recovery.
- [**GREENWOOD_WASM_ABI_V1**](GREENWOOD_WASM_ABI_V1.md) — contract execution
  profile, host ABI, limits, and transactional effects.
- [**GREENWOOD_COMPUTE_V1**](GREENWOOD_COMPUTE_V1.md) — compute request,
  assignment, provider capability, and receipt semantics.
- [**Public RPC v1**](rpc-v1.md) and [**Operator API v1**](operator-api.md) —
  stable HTTP/Unix-socket integration surfaces.

## Reading map

### Protocol and architecture

- [Concepts](concepts/overview.md): accounts, transactions, blocks, consensus,
  state, contracts, and compute in one place.
- [Architecture](architecture/overview.md): process boundaries and data flow.
- [Blockchain layer](architecture/blockchain-layer.md),
  [state](architecture/state.md), [chain history](architecture/chain-history.md),
  [consensus](architecture/consensus-layer.md), and
  [recovery](architecture/recovery.md): component-level behavior.

### Applications and APIs

- [CLI](cli.md): the PHP RPC/operator client and its distinction from the Python
  application deployment CLI.
- [Contracts](contracts/overview.md), [quickstart](contracts/quickstart.md),
  and [storage](contracts/storage.md): deterministic WASM development.
- [RPC endpoint details](rpc/public-api-endpoints-1.md),
  [transactions](rpc/public-api-transactions.md), and
  [compute](rpc/public-api-compute.md): response-level API reference.

### Operations and assurance

- [Provider operations](provider-operations.md) and
  [compute security](compute-security-v1.md): Linux provider requirements and
  the explicit v1 security model.
- [Linux execution manifest](linux-execution-manifest.md): supported runtime
  and filesystem/security requirements.
- [Testing](testing/overview.md): test layers and commands.
- [Troubleshooting](provider-operations.md#troubleshooting): authorization, runtime,
  policy, recovery and routing failures.

## Documentation status

Runtime source, reusable deployment configuration and regression tests are tracked.
Acceptance/verification scripts, acceptance documentation and audit data belong
only in ignored `docs/acceptance/`. Never force-add that directory. Local retained
scripts are not release entrypoints and may require path/environment review.

This is reference documentation, not a product roadmap. Commands are only
included when they map to a checked-in entry point. Chain queries use finalized
state; transaction lookups also inspect the mempool, and diagnostics observe local
processes. Operational instructions avoid creating or
modifying protocol state outside canonical transactions.

The reading map above is the operating reference set for this version. A report
or evidence file outside that map is not an installation or activation procedure.

---

<!-- docs-nav -->
**Continue reading:** [Get started](getting-started/overview.md) · [Explore the architecture](architecture/overview.md) · [Read the protocol](GREENWOOD_PROTOCOL_V1.md)
<!-- /docs-nav -->
