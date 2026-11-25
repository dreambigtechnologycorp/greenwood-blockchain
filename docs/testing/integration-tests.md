# Integration Tests

Run PHPUnit classes through PHPUnit, not directly as PHP scripts:

```sh
php -d ffi.enable=true vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Integration
php -d ffi.enable=true vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Integration/TransferSafetyTest.php
php -d ffi.enable=true vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Integration/RecoveryTest.php
```

Current integration files cover chain foundation, transfer safety, recovery,
contract state/vectors, compute L1, provider capabilities, reassignment and mTLS.
Consensus tests are in tests/Consensus; native contract tests are in tests/Contracts.
Contract execution requires the compatible Wasmtime library and PHP FFI.
Transport tests may bind disposable loopback sockets.

Composer's `test:source` suite selects unit, integration, consensus, RPC, CLI,
operator and compute tests while excluding live containerd lifecycle tests.
The contract and native suites, standalone resource/control-plane checks and
physical acceptance are separate. Use [testing overview](overview.md) for their
entrypoints. Keep private acceptance outputs outside source; reusable procedures
are tracked under `deploy/`.

Tests must use disposable state, identities and ports. Do not point fixtures at
operational data directories or interpret a local fixture as physical acceptance.
