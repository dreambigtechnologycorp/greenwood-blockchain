# Network system services — candidate

> **Historical scope — reviewed November 24, 2026.** The distribution/installer/coordinator source gaps below were addressed in later source checkpoints. This does not establish successful physical installation.
> Read the [economics index](../README.md) for current source and policy precedence,
> and [project status](../../PROJECT_STATUS.md) for release/installation and qualification.

Native version-1 `resource_network` records use existing treasury policy governance,
not a second ledger. Records commit role, digest/source, command, configuration schema,
budget reference, revision, activation, grace and rollback fields. Local opt-in is
required and cannot authorize a different image. New definitions override unsafe
root/host-network defaults. Host collector access is separately reviewed.

The current source includes native policy and a definition adapter plus an unbuilt
meter OCI recipe. It does **not** include a complete distribution/updater agent,
durable installation/cutover reconciliation, reviewed writable journal mounting, or
attestor/coordinator OCI executables. The Ansible seed allowlist is unchanged.
These are engineering gaps, not physical qualification successes.

Ratify artifact distribution authorities, host permissions, schema ownership,
replica/health requirements, grace/rollback behavior, custody and compensation before
production installation. No automatic host privileges or treasury powers are granted.
