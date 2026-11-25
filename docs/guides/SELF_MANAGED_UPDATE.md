# Review, test and update Greenwood yourself

These instructions prepare source and release artifacts for your selected service
roles or network. They do not authorize a production reset. Start with
[role-based onboarding](../operations/ROLE_BASED_ONBOARDING.md) to distinguish
participation in an existing chain from a new network. [Project status](../PROJECT_STATUS.md)
records unreleased source changes separately from the reference deployment.
Review and qualify those changes before treating any earlier artifact as current.

## Review your local changes

Run from the Greenwood PHP repository:

```sh
git status --short
git diff --stat
git diff --check
git diff
```

Also review new untracked files: Git diff does not show their contents. Keep keys,
protected machine configuration, SQLite journals, raw preflight evidence and
runtime state outside version control. Preserve unrelated local changes and
submodule work. Nothing in this guide requires pushing or opening a PR.

## Install development dependencies

Use Linux, PHP 8.5 with Sodium, PDO SQLite, FFI, PCNTL and POSIX, Node.js 22 or
newer, Python 3 and Composer. The physical release builder additionally enforces
ARM64, PHP 8.5.12 and its declared native extensions. Match the pinned Wasmtime
C library in the runtime instructions; a missing library is not a passing test.

In a checkout where `vendor` is absent or is a dependency directory:

```sh
composer install --no-interaction --prefer-dist
npm ci --prefix sdk/javascript
```

If `vendor` is an existing regular file, stop and use a separate local source copy
for dependency installation and tests. Do not overwrite that file. Copy the full
source, generated containerd bindings, lockfiles and tests into that copy.

## Run the source checks

Set `GREENWOOD_WASMTIME_LIB` to the absolute path of the installed, pinned
`libwasmtime.so`. Build the pinned Application Registry fixture before the certified hosted-history
suite:

```sh
npm ci --prefix contracts/application-registry-next
export GREENWOOD_ASSEMBLYSCRIPT_ROOT="$PWD/contracts/application-registry-next/node_modules/assemblyscript"
sh contracts/application-registry-next/build.sh /tmp/greenwood-hosted-registry.wasm
```

See [resource workflows](../../system-services/runtime/RESOURCE_WORKFLOWS.md)
for the separate service packaging process.

```sh
php -d ffi.enable=true vendor/bin/phpunit --testsuite source,contracts
python3 scripts/verify-resource-release.py \
  --output-dir build/my-source-verification --certified-history
```

Choose a new output directory for every run. Inspect `verification.json`, including
individual exit codes and environment overrides. The resource runner covers all
three allocation candidates, explicit Epoch 0 trust, independent-attestation
settlement, recovery, metering, retention, private IPC and JavaScript/PHP signing.
The selected production allocation is 15/25/30/25/5. The other allocations are
regression fixtures, not production defaults. PHPUnit skipped tests and warnings
must be inspected separately; source tests do not establish physical qualification.

Useful focused checks during edits:

```sh
php tests/Economics/resource-telemetry.php
php tests/Economics/meter-retention.php
php tests/Economics/governance-recovery.php
php tests/Economics/meter-ipc.php
node tests/Economics/resource-client.mjs
```

## Review and build the release

The release builder archives **committed HEAD**, not your uncommitted working
files, and rejects dirty release inputs. Once you have reviewed the complete
change set and test evidence, create your own local commit with only the reviewed
paths. Publishing that commit is your separate decision.

On the required build platform:

```sh
RELEASE_VERSION=YOUR_REVIEWED_VERSION bash deploy/build-validator-release.sh
```

Replace the version placeholder. Follow [source releases](../RELEASING.md) and
[resource workflows](../../system-services/runtime/RESOURCE_WORKFLOWS.md) for
runtime pins, immutable system-service OCI packaging, checksums and attestations.
A source archive is not a qualified container image. Record exact source commit,
runtime inputs, OCI digests, test report and artifact hashes together.

## Configure and install when authorized

For a new network, use [release preparation](../operations/PRODUCTION_REBUILD.md),
the policy/topology templates under `docs/economics`, and
`php scripts/economics-cli.php --help` for the available candidate-generation
commands. Candidate generation does not create approved signing authorities.
For the reference network, selected decisions are recorded in the
[November 18 adoption](../economics/PRODUCTION_POLICY_ADOPTION_2026-11-18.md).
Other networks need their own approved values. An existing-chain participant
retains that network's trusted genesis/policy rather than creating a replacement.
Reuse existing authorized identities; never substitute test fixture keys.

Follow the [physical handoff](../../deploy/ansible/CONTROL_PLANE_HANDOFF.md).
Exact-target preflight, recovery readiness, approved public inputs and the chosen
installation/maintenance scope remain required. A fresh installer is only for
uninitialized targets. For a staged installation use its bounded continuation;
for maintenance use the runtime upgrade path. Do not run installation commands
merely because a source verification report passed.

## Metering and ongoing use

- [Resource metering](RESOURCE_METERING.md): collectors, allocation leases,
  operation receipts, signed telemetry and Unix IPC commands.
- [Retention and recovery](RETENTION_AND_RECOVERY.md): archives, backups,
  replay protection and governance recovery actions.
- [JavaScript customer integration](../../sdk/javascript/README.md): offer
  disclosure, explicit trust consent and signing without automatic submission.

Preserve accepted verification requirements through service renewal and restart.
Unavailable measurements require reconciliation; they are not zero usage or
permission to downgrade independent verification. Keep unsupported payable meters
disabled until their actual source, governance profile and hardware acceptance
are qualified.

Current validation scope is recorded in [project status](../PROJECT_STATUS.md).
The [November 16 source validation](SOURCE_VALIDATION.md) is retained historical
evidence, not qualification of later local changes.

For actual source composition and storage I/O, see
[adapter integration](SOURCE_ADAPTER_INTEGRATION.md).
