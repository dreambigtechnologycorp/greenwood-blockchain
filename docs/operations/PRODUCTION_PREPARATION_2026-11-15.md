# Post-merge production preparation — November 15, 2026

> Historical snapshot: the findings and commands below describe their dated
> source/infrastructure state, not the current installation procedure. Start with
> [project status](../PROJECT_STATUS.md) and [operator onboarding](README.md).
> Retain the recorded evidence and counts; do not repeat completed initialization,
> removal or credential-generation steps from this snapshot.

Merged implementation: `bfa3d500d799d212530fc469e745679f0e8e3aa4`.
Production installation and permanent genesis remain unauthorized.

## Read-only preflight status

Authenticated read-only checks completed against all four user-confirmed host
identities. Detailed topology, runtime findings, machine identifiers and raw
evidence remain in the protected, ignored local `build/production-preparation/`
directory and are excluded from this public document. No remote services or
configuration were changed. Runtime reconciliation and independent recovery
verification remain outstanding; SSH success is not recovery qualification.

## Repeatable preflight

`scripts/production-preflight.py` requires an explicit four-host JSON inventory,
a matching file containing exactly four confirmed ED25519 host records, and an
SSH key path. It validates addresses, unique machine identities and fingerprints,
uses only fixed read-only commands, stops on host/machine mismatch, and refuses to
overwrite evidence. It never treats successful SSH as recovery or installation
approval. Inventory version 1 has a `hosts` list containing `name`, `address`,
`user`, `machine_id`, `architecture` and `host_key_sha256` for each host.

```sh
python3 scripts/production-preflight.py \
  --inventory /private/confirmed-inventory.json \
  --known-hosts /private/known_hosts \
  --ssh-key /private/id_ed25519 \
  --output /private/new-preflight.json
```

## Public genesis preparation

The new command consumes only an economic policy and public topology:

```sh
php scripts/economics-cli.php prepare-production-genesis \
  /private/reviewed-policy.json /private/public-topology.json
```

Incomplete templates return exit code 2 and `BLOCKED`, with no genesis candidate.
Complete technical inputs produce `CANDIDATE_ONLY`, a public genesis document,
public provider identities, genesis hash and state root. This never authorizes
activation, verifies Institute ratification, generates keys, broadcasts, writes
ledger state or imports old testnet state. Custody and freshness must also be
verified operationally; the retired public-key list must be complete.

Preparation rejects the old chain ID, reused/duplicate validator-provider keys,
unknown fields, invalid endpoints, unresolved policy values and historical optional
trust policies. The public topology template deliberately contains no production
identities or addresses. Do not put secrets into these input documents.

## Remaining build and activation dependencies

The validator builder requires the repository-pinned ARM64 PHP toolchain and
extensions. Service recipes require an approved digest-pinned runtime and an OCI
builder. These release prerequisites still need to be supplied and qualified.
The committed-source archive is reproducible; validator and service OCI artifacts
are not yet built or qualified. No live runtime was upgraded.

Governance ratification, reserve/fee budgets, signer/guardian custody, configurable
lost-quorum recovery implementation and tests, commercial Epoch 0 activation,
physical recovery and explicit destructive installation authorization remain open.
No new treasury or recovery authority is created by preparation tooling.
