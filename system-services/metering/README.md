# Candidate Greenwood meter

`meter.php /absolute/private/journal` consumes canonical JSON lines over a private
stdin pipe. Operations are `begin`, `checkpoint`, `finish`, and `bundle`; each is
bound to a commitment, and sampling operations contain a `sample` from the existing
`CgroupAccounting::observe()`. No remote API, containerd socket, private keys, shell
commands, or arbitrary host file reads are exposed. Failures return UNVERIFIED,
never synthetic zero usage. In the working checkout, closed segments use the
shared SQLite rollback-journal policy (`DELETE`/`EXTRA`). Existing WAL databases
require [stopped-service migration](../../deploy/ansible/STATE_STORAGE.md).
Use `SignedObservation` outside this process to sign explicitly nonpayable provenance.

The private Unix-socket interface is
`php system-services/metering/meter.php /absolute/private/journal --unix`; the
socket is `meter.sock` with mode 0600 and exclusive journal ownership. These are
operator-local entrypoints, not public network services.

The OCI recipe installs no dependencies. Its build argument must be an independently
reviewed digest-pinned image with PHP, Sodium, PDO SQLite and POSIX already installed.
No image has been built, approved, distributed or started by this source change.
Production uses the explicitly consented private persistent journal mount and Unix
socket transport. The existing provider collector reads host cgroups; the governed
meter receives no containerd socket or arbitrary host filesystem access. Governed
installation, staging, rollback and audit source exists; actual OCI lifecycle
acceptance is separate from source implementation on each approved destination.
See [project status](../../docs/PROJECT_STATUS.md) for current evidence and
[resource workflows](../runtime/RESOURCE_WORKFLOWS.md) for source packaging and the
separate attestor/coordinator services.

CPU and raw block counters remain provider-origin observations. Memory peaks are
bytes, not time integrals. The universal telemetry path additionally accepts
signed, scoped allocation and instrumented service observations. It supports
network, NAS/object/backup and accelerator reservation accounting without treating
operator assertions as independent hardware proof. Missing adapters remain
unavailable; all catalog entries remain disabled until qualified and governed.

Use [the metering guide](../../docs/guides/RESOURCE_METERING.md) for the
`resource-bind`, `resource-append`, `resource-close`, `resource-evidence` and
`resource-archive` protocol and collector commands. See
[retention](../../docs/guides/RETENTION_AND_RECOVERY.md) before pruning hot records.
