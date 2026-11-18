# Production policy and release review — November 18, 2026

[Operator policy adoption](../economics/PRODUCTION_POLICY_ADOPTION_2026-11-18.md) records the user decision; actual custody remains pending.
Selected input: `deploy/production/release.selected-2026-11-18.json`.

Current HEAD: `30b43586ad225db87efa4eebece5b6c370fccf70`, with uncommitted economics changes.
Existing services bind `0b0561c896b08c9cf993b5ca73f29c109ebef916`; they are not the final source release.
All six OCI archives passed hashes, descriptor checks, ARM64 architecture, source labels and SBOM/provenance bindings.
All three source archive SHA-256 checks passed.
Configuration results: [{"name": "release.json", "status": "match"}, {"name": "image-digests.json", "status": "match"}, {"name": "verification.json", "status": "missing"}].

| Existing artifact | Verified manifest digest |
| --- | --- |
| registry | `sha256:2011137cf26e610eda975cf4a72a1898827f2d7bec7ed565ef83dc4f4cd54953` |
| registry-auth | `sha256:ac5c122b158f5c090fbe93534521120350c661b7084ff7496017375cf0b25df3` |
| metering | `sha256:c266778a4317baa1b5a9591e75bd31fe1e62254e945837fd62cdc39abc20810e` |
| attestor | `sha256:4e2821f28c9ef71a6eef11c2f527cf4240768ba3b613329606687c3e499d2572` |
| metering-coordinator | `sha256:9ae2ae8c3f910e24d2b9958544f569b1e7196894c9c385fb421b1e243f299eb9` |
| runtime | `sha256:feb908be41dc118121c864c1c361d0b7d3249304a5268f33f823006303a26379` |

## Pending gates

- Finalize source revision and rebuild/qualify its artifacts; current working tree is dirty.
- Reconcile missing or mismatched configuration evidence reported above.
- Full final suite not rerun: signed tests generate temporary keys outside the current no-key boundary.
- Registry public DNS/TLS passed an unauthenticated curl HEAD check, but `/v2/` returned Cloudflare HTTP 502. Origin TLS, issuer and publisher authorization remain unverified.
- Operator-generated identities and actual custody completion remain pending.
- Operator will run physical acceptance after deployment; results remain unset.

Keyless tests passed: 80 zero-reserve, 63 additional customer-funded and 32 budget checks.
No keys generated, production activated, commits or pushes.
Verification of existing artifact integrity is not independent build trust, final release approval or physical qualification.
Evidence: `build/policy-release-review-2026-11-18/`.

## Follow-up release repair

The original qualification evidence was located under `qualification-0b0561c/verification.json` and restored to `configuration/verification.json` after matching the exact recorded hash. The operator authorized disposable test-only signing keys for final local qualification. Production keys remain operator-only. New bundles use `scripts/package-production-bundle.py` to prevent missing evidence paths. Historical findings above describe the initial inspection.
