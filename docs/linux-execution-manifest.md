# Linux Execution Requirements

This is the current prerequisite checklist, not a certified runtime bill of
materials. Reviewed November 24, 2026. Record exact approved artifacts for the
intended operator installation. Requirements are role-based: a homelab provider,
validator, archive/RPC node and control-plane service need different permissions
and dependencies. See [project status](PROJECT_STATUS.md) for the repository's
release/qualification state; a historical cluster layout is not a network-wide
placement requirement.

## Runtime Inputs

| Component | Current requirement |
| --- | --- |
| PHP | Composer requires `^8.5`; control-plane preflight requires PHP 8.5 or later |
| PHP extensions | sodium, PDO SQLite, FFI for contracts; curl/openssl/posix for control plane; gRPC, pcntl and posix for provider operation |
| Wasmtime | Approved native library matching the contract runtime; supply its artifact/version provenance |
| containerd and runc | Explicit approved versions and ARM64 download SHA-256 checksums; no approved role defaults |
| EROFS | Exact `erofs_utils_version`, compatible kernel module, healthy snapshotter/mount-handler/differ and version-matched containerd configuration |
| Worker launcher | Linux `close_range`, glibc support and `/usr/bin/setpriv`; unsupported hosts fail closed |
| Other tools | `mkfs.ext4`, nftables, systemd, OpenSSL; OpenSSH for policy SFTP, Apache for frontends/edge |

Ansible requires `containerd_version`, `containerd_sha256`, `runc_version`,
`runc_sha256`, `greenwood_containerd_config`, and an exact EROFS package version
when EROFS is selected. Repository snapshot provenance and package availability
must be recorded separately. Do not substitute `latest` or a guessed minimum
version for a tested installation profile.

## Roles and admission

| Role | Required boundary |
| --- | --- |
| Non-validating node / archive / RPC | Trusted network genesis and endpoints; verified history/replay and durable state; no validator signing key needed |
| Validator | Existing genesis membership, its own signing authority and durable safety journal; provider registration cannot add consensus votes |
| Compute/hosted provider | Approved containerd/runc/EROFS profile, isolated worker, own provider identity, declared capacity, eligibility and finalized funding |
| Deployment controller / receiver | Protected deployment identity, registry authorization, committed assignment journal and authenticated operator endpoint |
| Meter / attestor / coordinator | Approved service definition and local consent, scoped evidence/IPC and declared trust policy; independent claims additionally need qualified separate control |

A compatible machine is not automatically admitted to every role. Follow
[operator onboarding](operations/ROLE_BASED_ONBOARDING.md),
[provider operations](provider-operations.md) and the
[economics policy/source map](economics/README.md). Customer-funded Epoch 0
services still need approved programs and funding. Provider joins do not unlock
subsidies or rotate the genesis-fixed validator/governance authorities.

## Ownership and State

Executable releases and all parent directories must be root-owned and not writable
by the worker or other users. Dedicated worker UID/GID are inventory/configuration
values; verify they identify the intended dedicated account on each host.

| Location | Purpose |
| --- | --- |
| `/etc/greenwood/control-plane/` | Root-owned 0700 directory; service settings and secret files 0600 |
| `/var/lib/greenwood-control-plane/` | Protected operation, assignment, admission and policy rollback state |
| `/etc/greenwood/system-services/` | Root-owned immutable registry/issuer definitions |
| `/run/containerd/containerd.sock` | Privileged runtime socket; persistent configured worker group access |
| `/run/greenwood-provider/operator.sock` | Authenticated local application operator endpoint required by enabled controller |

The dedicated SFTP public-bundle chroot is a deliberate root-owned 0755 exception
under private control-plane state; it contains only public signed output. Signing
keys and ledgers must never be placed there. See the [handoff](../deploy/ansible/CONTROL_PLANE_HANDOFF.md).

Paths above are role defaults, not a promise that an arbitrary mount is suitable.
Bind configured writable paths to approved durable storage and validate ownership,
mount identity, locking and restart behavior before startup. The November 24
source uses SQLite `journal_mode=DELETE` and `synchronous=EXTRA`; former
WAL databases require an explicit stopped-service migration. That source change
has not yet been incorporated into rebuilt immutable release artifacts. See
[storage operations](../deploy/ansible/STATE_STORAGE.md); do not copy/delete live
SQLite sidecars or treat an empty replacement directory as recovered state.

## Containerd Configuration

Use a reviewed configuration for the exact containerd build. Validate it with
`containerd --config /etc/containerd/config.toml config dump` before a planned
restart. An old example TOML is not proof of compatibility. Preserve unrelated
runtime settings and storage. Configure worker socket GID persistently; one-off
`chgrp` does not survive socket recreation.

On the intended installation profile, acceptance must prove socket ownership across daemon
restarts and healthy EROFS transfer/unpack plugins. Merely loading the module or
enabling a snapshotter is insufficient. Restart checks require maintenance approval.

## Entrypoints

From the installed approved source tree, the node uses `bin/greenwoodd` with
`--genesis` and `--data-dir`; validator operation additionally requires its own
`--node` and `--key`. The provider uses `bin/greenwood-provider --config=...`.
Private operator activation requires both `--operator-socket` and
`--operator-token-file`. Do not start a second process beside an existing service.

Role-specific service definitions and approved releases are staged by Ansible.
A joining operator uses the network's trusted genesis and public configuration;
creating a fresh chain is a separate network-creation procedure, not provider
onboarding. Control-plane units are activated only in the documented
dependency order after their protected configurations, trust material and current
policies validate. Setting a deployment boolean does not satisfy authorization.

## Validation Boundary

PHP/unit/template checks can run on a suitable development host. Actual provider
container execution is Linux-specific; macOS is suitable for the Python CLI and
some source tests, not a replacement physical provider. Linux SFTP/PAM, systemd,
Apache, kernel/EROFS and firewall validation belongs on the intended installation
profile. Fresh-node end-to-end and two-pass idempotency remain pending.

See [provider operations](provider-operations.md), [testing](testing/overview.md),
and the [physical handoff](../deploy/ansible/CONTROL_PLANE_HANDOFF.md).
