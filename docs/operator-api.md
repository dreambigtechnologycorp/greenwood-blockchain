# Greenwood Operator API v1

Privileged control plane for node operators. **Never served on the public
listener.** Transport (preferred order):

1. Unix-domain socket (`--operator-socket=/run/greenwood/operator.sock`, mode 0660)
2. Loopback-only HTTP + bearer token

Authentication: every request requires `Authorization: Bearer <token>`.
The token comes from a protected file (`--operator-token-file`, mode 0600),
is compared in constant time, and is never logged or echoed in errors.
Missing/invalid credentials → 401 UNAUTHENTICATED.

## Validator routes (greenwoodd)

| Route | Method | Response |
|---|---|---|
| /v1/operator/validator/status | GET | role, validator_id, chain_id, finalized_height/hash, state_root, peer_count, syncing |
| /v1/operator/validator/consensus | GET | height, round, finalized_height/hash, mempool_size, peer_count, syncing |

Signing keys, journal records and key paths are never exposed.

## Provider routes (greenwood-provider)

| Route | Method | Response |
|---|---|---|
| /v1/operator/provider/status | GET | healthy, ready, pid, observed_height, network_status, local_drain, effective_accepting_work, active/queued/running_jobs, containerd_available, snapshotter, snapshotter_ready |
| /v1/operator/provider/jobs | GET | shaped job list (request_id, generation, state, runtime_id, worker_pid, error) |
| /v1/operator/provider/runtime | GET | containerd_socket_access, configured_snapshotter, snapshotter_available, snapshotter_ready, ephemeral_storage_limit_bytes (EROFS bound), worker_identity_ready, data_path_writable, chain_reachable, signing_key_isolated |
| /v1/operator/provider/drain | POST | canonical DRAINING capability tx |
| /v1/operator/provider/resume | POST | canonical ACTIVE capability tx |
| /v1/operator/provider/capability | POST | canonical capability update tx |

EROFS: when configured, missing EROFS tooling/socket makes `snapshotter_ready`
false. There is no overlayfs fallback.

## Canonical mutations

### Hosted application operations

The provider also offers POST routes under `/v1/operator/provider/applications/`:

| Suffix | Purpose |
| --- | --- |
| `reserve` | Reserve an application/service network binding for a digest-qualified image; does not establish public readiness. |
| `start` | Execute through the provider application operator with scoped registry credentials and authorization. |
| `inspect` | Observe the identified application/service runtime. |
| `stop` | Stop the identified application/service runtime. |

These are private operator operations, not provider-capability transactions and
not the developer deployment API. A process without a corresponding handler
returns 404. The controller performs finalized release, signed scope, allocation
and receiver-ACK checks before execution. Keep this surface peer-restricted;
do not expose it as a shortcut for developer deployment.
See [provider operations](provider-operations.md) and the
[controller guide](../system-services/deployment-controller/README.md).

### Provider capability transactions

drain / resume / capability **do not mutate state locally**. Flow:

1. Load current finalized capability from chain (404/UNREGISTERED → 409
   PROVIDER_CAPABILITY_NOT_FOUND for drain/resume).
2. Merge requested fields; `capability_nonce = previous + 1`.
3. Sign `TYPE_PROVIDER_CAPABILITY = 10` with the supervisor identity.
4. Submit through the existing chain client submission path.

Response:

```json
{ "tx_hash": "...", "provider_id": "...", "requested_status": "DRAINING",
  "capability_nonce": 2, "finalized": false }
```

`finalized: false` — this is transport acceptance, not finality. Poll
`GET /v1/compute/providers/{id}` on the public API. Chain admission enforces
all protocol guards (key binding, capability nonce, reservation cover);
rejection surfaces as 409 PROVIDER_CAPABILITY_REJECTED.

Capability request fields (all optional after registration; all required for
initial registration): `status` (ACTIVE|DRAINING|SUSPENDED), `architectures`,
`cpu_capacity_millis`, `memory_capacity_bytes`, `pid_capacity`,
`max_concurrent_jobs`. Unknown fields → 400.

## Local emergency drain vs network status

`ProviderJobRegistry::setDrain()` (CLI `--drain` at startup) remains a
**local safety control** only. The distinction is explicit in provider status:

```
network_status           = ACTIVE      # finalized on-chain capability status
local_drain              = true        # local emergency switch
effective_accepting_work = false
```

Local drain never changes finalized state and is never presented as network
status; drain/resume via RPC are the canonical network operations.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](README.md) · [CLI reference](cli.md) · [Provider operations](provider-operations.md)
<!-- /docs-nav -->
