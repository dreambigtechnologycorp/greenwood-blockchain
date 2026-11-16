# Greenwood production release preparation

The release target is the existing four-node cluster. Testnet ledger state is
retired, not migrated. This directory contains public templates only; fill private
inventory and operational configuration outside Git. No template authorizes a
reset, permanent genesis, treasury spending or registry publication.

## Build and identify the source

Review the source commit and run the qualification runner with `--require-clean`.
Use the pinned PHP/Wasmtime environment described in `docs/guides/SELF_MANAGED_UPDATE.md`.
The native suite requires the rebuilt hardware; skipped native tests are not passes.

```sh
python3 scripts/check-public-source.py
python3 -m unittest discover -s tests/Release
php -d ffi.enable=true vendor/bin/phpunit --testsuite source,contracts
python3 scripts/verify-resource-release.py --require-clean --certified-history \
  --output-dir build/release-qualification
python3 scripts/package-source.py HEAD --output-dir build/production-source
```

Commit/publish only reviewed source; never stage `.env`, private keys, database
state, raw physical inventories or generated build directories. Studio is excluded
from this release's publication scope.

## Build the reusable runtime and offline services

The builder is local rootless BuildKit through Buildx, with ARM64 execution under
QEMU on x86-64. `uidmap` and a working user namespace are prerequisites. Native
ARM64 qualification remains a separate acceptance step. Builder tools, dependencies
and resulting artifacts must be recorded by version and checksum.

`system-services/php-runtime/inputs.lock.json` pins the PHP ARM64 manifest,
libffi packages, Wasmtime 48.0.1 library/archive/license and SBOM generator. Upstream
registries are build-input sources only. Production pulls use Greenwood Zot.

```sh
python3 scripts/build-php-runtime.py --buildx /ABSOLUTE/PATH/docker-buildx \
  --builder greenwood-release --output build/production-runtime
```

The build executes PHP, Sodium signatures, SQLite queries, PCNTL and Wasmtime engine
loading, records extension versions and native-library dependencies, and emits an
OCI archive with SBOM and provenance. Inspect the build result and exact image
manifest before qualifying its digest. Do not invent a digest if the build fails.

Extract the verified runtime OCI layout to a private build directory. Set
`GREENWOOD_RUNTIME_LAYOUT` to that absolute layout directory and
`GREENWOOD_PHP_RUNTIME=greenwood-runtime@sha256:ACTUAL_RUNTIME_MANIFEST_DIGEST`.
The named build context prevents any dependency on a public production runtime.

```sh
GREENWOOD_BUILDX_BIN=/ABSOLUTE/PATH/docker-buildx \
GREENWOOD_RUNTIME_LAYOUT=/ABSOLUTE/PATH/VERIFIED_RUNTIME_LAYOUT \
GREENWOOD_PHP_RUNTIME=greenwood-runtime@sha256:ACTUAL_RUNTIME_MANIFEST_DIGEST \
  bash scripts/build-resource-services.sh build/production-services
```

This packages registry, registry-auth, meter, attestor and coordinator. All PHP
services use the same runtime. Registry uses the existing pinned Zot upstream.
`image-digests.json` distinguishes runtime image manifest digests from tarball and
OCI layout hashes. The controller/receiver/gateway services remain in the existing
source/systemd/Ansible deployment, not a replacement OCI architecture.

## Bootstrap the network registry offline

Before installing other services, independently verify the registry/auth archives,
source revision, checksums and the provisioned issuer public key. Install those
archives through the existing containerd/system-service infrastructure. Configure
the actual Zot HTTPS endpoint, certificate identity, bearer realm and issuer trust
using reviewed installation configuration. Do not use the Dockerfile example realm
as a verified production endpoint. Mirror approved runtime and service manifests
only after endpoint/TLS verification and publication authorization.

Application publishing authority does not authorize network service images.
`GREENWOOD_SYSTEM_PUBLISHER_POLICY` opts registry-auth into an explicit protected
policy with an authorization-document hash, chain/genesis, expiry and exact
publisher-key/service grants. Default policy is disabled. Repositories are
`chains/CHAIN/system-services/SERVICE`; application repositories remain separate.

An explicitly provisioned publisher signs a short-lived request:

```sh
php scripts/system-service-publish-sign.php /PRIVATE/policy.json metering \
  /PRIVATE/publisher.key > /PRIVATE/request.json
```

Submit it to the verified auth origin's `POST /system/token`. The request domain is
`GREENWOOD_SYSTEM_SERVICE_PUBLISH_V1`; signatures bind policy hash, repository,
actions, nonce and expiry. Replay, expired policy, app-path reuse and missing grants
are rejected. The returned bearer token grants only that repository for at most
120 seconds. Use it with an authenticated OCI distribution client; never log it or
substitute an application session. The registry issuer key is provisioned offline;
no release script generates or accesses production signing identities.

## Generate installation inputs and gate activation

Copy `release.template.json`, the economics production policy/topology templates
and the disabled publisher policy into your private configuration directory.
Use `economics-cli.php prepare-production-genesis` only with ratified policy and
independently provisioned public identities. It produces a candidate, not activation.

```sh
python3 scripts/prepare-production-release.py /PRIVATE/release.json \
  --source FULL_COMMIT_SHA --images build/production-services/image-digests.json \
  --qualification build/release-qualification/verification.json \
  --output build/production-release-manifest.json
```

The output hashes configuration/evidence and lists all unresolved gates. Source
support alone does not qualify a payable profile: each requires exact resource,
billing basis, meter version, approved evidence digest and explicit trust policy.
Independent profiles cannot accept operator-only evidence; bootstrap has zero
subsidy authority. Leave unsupported profiles absent/disabled.

Formal allocation ratification (15/25/30/25/5), spending limits, fees, bootstrap
funding, related-party rules, signer custody and lost-quorum recovery remain
unresolved until approval evidence is recorded. Hardware recovery access and
explicit destructive-installation authorization remain mandatory.

See `PHYSICAL_ACCEPTANCE.md` for post-install execution and evidence requirements.
