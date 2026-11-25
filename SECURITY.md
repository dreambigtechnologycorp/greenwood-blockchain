# Security Policy

Greenwood's security depends on protocol validation, qualified runtimes, protected
credentials, durable state and operator recovery. Source tests and deployment
tooling do not constitute an independent audit or intended-host physical
acceptance. The [dated project status](docs/PROJECT_STATUS.md) records the release
boundary; a running service is not an acceptance certificate.

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
outside source archives. Operators control secret generation, provisioning and
activation. Public inventories can contain public keys/certificates and file paths;
private inventories, credential exports and some preflight checks can access
secret material. Review the [custody boundary](deploy/production/OPERATOR_KEY_CUSTODY.md)
before sharing files or granting automation access.

A role name does not confer authority. Additional providers do not become
validators, treasury signers or independent attestors. Hosted assignments and
network-system publishing have their own authorization checks.

Canonical SQLite and containerd state must use qualified durable storage. Current
source uses rollback journaling with `synchronous=EXTRA`; changing journal mode
alone does not make NFS safe for canonical state. Preserve signing journals and
policy rollback ledgers during migration and recovery. Follow the guarded storage
procedure rather than deleting a database or its sidecars to clear a failure.

 Preserve finalized authority, mTLS identity pins,
revocation freshness, allocation checks and disabled-write gates. An uncertain
transaction or provider start must be reconciled, not blindly repeated.

See [compute security](docs/compute-security-v1.md),
[provider operations](docs/provider-operations.md), and
[release preparation](docs/RELEASING.md).
