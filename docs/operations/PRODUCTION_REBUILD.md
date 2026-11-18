# Fresh production release and profile-based replacement

See the [November 17 seven-node checkpoint](SEVEN_NODE_PREFLIGHT_2026-11-17.md) for current infrastructure evidence. The [November 15 preparation findings](PRODUCTION_PREPARATION_2026-11-15.md) retain historical four-node evidence and public genesis-candidate tooling.

The November 14, 2026 user direction supersedes the earlier disposable-cluster
requirement. The destination is the existing physical cluster, now expanded to seven nodes. Its
testnet genesis, validator/provider identities, blocks and ledger are not migrated.
No additional host or independent operator is a prerequisite for software completion
or explicitly approved Epoch 0 customer-funded service activation.

The implemented four/seven-node tooling is described in [the installation guide](SEVEN_NODE_INSTALLATION.md). All seven host identities and the six client NFS roots have now been verified. Production service placement, runtime reconciliation, recovery qualification and cutover authorization remain separate gates. The [earlier cluster assessment](NODE4_AND_SEVEN_NODE_ASSESSMENT_2026-11-16.md) documents the shared node-4 dependency.

## Ordered release gates

1. Finish source integration, system-service packaging and production tooling.
   Run applicable regressions and review security, correctness and reproducibility.
   Record unavailable physical checks separately; never report them as passes.
2. Commit reviewed source and documentation to the authorized Greenwood repositories
   and push immutable revisions. Exclude keys, credentials, protected per-machine
   configuration, runtime state and generated dependency trees.
3. Resolve governance and prepare fresh production identities and public genesis.
   Provision validator and provider keys separately; no old testnet key reuse.
   The selected allocation is **15/25/30/25/5**, totaling 1,000,000,000 GWD with six
   decimals. Fresh production requires `service_trust_policy: explicit_profiles_v1`;
   historical programs without a trust profile cannot be installed. Allocation selection is not authority to spend, activate, or recover
   a lost treasury quorum. The production template leaves unresolved limits unset.
4. Run infrastructure preflight on the exact machines in the selected profile: confirm machine IDs,
   addresses, architecture, storage, routing, available runtime and recovery access.
   Present those targets and the exact destructive installation plan for explicit
   authorization. No blanket reset based solely on an IP range or inventory name.
5. After authorization, remove the testnet installation and initialize the reviewed
   production release using existing Ansible/containerd/control-plane primitives.
   Install validators/providers, gateway, deployment controller/receiver, OCI registry
   and authentication, metering, approved attestation components, Application Registry,
   resource catalog and approved economic programs. Verify artifact digests.
6. Capture physical acceptance evidence on that rebuilt cluster: certificates and
   restart roots, workload execution and attribution, paid usage/refunds/disputes,
   conservation, deployment authorization, renewals, recovery, registration and policy.
7. Activate only the approved services and profiles. Unsupported payable meters stay
   disabled. DreamBIG-controlled telemetry is explicitly operator-attested; subsidy
   rules requiring independent evidence are not relaxed.

## Selected allocation and unresolved decisions

| Native reserve | GWD | Base units |
|---|---:|---:|
| security_emissions | 150,000,000 | 150000000000000 |
| grants | 250,000,000 | 250000000000000 |
| subsidies | 300,000,000 | 300000000000000 |
| institute_treasury | 250,000,000 | 250000000000000 |
| working_operations | 50,000,000 | 50000000000000 |

Formal Institute ratification, reserve window budgets, fees/bootstrap funding,
signer/guardian custody and lost-quorum recovery still need final decisions. Existing
three-of-five ordinary and four-of-five sensitive controls remain the implementation
basis. No new recovery council, discretionary compensation, privileged founder
allocation or authority-sunset date is inferred. E3 consensus membership/authority
migration remains an explicit protocol upgrade, not an automatic effect of onboarding.

## Acceptance truth labels

Local synthetic tests, real local processes/SQLite, certified local replay, actual
containerd execution and independent administration are separate evidence categories.
The retained local release evidence reports unavailable native containerd/gRPC/cgroup
prerequisites; image execution checks are not physical acceptance. Physical acceptance occurs on
the authorized rebuilt seven-node destination; separate qualification hardware is
not required. Production remains HOLD until the applicable gates are satisfied.

The old `qualification.ini` is retained only as a disabled historical fixture.
It does not constrain the newly authorized destination or require its provisioning.

## Reproducible preparation commands

Run from a clean checkout of the published review revision. Install the locked PHP
and test dependencies in a separate checkout if a local `vendor` path already
contains unrelated data. The PHP runtime needs sodium, SQLite, FFI and the pinned
Wasmtime library. Native acceptance additionally needs gRPC and the actual runtime.

```sh
php -d ffi.enable=true vendor/bin/phpunit --testsuite source,contracts
python3 -m unittest discover -s tests/Release -v
python3 scripts/verify-resource-release.py --output-dir /tmp/greenwood-review-evidence --certified-history
python3 scripts/package-source.py HEAD --output-dir /tmp/greenwood-source-release
```

Set `GREENWOOD_WASMTIME_LIB` and, if required, `GREENWOOD_SQLITE_EXTENSION` to the
reviewed local dependencies. Evidence output directories must not already exist.
The selected production allocation is the runner's default; explicit historical
candidates are separate regression cases. Native tests belong in the approved
physical acceptance run and must not be silently included as software-only passes.

After supplying reviewed identities and ratified values in a private copy of the
production policy template, assemble the fresh genesis through the reviewed
identity/genesis provisioning procedure. The economics CLI only validates the
resulting **public** genesis document:

```sh
php scripts/economics-cli.php validate-production-genesis /approved/public/genesis.json
```

This is schema/invariant validation, not Institute ratification or activation.
The fresh-production command rejects historical allocations and omitted explicit
service trust policy. It does not provision keys or authorize reserve spending.

For immutable service archives, set `GREENWOOD_PHP_RUNTIME` to the approved runtime
image with `@sha256:` digest, select `GREENWOOD_SERVICE_PLATFORM`, and run:

```sh
scripts/build-resource-services.sh /approved/new/service-archives
```

The runtime must contain the extensions and Wasmtime dependency documented by the
service recipes. Do not label archives verified until actual OCI build and runtime
checks pass. Record their hashes alongside the validator artifact and public genesis.

Use a private completed copy of `deploy/ansible/inventory/production-rebuild.example.yml`
for the read-only audit (never treat historical IPs as machine identity):

```sh
ansible-playbook -i /approved/private/production.yml deploy/ansible/playbooks/production_preflight.yml
```

The audit cannot remove testnet state or authorize installation. Once its evidence
is available, record the exact services, data directories and containerd namespaces
in the destructive plan, bind it to machine IDs and release/genesis hashes, and
obtain explicit authorization before invoking replacement tasks. Do not run the
existing fresh-cluster playbook against an existing testnet as an implicit reset.

## November 15 access/governance clarification

The supplied historical SSH user is `djordanjones`, with direct LAN Ed25519 key
access and previously working passwordless sudo on Ubuntu 24.04 ARM64. These are
not current connectivity results. `validators.ini` is a localhost fixture; derive
a current physical inventory from the verified topology rather than overwriting it.

Nodes 1–3 historically network-booted with NFS roots supplied by node 4. Node 4
booted from local SD and supplied DHCP/TFTP/NFS, DNS and the application edge.
Therefore recovery must include node 4 local boot/network access, tested installation
media and a way to recover nodes 1–3 when the existing network-boot services are
unavailable. No current out-of-band recovery has been confirmed.

The user explicitly confirmed that formal Institute ratification is not evidenced
for allocation, budgets, fees, bootstrap funding, operator compensation, commercial
bootstrap activation, signer/guardian appointments/custody or lost-quorum recovery.
The proposed five-of-seven recovery council and 30-day timelock are unapproved.
Current authority covers source development, review and release preparation only.
