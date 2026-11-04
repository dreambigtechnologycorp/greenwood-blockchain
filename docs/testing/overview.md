# Testing Greenwood

Greenwood's tests are organized by the boundary they protect. The ordinary PHP
suite validates protocol behavior without pretending that every runtime check
can run on every developer machine; native contract and provider checks are
called out separately.

## Standard suite

Install development dependencies, then run the Composer-defined suite:

```bash
composer install
composer run test
```

The `test` script selects the `source` suite in `phpunit.xml.dist`: unit,
integration, consensus, RPC, CLI, operator, compute value-object and mocked
containerd tests. Some source integration tests need Wasmtime/FFI and local
loopback or Unix sockets. Live containerd tests are excluded from this suite.
Use PHPUnit directly when narrowing to a specific file:

```bash
php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit/Crypto/ProtocolHashTest.php
php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Consensus/SafetyTest.php
```

## Runtime-dependent suites

Contract coverage invokes Wasmtime through PHP FFI:

```bash
composer run test:contracts
```

Environment-specific acceptance and verification scripts, their documentation,
and audit output belong in ignored `docs/acceptance/`. They are not shipped in
source checkouts or invoked by Composer. Review their environment and paths
before running any locally retained script.

Run live containerd tests separately with `composer run test:native`; this suite
requires a disposable Linux provider host and its runtime prerequisites. Read
[provider operations](../provider-operations.md) and the
[Linux execution manifest](../linux-execution-manifest.md) before running
native container lifecycle tests.

## Coverage map

Hosted application control-plane and Ansible checks are separate from Composer's
default suite. From this repository root:

```sh
php system-services/deployment-controller/tests/orchestration.php
php system-services/deployment-controller/tests/signed_policy.php
php system-services/deployment-controller/tests/policy_install.php
php system-services/deployment-controller/tests/distribution.php
php system-services/deployment-controller/tests/edge_admission.php
php system-services/deployment-controller/tests/release_content.php
ANSIBLE_LOCAL_TEMP=/tmp/h8-ansible-local python3 -m unittest discover -s deploy/ansible/tests
```

Ansible fixtures use local check mode and can need permission for a local RPC
socket. They do not prove Linux installation. The separate Python CLI repository's
`tests/test_cli.py`, `test_registration_gate.py` and `test_remediation.py` are
targeted local tests; full discovery also invokes registry WASM build/execution
fixtures and requires the PHP/AssemblyScript toolchains. Do not call full discovery
entirely mocked or infer physical acceptance from it.

| Area | Representative coverage |
|---|---|
| Codec and cryptography | canonical bounds, golden vectors, domain-separated hashing. |
| Chain and persistence | transfer safety, block limits, replay, state-root sensitivity, atomicity. |
| Consensus | signing safety, phase convergence, liveness, offline behavior, round catch-up, pacemaker durability. |
| Contracts | ABI validation, execution overlays, deterministic fuel, traps, host integration, replay. |
| Compute | request/receipt codecs, scheduling, provider capabilities, chain isolation, OCI specification security. |
| RPC and CLI | route validation, rate limiting, transport behavior, operator authentication, pure-RPC command behavior. |

## Test-writing rules

- Prefer protocol vectors and state-root assertions over snapshots of PHP
  objects or SQL rows.
- Keep a test's generated keys, paths, ports, and temporary runtime state
  isolated; never reuse a developer's node data directory.
- Exercise failure behavior as deliberately as success behavior: malformed
  bytes, conflicting signatures, missing certificates, and rejected runtime
  inputs should fail closed.
- For a cross-layer change, add coverage at the narrowest responsible layer
  first, then an integration test showing the composed behavior.

See [unit tests](unit-tests.md) and [integration tests](integration-tests.md)
for maintained regression commands and environment boundaries.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Testing overview](overview.md) · [Implementation inventory](../IMPLEMENTATION-INVENTORY.md)
<!-- /docs-nav -->
