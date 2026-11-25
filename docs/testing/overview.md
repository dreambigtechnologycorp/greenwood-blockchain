# Testing Greenwood

Run tests from a development checkout with disposable identities, directories and
ports. Follow [developer setup](../getting-started/development.md) first. Tests may
create test-only signing keys; they must never use operational custody material.
The suites below are separate from a deployment inventory and activation approval.

## Choose the right layer

| Layer | Entry point | What it establishes |
| --- | --- | --- |
| Source | `composer run test:source` (`composer run test` is an alias) | Unit, integration, consensus, RPC, CLI, operator and compute behavior; excludes live containerd lifecycle cases |
| Contracts | `composer run test:contracts` | Restricted Wasmtime ABI, execution, gas/fuel, transactional effects and replay |
| Native provider | `composer run test:native` | Actual containerd behavior on a prepared disposable Linux host |
| Ansible regression | `python3 -m unittest discover -s deploy/ansible/tests` | Inventory/guard/template/helper behavior using local fixtures |
| Source packaging | `python3 -m unittest discover -s tests/Release` | Archive/release-manifest and packaging guards |
| Resources and hosted control plane | `scripts/verify-resource-release.py` | Resource accounting, funding, metering, policies and control-plane suites with revision-bound evidence |
| Physical acceptance | [Operator procedure](../../deploy/production/PHYSICAL_ACCEPTANCE.md) | Behavior on the intended hosts, including restart, ingress, funding and settlement |

Some source tests require Wasmtime/FFI or local loopback/Unix sockets. A platform
skip is not a pass for that feature. Native tests may create containers and
exercise mounts/cgroups; use a dedicated prepared test environment, never an
operational provider merely because its socket is reachable.

## Standard development checks

```bash
composer install
composer run test:source
composer run test:contracts
python3 tests/Fixtures/verify_vectors.py
python3 tests/Fixtures/verify_contract_vectors.py
python3 tests/Fixtures/verify_compute_vectors.py
python3 tests/Fixtures/verify_provider_content_vectors.py
```

For a narrow change, run the relevant PHPUnit file or suite through PHPUnit,
not directly as a PHP script. Examples are in [unit tests](unit-tests.md) and
[integration tests](integration-tests.md). `phpunit.xml.dist` is the authoritative
suite membership list.

The Python/Ansible environment must include Ansible, PyYAML and Jinja2; see
[setup](../getting-started/development.md). Record actual versions for qualification:

```bash
python3 --version
ansible --version
python3 -m unittest discover -s tests/Release
ANSIBLE_LOCAL_TEMP=/tmp/greenwood-ansible-tests python3 -m unittest discover -s deploy/ansible/tests
```

These regressions may execute helper commands against temporary fixtures. They
do not constitute a live-node Ansible preflight or prove installation idempotency.

## Resource and control-plane qualification

The qualification driver includes the standalone economics, registry-auth and
controller tests, JavaScript resource/signing tests, and syntax checks. Node.js
is required for its JavaScript tests. Use a **new** output directory each time:

```bash
python3 scripts/verify-resource-release.py \
  --output-dir build/qualification-local
```

For release evidence, run from the final clean committed revision and include the
certified-history coverage:

```bash
python3 scripts/verify-resource-release.py \
  --require-clean --certified-history \
  --output-dir build/qualification-release
```

The second command rejects uncommitted/untracked source inputs and records the
commit. It does not build OCI artifacts or replace the PHP, Ansible, native or
physical suites above. Follow [release preparation](../RELEASING.md) and
[production packaging](../../deploy/production/README.md) to bind all required
evidence, architectures and immutable artifact digests.

For focused hosted changes the standalone controller entrypoints include:

```bash
php system-services/deployment-controller/tests/orchestration.php
php system-services/deployment-controller/tests/signed_policy.php
php system-services/deployment-controller/tests/distribution.php
php system-services/deployment-controller/tests/edge_admission.php
```

Those scripts are direct executables, unlike PHPUnit test classes. The separate
Python application CLI has its own tests and build prerequisites; results here
do not qualify that repository automatically.

## Evidence and maintenance

Record the exact revision, tool versions, commands, exit results and skips. Keep
private operational output outside source; reusable regression tests and physical
acceptance procedures are tracked. Ignored `build/` and `docs/acceptance/` may hold
local output, but must not be force-added with inventories, credentials or state.

Prefer assertions of canonical bytes, state transitions, conservation and failed
unauthorized operations. Cover malformed input, uncertain starts, missing
certificates, stale policies and interrupted storage migration. A source test,
a native runtime test and independent measurement of hardware are different
claims. See [project status](../PROJECT_STATUS.md) for the currently recorded
release boundary rather than copying a historical test count into new evidence.
