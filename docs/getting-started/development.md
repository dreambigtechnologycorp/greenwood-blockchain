# Developer setup

This workflow is for a **clean development checkout**. It does not use an operator
inventory, production keys, live node state or a network deployment command.

## 1. Prepare the toolchain

Install PHP 8.5 and Composer 2. The production runtime pins PHP **8.5.12**;
use that version when reproducing release behavior. CI requests **8.5.13** and
prints the installed patch version. Required Composer extensions
are Sodium, PDO SQLite and FFI. Provider/process tests also need POSIX and PCNTL;
live containerd integration additionally needs the native gRPC extension and
qualified Linux runtime. See [runtime requirements](../linux-execution-manifest.md).

Check the actual binary and extensions:

```bash
php --version
php -r 'foreach (["sodium", "pdo_sqlite", "ffi", "posix", "pcntl"] as $e) { echo $e, ": ", extension_loaded($e) ? "yes" : "missing", PHP_EOL; }'
composer --version
composer install
composer validate --no-check-publish
composer check-platform-reqs
```

Use `composer install` with the checked-in lockfile. Do not update dependencies
just to get a different local result. Source archives include generated containerd
bindings; initialize the pinned submodule only when working on their regeneration:

```bash
git submodule update --init --recursive
```

## 2. Supply the contract runtime

Contract execution and parts of the source integration suite require the
**Wasmtime 48.0.1 shared library** matching your OS and architecture. Use the
reviewed runtime artifacts and provenance procedure in
[production release preparation](../../deploy/production/README.md). A Wasmtime
CLI executable alone is not the shared library PHP loads.

Set `GREENWOOD_WASMTIME_LIB` to that verified library's absolute path, or use the
supported layout in `runtime/wasmtime/` documented by
[`WasmtimeConfig.php`](../../src/Contracts/WasmtimeConfig.php). Enable FFI for the
test process. Do not copy a different version into the expected filename.

```bash
# Point this at your already-verified, architecture-matching shared library.
export GREENWOOD_WASMTIME_LIB=/absolute/path/to/libwasmtime.so
php -d ffi.enable=true -r 'require "vendor/autoload.php"; $e = new Greenwood\Contracts\WasmtimeEngine(); echo $e->getVersion(), PHP_EOL;'
composer run test:source
composer run test:contracts
```

The constructor checks FFI loading; `getVersion()` reports the source's expected
version, not a native-library provenance check. The verified dependency checksum
and architecture must establish which library is actually loaded.

On supported macOS development hosts the library filename is `libwasmtime.dylib`.
Linux provider and native lifecycle qualification still require Linux.

## 3. Run the checks appropriate to your change

[Testing](../testing/overview.md) defines the suites and their side effects.
The source and contract suites use disposable identities and state; some tests
need local sockets. Native provider tests start disposable workloads on a
prepared test host and are a separate step.

For Python source/release checks, use Python 3. Ansible regression checks also
require Ansible, PyYAML and Jinja2 available in that environment. Keep development
tools in a dedicated virtual environment, separate from operator inventories:

```bash
python3 -m venv /tmp/greenwood-docs-dev-tools
. /tmp/greenwood-docs-dev-tools/bin/activate
python3 -m pip install ansible-core PyYAML Jinja2
python3 -m unittest discover -s tests/Release
ANSIBLE_LOCAL_TEMP=/tmp/greenwood-ansible-tests python3 -m unittest discover -s deploy/ansible/tests
```

These are developer tooling dependencies, not production image pins. Release
engineering records and qualifies the exact tool versions it uses. Do not replace
an existing qualified environment merely to follow this example.

## 4. Explore one path

- Run the [local four-validator fixture](quickstart.md), query finality and submit
  a disposable transfer.
- Compile and exercise a [WASM contract](../contracts/quickstart.md).
- Follow a transaction from `src/Transaction` through `src/State` to
  `src/Node/Chain.php`; use the [implementation inventory](../IMPLEMENTATION-INVENTORY.md).
- Read the [provider onboarding guide](../operations/ROLE_BASED_ONBOARDING.md)
  before testing real workloads or connecting a homelab.

The local fixture uses its own genesis and keys. Keep generated files in ignored
`local-genesis/`, `local-data/`, `build/` or a separate private test directory.
Never point a fixture at production paths.

## 5. Submit a reviewable change

Read [CONTRIBUTING](../../CONTRIBUTING.md). Include the user-visible behavior,
compatibility impact, documentation changes, tests run and any unavailable checks.
A role or protocol change must update the relevant guide and specification;
a local test does not approve a network deployment.
