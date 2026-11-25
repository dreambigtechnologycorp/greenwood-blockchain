# Run Greenwood services in your own environment

Choose the service you intend to operate, then prepare its trust, runtime and
state dependencies. You do not need to reproduce another operator's machine
names, network boot arrangement, gateway or complete cluster to understand a
provider or node. The repository's turnkey Ansible installations currently
validate explicit four- and seven-node reference profiles; they are not a
universal homelab installer. [Project status](../PROJECT_STATUS.md) distinguishes
released code, pending local changes and reference-installation evidence.

## Join an existing network or create a separate network

To participate in an existing network, obtain its trusted public genesis,
chain ID, protocol genesis hash, chain configuration, reviewed release revision
and approved RPC/peer endpoints. Verify these through the network's trusted
handoff. A genesis JSON file's SHA-256 and the protocol genesis hash are different
values. An HTTPS response is a node assertion; local certified replay provides
a different verification boundary.

Do not generate a new genesis to join, copy another operator's signing identity,
or run `fresh_cluster.yml` against the existing network. New-genesis preparation
creates a separate network with its own policy and validator identities; see
[production release preparation](PRODUCTION_REBUILD.md) only for that task.

The daemon supports a non-signing full-node role and a signing validator role.
Validators must already match the trusted genesis validator set; there is no
permissionless validator-join or automatic membership expansion in this release.
More machines or keys do not create independent ownership or governance authority.

## Select a role

| Role | Dependencies and responsibility | What it does not grant |
| --- | --- | --- |
| Full node / RPC | Trusted genesis and certified history, node runtime, durable ledger/index storage, reviewed peer/RPC configuration | Consensus voting without a genesis-authorized identity |
| Validator | Full-node dependencies plus its own authorized signing key and preserved safety journal | Permission to add validators or replace missing signing history |
| Finite compute provider | Trusted finalized RPC, dedicated provider identity, Linux supervisor/worker isolation, pinned containerd/runc/EROFS, funded assignments and durable provider state | Hosted deployment authorization, independent attestation or treasury access |
| Hosted application provider | Provider dependencies plus local application controller/observer, protected network policy, assignment receiver state and hosted funding | Public route publication or application ownership merely by starting a task |
| Application controller | Colocated provider Unix socket, finalized application/release authority, signed allocation/revocation policies and complete receiver acknowledgements | Remote public access to the provider operator socket |
| Assignment receiver / application edge | Configured mTLS peer identities, signed policy transport, durable rollback/assignment/route journals; edge additionally has reviewed ingress and admission | A node count that proves an independent quorum |
| Registry and registry authentication | Verified offline seed images, persistent registry/issuer storage, HTTPS, issuer key and explicit repository publishing policy | Arbitrary system-service publishing through an application token |
| Meter / attestor / coordinator | Qualified attributed sources, persistent evidence, explicit governed policy/consent and accepted trust profile | A payment or independent-observation claim merely from service startup |
| Artifact build controller | Reviewed source/submodule/dependency pins and reproducible artifact tooling | Application-controller or signing authority |
| Gateway / DNS / boot / storage infrastructure | Your site's chosen network and storage services, tested recovery and failure boundaries | A required dedicated machine or NFS-root topology for every operator |

A host may combine compatible roles after reserving capacity and reviewing their
shared failure domain. The current application controller requires its provider
locally; registry/edge roles can be separate. [Control-plane operations](../../deploy/ansible/CONTROL_PLANE_HANDOFF.md)
and [provider operations](../provider-operations.md) explain the service boundaries.

## Run a non-signing node

With the reviewed runtime/dependencies installed, trusted public genesis/config
provisioned, and durable data storage prepared, the operator can start the daemon
without a validator key. Paths below are role examples to replace with the actual
reviewed locations:

```sh
php bin/greenwoodd --genesis=/etc/greenwood/genesis.json \
  --config=/etc/greenwood/chain.json --data-dir=/var/lib/greenwood/node \
  --endpoint=http://127.0.0.1:9201
```

Omitting `--node` and validator-key options selects non-signing operation. The
command writes local chain/index state; it is not a read-only probe. Keep its
listener local unless a reviewed ingress policy explicitly exposes it. Obtain
certified history and reachable peer configuration for the existing chain, verify
advancing finality and preserve history on restart. See
[chain history](../architecture/chain-history.md) and
[node recovery](../architecture/recovery.md) for replay and restoration. An
operator API token is needed only when enabling that separate private API.

## Prepare a provider on an existing chain

1. Obtain the network's approved release/artifact pins and public chain inputs.
   Use [Linux runtime requirements](../linux-execution-manifest.md), then verify
   actual container execution and recovery on your hardware. The reference
   release builder targets ARM64; other host/platform support must be qualified
   rather than inferred from PHP or OCI portability.
2. Have the human operator provision a distinct provider key and local operator
   token, or reuse the matching existing identity. Start from
   [provider.example.json](../../config/provider.example.json): replace its
   placeholder chain, RPC, worker IDs and capacity with reviewed values. Pin
   `expectedPublicKey`/`expectedProviderId`; hosted recovery requires the public
   key pin. A remote RPC needs approved HTTPS trust; the example loopback address
   is useful only when the intended trusted endpoint is actually local.
3. Put provider, metering and application state on supported durable storage.
   The current source requires SQLite `DELETE` plus `synchronous=EXTRA`
   and rejects existing WAL databases until explicit stopped-service migration.
   Do not treat a prior release as containing that change. NFS or `/run` is not
   production database storage. The [reference storage runbook](../../deploy/ansible/STATE_STORAGE.md)
   demonstrates guarded migration, but its exact devices and continuation phases
   must not be applied to a different homelab.
4. Configure the root supervisor and dedicated unprivileged worker, persistent
   containerd socket group, exact EROFS snapshotter and resource ceilings using
   [provider operations](../provider-operations.md). For the packaged hosting
   composition the private socket is
   `unix:///run/greenwood-provider/operator.sock`. Keep it private and preserve
   ownership journals across restart; never use a second host with the same key
   as an uncoordinated duplicate provider.
5. On a chain with native economics, fund the ordinary account derived from the
   provider's signing key with enough authorized GWD for the applicable fees
   before capability registration. A zero configured fee needs no fee funding.
   The transaction charges the network's configured compute fee; advertised
   capacity does not create a balance or waive fees. Use the current finalized
   account nonce. A reserve address or protected operations account cannot act
   as the provider signing account; the operations account permits routine
   transfers only. Retain enough fee balance for later capability and receipt actions.
6. Use the authenticated local [provider capability API](../operator-api.md#provider-capability-transactions)
   to submit the type-10 base capability with all required fields: `status`,
   `architectures`, `cpu_capacity_millis`, `memory_capacity_bytes`, `pid_capacity`
   and `max_concurrent_jobs`. The supervisor signs a normal chain transaction;
   fee, account, nonce, capacity and identity checks still apply. Confirm its
   finalized record at `/v1/compute/providers/{id}` before treating it as registered.
7. For native resource-funded service programs, complete the
   [resource capability and funding steps](#qualify-a-resource-funded-service)
   below before accepting work. Base capability alone is insufficient for those
   programs. This extra resource path is distinct from legacy finite compute.
8. Verify an authorized workload selected for the provider, runtime identity,
   limits, attributed usage, applicable settlement/refund behavior and restart
   recovery. Capability registration and process health alone do not establish
   successful service.

After all provider prerequisites and configuration are verified, this is the
manual supervisor entrypoint (human operator, not a validation-only command):

```sh
sudo php bin/greenwood-provider --config=/etc/greenwood/provider.json \
  --operator-socket=/run/greenwood-provider/operator.sock \
  --operator-token-file=/etc/greenwood/operator.token
```

Use one managed supervisor per identity; do not run this alongside an existing
systemd instance. Hosting additionally needs its reviewed network and funding
options/configuration, not just this finite-provider command. Inspect local status
with `php bin/greenwood-provider --config=/etc/greenwood/provider.json --status`
after service startup; inspect results locally rather than sharing raw operational state.

The current finite-compute eligibility source permits valid provider identities
for the `OPEN` trust class. `TRUSTED` and `SOVEREIGN` pools are explicitly derived
from genesis. Capability registration does not add a provider to those pools.
Native resource-service programs, independent qualification and hosted application
authority have their own rules; do not infer them from `OPEN` compute eligibility.
No generic public self-enrollment endpoint for the complete hosted control plane
is supplied by the current profile installer.

## Qualify a resource-funded service

The `compute_resources_v2` and `hosted_resources_v2` adapters require a finalized
**type-14 resource capability** in addition to the type-10 base capability.
Networks using `service_trust_policy: explicit_profiles_v1` require these resource
adapters and an explicit trust profile for approved service programs. This is
not a blanket type-14 requirement for legacy finite-compute networks without
that native resource-funding path.

After the base capability finalizes, prepare the version-2 extension defined by
[ProviderCapabilityV2](../../src/Resources/ProviderCapabilityV2.php). Bind its exact
provider key/ID and genesis, `base_nonce` to the current finalized base capability,
and its own monotonically increasing extension `nonce`. These are separate from
the signing account's transaction nonce. Include the actual asset declarations,
capacity/exclusivity groups, resource IDs and supported meter versions; CPU/RAM
extensions must fit the base capacity. Self-advertisement does not qualify hardware
or establish independent evidence.

The human operator can sign the reviewed document using
`scripts/economics-cli.php resource-capability-sign GENESIS KEY CAPABILITY_JSON NONCE`.
That helper signs only; it neither broadcasts nor grants program approval. Submit
through the existing authenticated transaction workflow, pay the configured fee
from the provider's ordinary account, and verify finalized inclusion. Before
funding, the base must be `ACTIVE`, the extension must have **nonempty assets**,
and its `base_nonce` must match the base's current nonce. A later base update
requires rebinding the resource extension before new funding; do not discard
active reservations or legacy obligations to force conversion.

Use a finalized approved service program with the correct provider, qualified
resource catalog, accepted trust profile, authorized beneficiary and limits.
The payer must accept the matching signed resource quote and its exact manifest,
then finalize the bound escrow/interval before assignment or execution. The payer
and provider are distinct accounts; quote acceptance, base registration and asset
advertisement do not fund a workload by themselves. Hosted service funding also
binds finalized Application Registry authority and the physical assignment.
See [resource metering and settlement](../guides/RESOURCE_METERING.md) and
[resource workflows](../../system-services/runtime/RESOURCE_WORKFLOWS.md).

## Add hosting or governed resource services

Hosting needs an application-controller composition with the provider local, the
network's exact receiver map and mTLS trust, current signed policies, authorized
registry credentials, finalized application/release authority and funded intervals.
The shipped profiles recognize specific node/role identities: inventing a new
hostname, removing a receiver or editing Terraform membership does not extend
that protocol/configuration support. A different composition needs a reviewed
source/configuration change and qualification before use.

Stage units, certificates and public configuration before activation. The fresh
workflow leaves application recovery disabled until protocol/provider readiness,
its public identity pin and hosted-funding configuration are complete. See
[application restart recovery](../../deploy/application-recovery/README.md).
Install governed meters through the existing consent/policy mechanism, not by
widening the registry seed allowlist. Use [resource metering](../guides/RESOURCE_METERING.md)
and [source adapters](../guides/SOURCE_ADAPTER_INTEGRATION.md) to bind actual sources.
Unsupported hardware remains unavailable; another key you control is not an
independent witness.

## Maintain and verify your service

Keep private keys, tokens, raw state and recovery backups under operator custody.
Preflight/check mode can inspect private files: credential-bearing validation,
signing, provisioning and service activation are operator actions, even when
logs suppress values. Share only explicitly public metadata and redacted results.

Retain trusted genesis, complete block history, validator safety journals where
applicable, provider ownership and operation journals, policy rollback state and
immutable evidence archives. Back up consistently and exercise restore. Review
[retention](../guides/RETENTION_AND_RECOVERY.md), [source updates](../guides/SELF_MANAGED_UPDATE.md)
and the role-relevant [acceptance checklist](../../deploy/production/PHYSICAL_ACCEPTANCE.md).
A successful source suite, RPC health probe or service start does not replace
physical workload, outage and recovery evidence.

[Operator runbook index](README.md) routes maintenance tasks. The
[four/seven-node installation guide](SEVEN_NODE_INSTALLATION.md) is a worked
reference deployment, not a requirement that every joining operator owns seven
machines or uses its addresses, certificates, identities or economic policy.
