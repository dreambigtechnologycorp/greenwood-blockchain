# Candidate Greenwood meter

`meter.php /absolute/private/journal` consumes canonical JSON lines over a private
stdin pipe. Operations are `begin`, `checkpoint`, `finish`, and `bundle`; each is
bound to a commitment, and sampling operations contain a `sample` from the existing
`CgroupAccounting::observe()`. No remote API, containerd socket, private keys, shell
commands, or arbitrary host file reads are exposed. Failures return UNVERIFIED,
never synthetic zero usage. Closed segments survive restarts in SQLite FULL/WAL.
Use `SignedObservation` outside this process to sign explicitly nonpayable provenance.

The OCI recipe installs no dependencies. Its build argument must be an independently
reviewed digest-pinned image with PHP, Sodium, PDO SQLite and POSIX already installed.
No image has been built, approved, distributed or started by this source change.
Production uses the explicitly consented private persistent journal mount and Unix
socket transport. The existing provider collector reads host cgroups; the governed
meter receives no containerd socket or arbitrary host filesystem access. Governed
installation, staging, rollback and audit source exists; actual OCI lifecycle
acceptance remains pending on the authorized four-node production destination.
See `../runtime/RESOURCE_WORKFLOWS.md` for reproducible source packaging and the
separate attestor/coordinator services.

CPU and raw block counters are provider-origin diagnostics. Memory peaks are bytes,
not time integrals. NAS/object/network/vendor accelerator collectors remain
unsupported and cannot become payable by sending JSON to this process. GPU/NPU
reservation evidence still requires actual allocation authority and qualified
availability, not the device's advertised model or TOPS.
