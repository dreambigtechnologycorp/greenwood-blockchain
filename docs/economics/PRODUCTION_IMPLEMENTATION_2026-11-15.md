# Production implementation checkpoint — November 15, 2026

> **Historical scope — reviewed November 24, 2026.** The four-node destination and unresolved-ratification list below predate later deployment planning and the November 18 adoption. The evidence counts remain November 15 results.
> Read the [economics index](README.md) for current source and policy precedence,
> and [project status](../PROJECT_STATUS.md) for release/installation and qualification.

This supersedes the deployment scope and open-source-component descriptions in
UNIVERSAL_RESOURCE_CONTINUATION_2026-11-14.md. The production destination is the
existing four physical nodes. No testnet chain state or identity is retained, and
no separate qualification hardware is required. Production activation remains HOLD.

## Implemented source

- Selected fresh-genesis allocation: 15/25/30/25/5, exactly one billion GWD at six
  decimals. Historical allocation fixtures remain explicit replay comparisons.
- Fresh-production validation requires `service_trust_policy: explicit_profiles_v1`.
  Resource programs must declare an explicit native trust profile and V2 resource
  adapter. Policy rotation cannot remove that restriction. Historical genesis
  documents without it remain replayable but fail fresh-production validation.
  The fresh-install identity check enforces the same policy and rejects provider
  keys reused from the genesis validator set.
- Epoch 0 operator-attested customer services require finalized E0 governance and
  disclosed provider/meter/attestor control. They cannot reserve or spend subsidies.
  Independent services require qualified separate sources and attestor domains;
  their coordinator cannot silently fall back to founder telemetry.
- Persisted local meter bundles produce scoped CPU, reserved RAM and mapped block
  I/O evidence candidates. RAM is an operator assertion from sampled allocation,
  not proof of continuous independent availability. Missing devices/controllers and
  uncertain/restarted intervals do not fabricate payable usage.
- Meter, attestor and settlement coordinator have OCI recipes and private IPC
  workers. Workflows replay certificate-verified blocks and pin chain/genesis/height/
  block/state root. Signatures and exact-byte relay remain explicit operations.
- Existing governed installation, consent, readiness, journal recovery, rollback,
  deployment controller/receiver, registry auth, archive/RPC and funding workflows
  are included. Release recipes use committed source and immutable runtime inputs.
- Read-only four-node Ansible preflight and an incomplete example inventory are
  supplied. An exact destructive reset plan is still dependent on confirmed machine
  identities, actual installation paths, recovery access and explicit authorization.

## Evidence and limits

See `evidence/production-release-2026-11-15/`. `strict-policy/verification.json`
records 49 passing suites, 253 PHP syntax checks and the explicit Epoch 0
certified replay: 24,488 blocks, three validator signatures, exact restart state
root, zero bootstrap subsidies and the selected 15/25/30/25/5 allocation.
`final-local/verification.json` records the preceding successful 48-suite run.
The top-level `verification.json` retains the earlier sandbox-limited failure;
it is not release success evidence. Source suite, contracts, Ansible and Studio
reports identify their separate scope and unavailable native runtimes. The updated
source/contracts run passed 607 tests and 6,129 assertions, with two skips and
20 deprecations. Release packaging tests passed all five checks. Independent
protocol vectors and the tracked-source publication scan also pass.
`native-unavailable.json` records the failed all-suite invocation caused by missing
gRPC/containerd/cgroup facilities; those physical checks remain outstanding.

These are local software results, including synthetic measurement fixtures and
real local SQLite/process/certificate/WASM execution. They do not establish live
containerd execution, physical metering, independent administration, OCI image
build success, browser rendering, or production acceptance. E3 consensus authority
migration and unsupported payable meters are not enabled by this release.

## Remaining production gates

1. Publish the reviewed source revision and build/verify immutable artifacts with
   the approved runtime digest and toolchain. OCI builds have not run here.
2. Formally ratify allocation, reserve budgets, fees/bootstrap funding, governance
   key custody and recovery. Unresolved template values remain unset. Selection of
   allocation alone does not authorize spending or a recovery council.
3. Provision independent fresh validator/provider identities using approved custody;
   generate and review new public genesis and network configuration.
4. Confirm exact four machines and infrastructure/recovery preflight; obtain explicit
   authorization for a concrete destructive testnet replacement plan.
5. Install through the existing Ansible/containerd/service infrastructure and collect
   physical acceptance evidence for workloads, measurements, finality, settlement,
   disputes/refunds/expiry, financial conservation, restart/renewal, registry and
   application deployment, and network governance.

The release does not claim completion of physical WP0–WP10 qualification. See
`../operations/PRODUCTION_REBUILD.md` for the required execution sequence.
