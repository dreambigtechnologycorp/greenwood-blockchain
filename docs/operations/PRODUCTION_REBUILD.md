# Prepare and deploy a reviewed network release

This procedure is for release maintainers and operators creating or replacing a
network installation. To run services on an existing network, start with
[role-based onboarding](ROLE_BASED_ONBOARDING.md); joining does not require a new
genesis, treasury policy or another operator's machine layout. Use
[project status](../PROJECT_STATUS.md) for the source/release boundary and the
separately recorded reference deployment's progress.

## Select the operation before building

| Situation | Path |
| --- | --- |
| Join an existing chain as a provider or non-signing node | Reuse its trusted public chain inputs; follow the role guide and network participation rules |
| Initialize a genuinely new supported reference installation | [Fresh cluster](../../deploy/ansible/FRESH_CLUSTER.md), with complete approved chain, role, artifact and custody inputs |
| Continue after runtime and identity staging, before validator initialization | [Bounded continuation](../../deploy/ansible/FRESH_CLUSTER.md#continue-after-runtime-and-identity-staging); preserve installed genesis, keys and all state |
| Continue a storage migration | Select the exact receipt/observed-state phase in [state storage](../../deploy/ansible/STATE_STORAGE.md); the provided plan is reference-specific |
| Replace runtime code in an existing installation | Reviewed immutable release plus `release_runtime.yml`; no fresh-genesis or identity reset |

The existing reference deployment's obsolete testnet removal is recorded in
[the November 17 snapshot](TESTNET_REMOVAL_2026-11-17.md). It is completed history,
not a standing instruction to erase state. Any different destructive replacement
needs its own exact-target authorization and recovery procedure.

## Ordered release gates

1. Review the complete source and untracked files, including deployment tooling,
   runtime persistence changes and documentation. Preserve unrelated local work.
   Run applicable regressions and record warnings, skips and unavailable hardware
   checks separately. Earlier green runs do not qualify later changes.
2. Select a reviewed immutable revision and record submodule, dependency, native
   runtime and image pins. Committing or publishing is a separate authorized
   release action. Do not package the old HEAD as if it contains local changes.
3. Build and qualify artifacts for that revision. Bind source, OCI manifests,
   archive hashes, runtime inputs and verification evidence in one manifest.
   Follow [production packaging](../../deploy/production/README.md).
4. Bind the exact public chain/genesis, role topology, service configurations,
   approved policy and existing or newly operator-provisioned identities. For an
   existing chain, retain its identity and history. Policy approval, key custody
   and service consent are separate evidence, not inferred from a successful build.
5. Verify actual runtime, durable state and recovery readiness on the intended
   hosts. Credential-bearing checks remain operator-only. Preserve signing,
   ownership, allocation, policy and route journals. Keep all clients of a
   migration stopped until copy/conversion and runtime checks complete.
6. Run the selected guarded installation or maintenance procedure under its
   approved scope. Startup order establishes validator finality, direct HTTPS
   origins and relays, provider trust/readiness, then policy/registry/hosting
   services. Recovery remains disabled until its identity and funding inputs are ready.
7. Capture role-relevant [physical acceptance](../../deploy/production/PHYSICAL_ACCEPTANCE.md)
   for execution, attribution, settlement/refunds/disputes, authorization,
   certificates, restart, storage loss and restore. Activate only services and
   payable profiles whose authority and evidence are complete.

## Reference-network policy and topology

The repository's four/seven-node profiles retain validators A/B/C and explicit
provider, build-controller, edge and registry placements. See the
[reference installation guide](SEVEN_NODE_INSTALLATION.md). They are supported
compositions, not a requirement that another homelab own the same machines.
Arbitrary hosted receiver membership or validator expansion is not implemented
by changing an inventory.

The [November 18 adoption record](../economics/PRODUCTION_POLICY_ADOPTION_2026-11-18.md)
records the reference operator's selected Epoch 0 policy, including 15/25/30/25/5
allocation, bounded operations funding, fee choices and recovery parameters.
Do not relabel these selected terms as wholly unratified. Adoption does not prove
custody completion, on-chain execution, independent administration or physical
acceptance, and does not authorize a different operator's spending.

Reference supply allocation: 1,000,000,000 GWD at six decimals.

| Native reserve | GWD | Base units |
| --- | ---: | ---: |
| security_emissions | 150,000,000 | 150000000000000 |
| grants | 250,000,000 | 250000000000000 |
| subsidies | 300,000,000 | 300000000000000 |
| institute_treasury | 250,000,000 | 250000000000000 |
| working_operations | 50,000,000 | 50000000000000 |

Fresh-production validation requires explicit service trust and recovery decisions.
Unsupported payable meters remain disabled. Operator-attested customer-paid
Epoch 0 services do not acquire independent-attestation subsidy authority.
Validator membership remains genesis-fixed; resource/operator role registration
does not add consensus votes.

## Source preparation and evidence

Use the environment and dependency instructions in
[self-managed updates](../guides/SELF_MANAGED_UPDATE.md). Review in the working
checkout; run final release qualification against the chosen clean revision:

```sh
php -d ffi.enable=true vendor/bin/phpunit --testsuite source,contracts
python3 -m unittest discover -s tests/Release -v
python3 scripts/verify-resource-release.py --require-clean \
  --output-dir build/release-qualification --certified-history
python3 scripts/package-source.py HEAD --output-dir build/production-source
```

Use new output directories. Native acceptance additionally needs the real
approved runtime and hardware; skipped native checks are not passes. Source
archive, runtime-image and service-image digests identify different artifacts.
The current local SQLite policy requires `DELETE` with `synchronous=EXTRA`; old
WAL state needs stopped-service conversion and a rebuilt matching release before
startup. See [state storage](../../deploy/ansible/STATE_STORAGE.md).

Public genesis preparation/validation is not signing or activation. Human
operators retain private identities and execute credential-bearing checks and
service changes. Do not return private inventories, raw state, signing keys or
backup contents as release evidence. Share public hashes and bounded, redacted
results. Physical acceptance follows actual authorized deployment; it must not
be marked passed in advance.
