# Unit Tests

Use the repository-local PHPUnit with Composer autoloading, from the repo root:

```sh
php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit
php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit/Crypto/ProtocolHashTest.php
php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit/ContractPayloadTest.php
```

The current tree includes canonical vectors, crypto, contract payload, compute,
provider, edge and control-plane unit coverage. Consult actual files under
tests/Unit instead of assuming a directory exists for every source namespace.
Some tests invoke local commands or runtime-dependent adapters; inspect prerequisites
before treating the entire directory as platform-independent.

Use test doubles through the implementation's interfaces and existing fixtures.
Wasmtime-backed integration belongs in tests/Contracts with FFI enabled.
Coverage collection additionally needs a compatible coverage driver and explicit
source filtering; no measured 100% coverage claim is made here.

See [testing overview](overview.md) for suite boundaries and isolation rules.
