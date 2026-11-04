# Security Policy

Greenwood is an experimental reference implementation. It is not represented
as independently audited or production-ready. Do not use it to secure funds
or sensitive workloads on the strength of source tests alone.

## Supported versions

Security fixes are developed on `main`. There is currently no maintained stable
release series, backport commitment, or guaranteed response time. Pin a reviewed
commit and read release notes before upgrading a node.

## Reporting a vulnerability

Do not publish exploit details, private keys, tokens, or live infrastructure
access in a public issue. Use the repository's **Security -> Advisories ->
Report a vulnerability** action if private reporting is available. If it is
unavailable, open a minimal issue requesting a private reporting channel,
without describing the vulnerability or including sensitive evidence.

Maintainers must enable private vulnerability reporting before announcing a
public release. No monitored security email address is claimed by this file.

Include the affected commit, component, expected and observed behavior, impact,
and a minimal reproduction against an isolated environment. Coordinate public
disclosure with maintainers; do not test against networks you do not control.

## Operational boundaries

Keep validator keys, signing ledgers, provider credentials and policy signers
outside source archives. Preserve finalized authority, mTLS identity pins,
revocation freshness, allocation checks and disabled-write gates. An uncertain
transaction or provider start must be reconciled, not blindly repeated.

See [compute security](docs/compute-security-v1.md),
[provider operations](docs/provider-operations.md), and
[release preparation](docs/RELEASING.md).
