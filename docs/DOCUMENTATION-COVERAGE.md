# Documentation map and maintenance policy

Greenwood documentation is maintained by authority, not by an aspirational
coverage percentage. The current source and the versioned protocol documents
are the required references for behavior.

## Authority order

1. `GREENWOOD_PROTOCOL_V1`, `GREENWOOD_WASM_ABI_V1`, and
   `GREENWOOD_COMPUTE_V1` define compatibility behavior.
2. `src/` and checked-in tests define the current PHP reference implementation.
3. `rpc-v1.md` and `operator-api.md` define the supported integration surfaces.
4. Guides and examples explain how to use those boundaries; they must not
   invent commands, endpoints, state tables, or consensus rules.

## Coverage map

| Domain | Primary reference | Implementation anchor |
|---|---|---|
| Canonical protocol | [Protocol v1](GREENWOOD_PROTOCOL_V1.md) | `src/Codec`, `src/Crypto`, `src/Transaction`, `src/Block`, `src/Consensus` |
| Chain persistence | [Chain history](architecture/chain-history.md) | `src/Node/Chain.php`, `src/Persistence` |
| Logical state | [State model](architecture/state.md) | `src/State`, `src/Persistence/StateSqlite.php` |
| Contract runtime | [WASM ABI v1](GREENWOOD_WASM_ABI_V1.md) | `src/Contracts` |
| Compute | [Compute protocol v1](GREENWOOD_COMPUTE_V1.md) | `src/Compute`, `src/Provider` |
| Public API | [Public RPC v1](rpc-v1.md) | `src/Rpc/HttpApi.php` |
| Operator API | [Operator API v1](operator-api.md) | `src/Rpc/OperatorApi.php` |
| Deployment | [Deploy README](../deploy/README.md) | `deploy/` |
| Verification | [Testing overview](testing/overview.md) | `tests/` |
| Hosted applications | [Current functionality](CURRENT_FUNCTIONALITY.md) | `system-services/deployment-controller`, `src/Edge` |
| Registry authorization | [Registry issuer](../system-services/registry-auth/README.md) | `system-services/registry-auth/src` |
| New-node staging | [Physical handoff](../deploy/ansible/CONTROL_PLANE_HANDOFF.md) | `deploy/ansible/roles/control_plane`, provider runtime preflight |

Current guides distinguish source behavior, protocol compatibility and required
physical validation. See the [audit scope](CURRENT_FUNCTIONALITY.md#documentation-audit-scope).

## Operating Reference Set

| Process | Current guide |
| --- | --- |
| Local development and nonce-safe transfer example | [Getting started](getting-started/overview.md), [quickstart](getting-started/quickstart.md) |
| RPC client configuration | [CLI](cli.md) |
| Runtime and worker configuration | [Linux requirements](linux-execution-manifest.md), [provider operations](provider-operations.md) |
| Troubleshooting and uncertain-start recovery | [Troubleshooting](provider-operations.md#troubleshooting) |
| Restore confirmed-running application tasks after reboot | [Application recovery](../deploy/application-recovery/README.md) |
| Playbook selection and side effects | [Deployment](../deploy/README.md) |
| Inventory, secrets, runtime artifacts, policy SFTP, HTTPS and activation | [Control-plane operations](../deploy/ansible/CONTROL_PLANE_HANDOFF.md) |
| Local tests versus intended-host acceptance | [Testing](testing/overview.md) |

Reports and evidence are excluded from this operating reference set. Updating
guides does not modify evidence, authorize deployment or establish passing
installation results. The current instructions contain only supported processes;
no report should be substituted for them.

## Maintenance checklist

When changing protocol-relevant code, update the relevant specification,
vectors/tests, source anchor, and public-facing guide in the same change. When
deprecating a page, replace stale instructions with a clear link to the
canonical reference rather than leaving plausible but invalid examples.

Evidence is kept separate from the operational reference set and is not changed
by documentation maintenance.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](README.md) · [Implementation inventory](IMPLEMENTATION-INVENTORY.md) · [Testing overview](testing/overview.md)
<!-- /docs-nav -->
