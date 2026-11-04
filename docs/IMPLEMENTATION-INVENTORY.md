# Implementation inventory

This inventory maps the Greenwood reference documentation to the current PHP
implementation. It is not a claim that every future protocol feature exists;
the v1 boundary is the scope described by the linked specifications.

| Capability | Primary source | Primary verification |
|---|---|---|
| Canonical reader/writer and protocol hashes | `src/Codec`, `src/Crypto/ProtocolHash.php` | `tests/Unit/Crypto`, `tests/Unit/GoldenVectorsTest.php` |
| Ed25519 account and validator identities | `src/Crypto/Ed25519KeyPair.php` | crypto and integration tests |
| Signed transaction envelopes and state transitions | `src/Transaction`, `src/State/State.php` | `tests/Integration/TransferSafetyTest.php`, contract/compute tests |
| Blocks, Merkle roots, and replay | `src/Block`, `src/Node/Chain.php`, `src/Persistence/BlockFilesystem.php` | recovery, replay, and audit tests |
| Fixed-set BFT and safety journal | `src/Consensus` | `tests/Consensus`, safety/liveness tests |
| Derived SQLite state | `src/Persistence/StateSqlite.php` | persistence and recovery tests |
| WASM contracts | `src/Contracts` | `tests/Contracts`, contract integration/audit tests |
| Compute state and scheduling | `src/Compute` | unit and integration compute tests |
| Provider supervision/containerd | `src/Provider`, `src/Compute/Containerd` | provider and containerd tests |
| Public and operator APIs | `src/Rpc` | RPC, CLI, operator, and acceptance tests |
| CLI and entry points | `src/Cli`, `bin/` | `tests/Cli/CliTest.php` |
| Physical deployment and signed assignments | `system-services/deployment-controller/src` | controller `tests/orchestration.php`, `distribution.php`, `signed_policy.php`, `edge_admission.php` |
| Hosted application reservation/networking | `src/Provider`, `src/Rpc/ProviderApplicationHttpApi.php` | `tests/Operator/ProviderApplicationHttpApiTest.php`, `tests/Unit/Provider/ProviderApplicationNetworkTest.php` |
| Hosted task restart recovery | `src/Provider/ProviderApplicationRestorer.php`, `deploy/application-recovery` | `tests/Unit/Provider/ProviderApplicationRestorerTest.php`; intended-host reboot acceptance |
| Managed edge and release content | `src/Edge`, controller `deploy/edge-reconcile.php` | `tests/Unit/Edge`, controller `tests/release_content.php` |
| Registry authorization | `system-services/registry-auth/src` | controller `tests/registry_authority.php` |
| Protected configuration and transport staging | `deploy/ansible/roles/control_plane` | `deploy/ansible/tests` |

## Executables

| Entry point | Purpose |
|---|---|
| `bin/greenwoodd` | Node daemon; requires `--genesis` and `--data-dir`; validators also provide `--node` and `--key`. |
| `bin/greenwood-provider` | Linux provider supervisor; requires `--config`. |
| `bin/greenwood` | Pure RPC operator/client CLI. |
| `bin/greenwood-cli` | Development key/genesis helper and local signer for supported envelope types. |

## Non-goals and boundaries

The validator set is fixed by genesis; certificate verification derives strict
`>2/3` quorum from its size (minimum three), rather than requiring exactly four.
The implementation does not turn
provider host state into consensus state, perform arbitrary off-chain input
fetching for workloads, or permit a contract to access WASI, filesystem,
networking, clocks, or host randomness. See the protocol documents for all
limits and compatibility requirements.

Hosted physical assignments are separate from finalized compute assignments.
Their signed generation approvals, receiver ACKs and runtime evidence do not add
new consensus records. See [current functionality](CURRENT_FUNCTIONALITY.md).

## How to verify

Run `composer run test` for the standard PHP suite, then the runtime-dependent
scripts described in [Testing](testing/overview.md). Verify a deployed Linux
provider through the [operations guide](provider-operations.md) and the
deployment verification assets rather than by manipulating its internal data.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](README.md) · [Architecture overview](architecture/overview.md) · [Testing overview](testing/overview.md)
<!-- /docs-nav -->
