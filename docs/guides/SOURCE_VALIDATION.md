# Local source validation — November 16, 2026

This records validation of the local, uncommitted source changes. It does not
certify a published commit, built OCI image or physical installation.

| Check | Result |
|---|---|
| Resource release runner | 55 suites passed; 265 PHP syntax checks passed |
| Certified hosted history | 24,488 blocks replayed, real Application Registry WASM, selected 15/25/30/25/5 allocation and explicit Epoch 0 trust |
| PHPUnit source and contracts | 607 tests, 6,129 assertions, no failures; one runner warning, 20 deprecations, two skipped tests |
| Final metering retention checks | 11 passed after the binding/archive locking fix |
| Governance recovery | 34 passed |
| Universal resource telemetry | 65 passed across all 36 catalog definitions |
| Extended actual Unix IPC test | 15 passed, including process kill/restart and signed resource archive retrieval |
| Final JavaScript resource client | 15 passed |
| PHP/JavaScript signing compatibility | 32 passed |
| Final shell syntax, documentation links and whitespace | Passed |

The complete runner report is generated locally at
`build/source-completion-tests/verification.json`. That broad run preceded the
final binding-lock, SDK permission-validation and extended IPC-test changes;
focused affected checks were rerun afterward. PHPUnit ran in an isolated source
copy because this checkout's existing `vendor` path is a regular file. Its runner
warning concerned the existing `tests/Consensus/._RoundCatchupTest.php` artifact.
Skipped native/environment-dependent tests are not passing hardware tests.

The implementation includes signed scoped telemetry, Linux counter collectors,
allocation adapters, instrumented service-operation journals, durable evidence
retention, recovery policy enforcement and a local customer SDK. NAS/object/backup
services must integrate their operation receipts; allocator and vendor sources
must supply attributable observations. Generic adapters are not vendor hardware
qualification. No payable resource is enabled merely by this implementation.

Physical OCI execution, hardware qualification, production policy ratification,
key custody and installation remain separate work. There were no production
changes, Git commits, pushes or new pull requests during this source-completion
work. Unrelated existing submodule modifications were preserved.

Start with [self-managed updates](SELF_MANAGED_UPDATE.md) to review, test and
package your chosen source revision yourself.

Later adapter and Studio integration results are recorded in
[source scope status](SOURCE_SCOPE_STATUS.md); this earlier validation record is
retained to distinguish its exact test scope.
