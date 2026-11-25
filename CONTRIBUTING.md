# Contributing

Greenwood welcomes focused fixes, documentation improvements and reproducible
bug reports. Read [current functionality](docs/CURRENT_FUNCTIONALITY.md) and
the [protocol specification](docs/GREENWOOD_PROTOCOL_V1.md) first.

## Development

Start with [developer setup](docs/getting-started/development.md), then the
[local quickstart](docs/getting-started/quickstart.md). Use the checked-in Composer
lockfile and runtime requirements; production qualification pins PHP 8.5.12 and
Wasmtime 48.0.1. Ordinary source archives contain generated protocol bindings.
Initialize pinned submodules only when regenerating them.

```bash
composer install
composer validate --no-check-publish
composer run test:source
composer run test:contracts
```

The [testing guide](docs/testing/overview.md) covers Python/Ansible, resource,
control-plane and native suites. The commands above require their development
prerequisites and disposable state; they do not target live nodes. Report checks
not run, runtime limitations and skips.

## Changes

Submit focused pull requests against `main` with a description, regression
coverage and relevant documentation. Do not combine formatting churn with a
behavior change. Describe compatibility impacts on persisted state, wire
formats, consensus, signing and recovery. Protocol changes require explicit
versioning and compatibility review; passing local tests is not sufficient.

Preserve generated-code provenance. Use the scripts under `scripts/` to
regenerate bindings and record tool versions. Commit dependency lock changes
only when intentional. Never commit credentials, private inventories, operational node state or private
acceptance records. Local output may live in ignored `build/` or `docs/acceptance/`;
reviewed public fixtures, reusable tests and acceptance procedures belong in source.
Do not force-add ignored custody or runtime files.

Write public guides around roles and responsibilities. Keep named machine layouts
in explicitly labeled reference profiles. Document source-enforced placement,
identity and eligibility constraints rather than promising an unsupported topology.
Separate joining an existing network from creating one. Preserve approved policy
and historical evidence bytes; use indexes to explain their scope and status.

## License and security

Contributions of original project code are submitted under the
[Apache License 2.0](LICENSE), unless explicitly agreed otherwise. Submit only
work you are authorized to contribute under its copyright and applicable patent
terms, including any necessary employer or contractor permissions.
Only contribute material you have permission to license. Preserve upstream
licenses and notices for third-party material; see [third-party notices](THIRD_PARTY_NOTICES.md).
Report vulnerabilities using [SECURITY.md](SECURITY.md), not public bug reports.
