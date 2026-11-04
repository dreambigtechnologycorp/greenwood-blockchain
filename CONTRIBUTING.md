# Contributing

Greenwood welcomes focused fixes, documentation improvements and reproducible
bug reports. Read [current functionality](docs/CURRENT_FUNCTIONALITY.md) and
the [protocol specification](docs/GREENWOOD_PROTOCOL_V1.md) first.

## Development

Use the runtime and extensions in the
[Linux execution manifest](docs/linux-execution-manifest.md). Infrastructure
provisioning targets PHP 8.5.12. Clone with submodules when regenerating protocol
bindings; normal source archives already contain the generated PHP bindings.

```sh
git submodule update --init --recursive
composer install
composer run test
python3 -m unittest discover -s deploy/ansible/tests
python3 -m unittest discover -s tests/Release
```

These commands are not a substitute for the platform-specific suites in the
[testing guide](docs/testing/overview.md). Contract tests require Wasmtime and
FFI; provider tests may require Linux and containerd. Report tests not run.

## Changes

Submit focused pull requests against `main` with a description, regression
coverage and relevant documentation. Do not combine formatting churn with a
behavior change. Describe compatibility impacts on persisted state, wire
formats, consensus, signing and recovery. Protocol changes require explicit
versioning and compatibility review; passing local tests is not sufficient.

Preserve generated-code provenance. Use the scripts under `scripts/` to
regenerate bindings and record tool versions. Commit dependency lock changes
only when intentional. Never commit credentials, node state or deployment
evidence. Local acceptance and audit material belongs in ignored
`docs/acceptance/`; maintained automated regression tests belong in source.

## License and security

Contributions of original project code are submitted under the
[Apache License 2.0](LICENSE), unless explicitly agreed otherwise. Submit only
work you are authorized to contribute under its copyright and applicable patent
terms, including any necessary employer or contractor permissions.
Only contribute material you have permission to license. Preserve upstream
licenses and notices for third-party material; see [third-party notices](THIRD_PARTY_NOTICES.md).
Report vulnerabilities using [SECURITY.md](SECURITY.md), not public bug reports.
