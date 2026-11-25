# Documentation map and maintenance policy

Greenwood documentation is maintained by authority, not by an aspirational
coverage percentage. The current source and the versioned protocol documents
are the required references for behavior.

## Authority order

1. `GREENWOOD_PROTOCOL_V1`, `GREENWOOD_WASM_ABI_V1`, and
   `GREENWOOD_COMPUTE_V1` define compatibility behavior.
2. `src/` and checked-in tests define the current PHP reference implementation.
3. `rpc-v1.md` and `operator-api.md` define the supported integration surfaces.
4. Guides and examples explain how to use those boundaries; they must not
   invent commands, endpoints, state tables, or consensus rules.

## Coverage map

| Domain | Primary reference | Implementation anchor |
|---|---|---|
| Canonical protocol | [Protocol v1](GREENWOOD_PROTOCOL_V1.md) | `src/Codec`, `src/Crypto`, `src/Transaction`, `src/Block`, `src/Consensus` |
| Chain persistence | [Chain history](architecture/chain-history.md) | `src/Node/Chain.php`, `src/Persistence` |
| Logical state | [State model](architecture/state.md) | `src/State`, `src/Persistence/StateSqlite.php` |
| Contract runtime | [WASM ABI v1](GREENWOOD_WASM_ABI_V1.md) | `src/Contracts` |
| Compute | [Compute protocol v1](GREENWOOD_COMPUTE_V1.md) | `src/Compute`, `src/Provider` |
| Public API | [Public RPC v1](rpc-v1.md) | `src/Rpc/HttpApi.php` |
| Operator API | [Operator API v1](operator-api.md) | `src/Rpc/OperatorApi.php` |
| Deployment | [Deploy README](../deploy/README.md) | `deploy/` |
| Verification | [Testing overview](testing/overview.md) | `tests/` |
| Hosted applications | [Current functionality](CURRENT_FUNCTIONALITY.md) | `system-services/deployment-controller`, `src/Edge` |
| Registry authorization | [Registry issuer](../system-services/registry-auth/README.md) | `system-services/registry-auth/src` |
| New-node staging | [Physical handoff](../deploy/ansible/CONTROL_PLANE_HANDOFF.md) | `deploy/ansible/roles/control_plane`, provider runtime preflight |

Current guides distinguish source behavior, protocol compatibility and required
physical validation. [Project status](PROJECT_STATUS.md) records release progress;
[role-based onboarding](operations/ROLE_BASED_ONBOARDING.md) is the entrypoint for
a homelab operator. Named host layouts are supported examples, not general
participation requirements.

## Operating Reference Set

| Process | Current guide |
| --- | --- |
| Local development and nonce-safe transfer example | [Getting started](getting-started/overview.md), [quickstart](getting-started/quickstart.md) |
| RPC client configuration | [CLI](cli.md) |
| Runtime and worker configuration | [Linux requirements](linux-execution-manifest.md), [provider operations](provider-operations.md) |
| Troubleshooting and uncertain-start recovery | [Troubleshooting](provider-operations.md#troubleshooting) |
| Restore confirmed-running application tasks after reboot | [Application recovery](../deploy/application-recovery/README.md) |
| Playbook selection and side effects | [Deployment](../deploy/README.md) |
| Inventory, secrets, runtime artifacts, policy SFTP, HTTPS and activation | [Control-plane operations](../deploy/ansible/CONTROL_PLANE_HANDOFF.md) |
| Local tests versus intended-host acceptance | [Testing](testing/overview.md) |

Reports and evidence are excluded from this operating reference set. Updating
guides does not modify evidence, authorize deployment or establish passing
installation results. The current instructions contain only supported processes;
no report should be substituted for them.

## November 24, 2026 audit

Reviewed every one of the **163 tracked entries in `docs/`** present at the start
of this audit, plus root documentation, related deployment/service guides and the
new onboarding/index pages. The [file-by-file audit record](DOCUMENTATION_AUDIT_2026-11-24.json)
lists the reviewed content, final hashes, preserved artifacts and validation
limits. Ignored operational outputs, credentials and third-party dependency trees
are outside this source-documentation audit.

| Area | Review and changes |
| --- | --- |
| Root README and contribution/security/release guides | Role-first navigation, reproducible developer entrypoints, explicit source versus release versus installation status |
| Getting started and user guides | Separate local fixture, application development, existing-network participation and network creation |
| Architecture, protocol, CLI and RPC | Correct commands, response shapes, envelope types, quorum, persistence and implemented runtime boundaries against source |
| Contracts | Replace two Markdown-disguised `.wat` files with valid runnable WAT examples; correct quickstart commands |
| Operations and deployment | Role dependencies, provider registration/funding, exact profile limitations, staged recovery, storage continuation and credential boundaries |
| Economics | Separate protocol invariants and current funding rules from the initial operator's adopted policy and historical proposals |
| JSON templates and evidence | Parse/review all 60 tracked economics JSON files; verify intentionally incomplete policy templates reject and the disabled catalog remains disabled |
| Custody PDFs | Synchronize the CA guide with its Markdown source; add a current-scope/correction page to the historical key-creation guide while retaining original commands |
| Legal and third-party notices | Review navigation/provenance references; preserve license, attribution and trademark terms unchanged |

### Verification performed

- Local Markdown file/heading links and fenced-block balance across the full
  first-party reference set; whitespace validation with `git diff --check`.
- Current-source CLI/RPC regressions: **17 tests, 184 assertions** using existing
  Composer test dependencies and a temporary loader for the working source.
- Both WAT files and the inline quickstart module compiled with WABT 1.0.37,
  passed the current PHP ABI/policy checks, and passed a local WebAssembly host
  fixture's return/storage/error behavior checks.
- Zero-reserve-budget checks, customer-funded-program boundary checks, independent
  protocol vectors and 12 contract vectors passed.
- Both PDFs rendered and visually reviewed; all 13 CA-guide code blocks match
  the Markdown, and all 14 original custody pages retain their command text.
- Digest-bound adopted governance documents and all historical JSON evidence
  retained their bytes. Historical prose received scope notices where appropriate.

### Limits of this review

This is not the complete final release qualification. Native Wasmtime execution
of the repaired examples was not rerun because a compatible Linux shared library
was unavailable in the inspected development runtime locations. Node WebAssembly
fixtures and ABI checks do not replace that native-runtime test. Live nodes,
private inventories, secret material, external routing and physical acceptance
were not inspected by this documentation pass. No service was activated.

The initial operator supplied successful storage-migration results during the
audit; [project status](PROJECT_STATUS.md) records that report separately. Final
source review/qualification, immutable artifact rebuild and operator approval
remain prerequisites for deployment of the newly changed runtime.

## Maintenance checklist

When changing protocol-relevant code, update the relevant specification,
vectors/tests, source anchor, and public-facing guide in the same change. When
deprecating a page, replace stale instructions with a clear link to the
canonical reference rather than leaving plausible but invalid examples.

Preserve digest-bound adoption records and raw historical evidence. Label dated
prose clearly, link to the current rule or guide, and keep profile-specific names
out of general onboarding. When changing a PDF, synchronize its source or add an
explicit historical scope/correction notice, then render and inspect it.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](README.md) · [Implementation inventory](IMPLEMENTATION-INVENTORY.md) · [Testing overview](testing/overview.md)
<!-- /docs-nav -->
