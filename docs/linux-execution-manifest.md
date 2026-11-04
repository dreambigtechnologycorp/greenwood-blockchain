# Linux Execution Requirements

This is the current prerequisite checklist, not a certified runtime bill of
materials. Record the exact approved artifacts for the intended installation.

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

## Containerd Configuration

Use a reviewed configuration for the exact containerd build. Validate it with
`containerd --config /etc/containerd/config.toml config dump` before a planned
restart. An old example TOML is not proof of compatibility. Preserve unrelated
runtime settings and storage. Configure worker socket GID persistently; one-off
`chgrp` does not survive socket recreation.

On disposable/new hardware, acceptance must prove socket ownership across daemon
restarts and healthy EROFS transfer/unpack plugins. Merely loading the module or
enabling a snapshotter is insufficient. Restart checks require maintenance approval.

## Entrypoints

From the installed approved source tree, the node uses `bin/greenwoodd` with
`--genesis` and `--data-dir`; validator operation additionally requires its own
`--node` and `--key`. The provider uses `bin/greenwood-provider --config=...`.
Private operator activation requires both `--operator-socket` and
`--operator-token-file`. Do not start a second process beside an existing service.

Control-plane units are staged by Ansible, then activated only in the documented
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
