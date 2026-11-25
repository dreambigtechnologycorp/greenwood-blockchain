# Greenwood production release preparation

For service participation in your own environment, use
[role-based onboarding](../../docs/operations/ROLE_BASED_ONBOARDING.md). This
directory covers network-release preparation and supported reference profiles.
[Project status](../../docs/PROJECT_STATUS.md) separates the current source/release
boundary from the reference deployment. Prior artifacts do not include pending
local startup, storage and SQLite durability fixes.

The selected reference release targets a seven-node composition; other operators
should use their network's approved roles and chain inputs. Its obsolete testnet
was retired without state migration. This directory contains public templates and
selected public inputs; fill private
inventory and operational configuration outside Git. No template authorizes a
reset, permanent genesis, treasury spending or registry publication.

See [operator-only key custody](OPERATOR_KEY_CUSTODY.md) before running any
provisioning or signing command. Release preparation does not generate keys.
Reuse existing matching identities; the reference operator has already provisioned
its credentials. Generation is only for genuinely missing or explicitly replaced identities.

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

The public topology template defaults to the seven-node layout: validators A/B/C
on nodes 1/2/3 and providers A/B/C/D/E on nodes 1/2/3/6/7. Node 4 remains the
gateway/netboot host; node 5 is edge/registry. The helper also accepts the original
three-provider A/B/C layout, but rejects incomplete four-provider sets and unknown
labels. Every validator/provider public key must be distinct and absent from the
retired identity list. Validators remain exactly A/B/C in either layout.

All provider bindings are returned in `provider_public_keys`; they do not add
validators, allocate tokens, register providers or change the genesis/state hash.
Retain that binding map with the deployment inventory. Validator consensus endpoints
use `http://HOST:PORT`, matching the node HTTP-over-TCP transport (the existing
cluster uses port 9201). Raw `tcp://` and `https://` peer URLs are not supported
by that transport. These internal peer URLs are separate from public HTTPS
frontends; do not disable TLS on registry or provider-facing HTTPS services. Five-provider preparation
does not waive missing governance, recovery, endpoint or retired-identity inputs.

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

For the selected reference policy, the [November 18 adoption](../../docs/economics/PRODUCTION_POLICY_ADOPTION_2026-11-18.md)
records allocation, spending, fees, bootstrap, related-party and recovery decisions.
Bind that exact evidence rather than treating those selected terms as unresolved.
Actual custody review, endpoint/service evidence and physical recovery remain
separate. A different network needs its own approved inputs. Destructive
replacement is a separate operation; it is not required for a runtime continuation.

See `PHYSICAL_ACCEPTANCE.md` for post-install execution and evidence requirements.

## November 18 selected policy and complete bundle

The operator adopted the latest Epoch 0 terms in
`docs/economics/PRODUCTION_POLICY_ADOPTION_2026-11-18.md`. Start from
`release.selected-2026-11-18.json`; it selects
`https://registry.greenwoodblockchain.com` and binds the policy adoption hash.
This selected file is a historical public input, not the current completed
installation manifest. Its unset fields must be reconciled with actual reviewed
public inputs; do not infer that existing identities need generation again.
The operator alone generates and provisions production keys. Disposable test-only keys are permitted
for local release qualification; they must never become production identities.

After committing reviewed source, building services and passing clean qualification,
assemble a self-contained handoff (new output directory required):

```sh
python3 scripts/package-production-bundle.py \
  --source build/production-source \
  --services build/production-services \
  --runtime build/production-runtime \
  --qualification build/release-qualification/verification.json \
  --config deploy/production/release.selected-2026-11-18.json \
  --output build/production-bundle
(cd build/production-bundle && sha256sum -c SHA256SUMS)
```

This re-verifies all OCI blobs and source archive hashes, copies the exact
qualification file to `configuration/verification.json`, checks every manifest
reference, and records remaining activation gates. It does not sign, upload,
install or enable services. An old runtime may be reused at its verified immutable
digest; the five service images and qualification must bind the new source commit.

The historical endpoint check returned Cloudflare 502; that result is not a
current health observation. If a new check returns 502, investigate origin
connectivity and service configuration. Before clients pull images, the operator must bootstrap Zot and registry-auth
from the offline archives, configure the reviewed issuer/public authorization policy,
and configure edge routing and origin TLS. Verify `/v2/` returns the expected registry
response (typically 401 with the correct bearer challenge before authentication),
then verify authorized and denied pushes. Public-edge TLS alone does not verify
origin TLS or publisher authority. Do not disable TLS checks to bypass this gate.

Deploy services with the prepared identities before running physical acceptance;
record its results afterward. An unset acceptance field is pending, not a failed
source build and not a reason to fabricate a pass. Permanent activation and payable
resource qualification retain their explicit gates.
