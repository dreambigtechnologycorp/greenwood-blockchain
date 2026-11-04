# Integration Tests

Run PHPUnit classes through PHPUnit, not directly as PHP scripts:

```sh
php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Integration
php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Integration/TransferSafetyTest.php
php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Integration/RecoveryTest.php
```

Current integration files cover chain foundation, transfer safety, recovery,
contract state/vectors, compute L1, provider capabilities, reassignment and mTLS.
Consensus tests are in tests/Consensus; native contract tests are in tests/Contracts.
Contract execution requires the compatible Wasmtime library and PHP FFI.
Transport tests may bind disposable loopback sockets.

Composer's test script selects tests/Unit, tests/Integration and tests/Consensus.
It does not run every standalone provider or control-plane acceptance script.
Use [testing overview](overview.md) for those. Physical acceptance scripts and
records are local-only under ignored `docs/acceptance/`.

Tests must use disposable state, identities and ports. Do not point fixtures at
operational data directories or interpret a local fixture as physical acceptance.
