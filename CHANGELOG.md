# Changelog

## Unreleased

This source revision includes changes after the `bd529a5` artifact baseline. These are
not yet a new qualified production release; see [project status](docs/PROJECT_STATUS.md).

- Guarded continuation for interrupted installation and storage preparation;
  recovery timers stay staged until explicit activation.
- Durable state placement and offline conversion to SQLite `DELETE` rollback
  journals with `synchronous=EXTRA`, retaining original state for review.
- Role-based contributor and homelab onboarding; reconciled protocol/API,
  deployment, resource and custody documentation. Named machine layouts are
  labeled reference profiles, not participation requirements.


## v0.1.0-alpha.1 - 2026-11-04

Experimental source-only prerelease. Native runtime binaries and deployment
bundles are not included.

### Included

- Fixed-validator BFT consensus, finalized block history, canonical transaction
  encoding and recoverable SQLite state.
- Wasmtime-backed deterministic contracts and finalized read-only contract queries.
- Public RPC and separate authenticated operator controls.
- Digest-pinned OCI provider execution, durable operations and reboot recovery.
- Registry authorization, signed physical assignments, admission and edge reconciliation.
- Guarded Ubuntu ARM64 fresh-cluster provisioning, PHP 8.5.12 runtime setup,
  protected identity staging and explicit hosting activation.
- Apache 2.0 licensing, DreamBIG attribution, a separate trademark policy,
  contributor guidance and source-only release packaging.

### Limitations

- Experimental software; no claim of independent security audit or production readiness.
- Fresh-node installation and physical acceptance remain separate from source tests.
- Terraform declares topology and inventory, not machine or OS provisioning.
- Installation requires genuine keys, trust material, signed policies, pinned
  artifacts and approved network configuration. It does not fabricate authority
  or automatically register applications or publish releases on-chain.
- The Python deployment CLI is a separate repository and release artifact.

A release tag and its immutable commit identify this prerelease. Physical
deployment validation remains separate from the source test results.
