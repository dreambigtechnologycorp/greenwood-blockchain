# Provider Operations

## Compute and Hosted Applications

The compute-job lifecycle below is distinct from hosted application execution.
`bin/greenwood-provider` also wires `ProviderApplicationAdapter` into the private
operator API for reserve/start/inspect operations. Optional `--application-listen`
requires `--application-peer`, `--operator-socket` and its protected token. This is
infrastructure access, not the developer controller endpoint.
`--application-network-policy` requires the operator socket and supplies protected
network/port allocation policy. Enabled controller writes require
`unix:///run/greenwood-provider/operator.sock`.

Reservation persists intended runtime/network identity before start. The controller
obtains matching receiver ACKs and edge admission before one start;
`START_UNCERTAIN` is reconciled by inspection, never a blind retry. Application
assignment `generation` is the canonical document SHA-256; `version` is its
increasing integer. These differ from compute attempt generations below.

Use the [physical handoff](../deploy/ansible/CONTROL_PLANE_HANDOFF.md) for new
installation. Runtime versions, checksums, matching containerd configuration and
an exact EROFS package version are mandatory inventory inputs. Restart commands
below are maintenance procedures, not permission to change the live deployment.

## Compute Supervisor

Run the supervisor on Linux as root, with a separate dedicated worker account.
Use PHP 8.5 with sodium, PDO SQLite, FFI, grpc, posix and pcntl, the installed
Composer dependencies, containerd with EROFS/runc, and `/usr/bin/setpriv`.
The PHP source/vendor tree and worker entrypoint must be readable by the worker.
Do not make them worker-writable.

Copy `config/provider.example.json` to a root-owned configuration file. Replace
chainId with the trusted genesis chain ID, configure the finalized node endpoint,
and set workerUid/workerGid to the numeric IDs of the dedicated runtime account.
The example IDs are placeholders, not a requirement to share the nobody account.
The existing 96-byte Ed25519 keypair file must be root-owned with mode 0600.
Optional expectedProviderId/expectedPublicKey are lowercase hex identity pins.
No private key is sent over RPC or included in worker JSON/environment.

Grant the worker group read/write access to the containerd Unix socket (0660).
Configure that socket group persistently in the containerd service; a one-off
chgrp is lost when the daemon recreates the socket. The supervisor readiness
check requires the socket group to match workerGid. Use the configured dedicated
worker UID/GID consistently. Worker execution uses
the minimal PHP launcher to close inherited descriptors with Linux close_range
while still root, then exec setpriv to change UID/GID, clear supplementary groups
and set no-new-privileges. The launcher requires libc close_range (Linux 5.9+ and
glibc 2.34+); unsupported hosts fail closed before worker execution.
Direct signing-key reads and inherited key descriptors are tested separately.
Containerd is a privileged runtime API; this model does not sandbox arbitrary
malicious replacement PHP worker code from the host through that API.

For disk-contained workloads, set provider `snapshotter` to `erofs` and merge
`config/containerd-provider.example.toml` into the existing containerd config.
Set `[grpc].gid` to the dedicated worker GID; this preserves socket access after
daemon restarts. Merge into the existing top-level `[grpc]` table — never add a
duplicate. Socket persistence acceptance requires the double-restart gate on the host:

```sh
getent group gw-worker   # numeric gid goes into [grpc].gid
sudo systemctl restart containerd
stat -c '%U %G %a' /run/containerd/containerd.sock   # root gw-worker 660
sudo systemctl restart containerd
stat -c '%U %G %a' /run/containerd/containerd.sock   # root gw-worker 660
sudo -u gw-worker test -r /run/containerd/containerd.sock -a -w /run/containerd/containerd.sock
```

Both restarts must yield `root gw-worker 660` and the worker identity must open
the socket unprivileged. A manual `chgrp` of the socket is never an acceptable
final state.
 Set EROFS `default_size = "256MiB"` and explicitly register its
native Transfer `unpack_config` with `differ = "erofs"` for the host platform.
Keep the overlayfs entry if other local workloads/tests require it. EROFS needs
the host kernel support, `mkfs.erofs`, and `mkfs.ext4`. Validate the merged config
and restart containerd during a maintenance window, then verify plugin health
and socket ownership. Enabling only the snapshotter does not enable Transfer
unpacking; without that entry image preparation fails closed.

The EROFS fixed-size writable backing filesystem bounds each task's rootfs;
overlayfs alone has no equivalent disk bound. Runtime snapshotter selection has
no fallback. The compatibility default in ProviderConfig remains overlayfs, so
operators must explicitly select the bounded profile as the example does.
This bound does not cover downloaded immutable image content, provider archives,
or local databases; provision and monitor their host storage separately.

```sh
sudo php bin/greenwood-provider --config=/etc/greenwood/provider.json \
  --operator-socket=/run/greenwood/operator.sock \
  --operator-token-file=/etc/greenwood/operator.token
sudo php bin/greenwood-provider --config=/etc/greenwood/provider.json --status
```

The operator socket (see [operator API](operator-api.md)) exposes local
status/jobs/runtime diagnostics and canonical drain/resume/capability
transactions. Create the token file (mode 0600, >=16 random bytes) beforehand.
The socket is created mode 0660; it is never a public listener. Canonical
drain/resume produce `TYPE_PROVIDER_CAPABILITY` transactions — they finalize
on-chain; the startup `--drain` flag remains a separate local emergency
control (`local_drain` vs `network_status` in operator status).

```sh
sudo php bin/greenwood-provider --config=/etc/greenwood/provider.json --status
sudo php bin/greenwood-provider --config=/etc/greenwood/provider.json --active
sudo php bin/greenwood-provider --config=/etc/greenwood/provider.json --recent=20
sudo php bin/greenwood-provider --config=/etc/greenwood/provider.json --job=REQUEST_HEX:1
```

Start with `--drain` to reconcile and settle existing jobs without launching new
assignments. Restart without it to resume intake. SIGTERM/SIGINT stop the
supervisor loop; independent workers retain their durable ownership and are
reconciled on restart. `--once` is a single orchestration tick, not a workload
completion command. Health reports a live, recently observed process; readiness
also requires validated chain access, runtime prerequisites and completed local
reconciliation. Pending/uncertain work keeps readiness false. These are local
operator views, never consensus state.

The v1 local input profile is a digest-pinned OCI image plus exact ash script
bytes. input_ref is either empty or canonical
`data:application/octet-stream;base64,...`; the decoded script is checked against
input_digest and passed as the workload's ash program. NUL-containing scripts
and other retrieval profiles are rejected locally. Arbitrary input URLs are not
fetched. Per-request resources must fit configured ceilings; CPU/memory/PID
ceilings also bound the aggregate of active jobs. FIFO order and maxConcurrent
control launch admission. Output overflow is hashed/drained and discarded beyond
bounded retention; it never triggers a workload kill by itself.

Data layout:

* provider.sqlite: durable jobs, immutable runtime/protocol IDs, receipts, exact
  transaction bytes and hashes, serialized outbox, worker ownership metadata.
* workers/RUNTIME/job.json: root-owned immutable IPC; run/ is worker-writable.
* archive/RUNTIME: completed ownership records and bounded facts, moved only
  after cleanup confirmation; no live runtime directory remains.
* output/: bounded retained output artifacts addressed by SHA-256.
* status.json: local health/readiness/job view; `/run/greenwood-provider/` holds
  identity ownership locks across data directories on the same host.

Generation 1 is the first finalized assignment. Only authorized L1 reassignment
creates the next generation. Restarts never create generations or rerun an
attempt whose start intent exists. Missing full-stream facts after a worker
crash produce FAILED_LOCAL after exact cleanup, never an invented receipt.
Archive metadata remains available for operator review and is not authoritative
chain state. Preserve it and provider.sqlite when restarting; do not wipe local
ownership to retry the same assignment.

Before HTTP submission, the exact signed Envelope and receipt are persisted.
On an uncertain response the lane queries finalized chain state and retries the
same bytes. It never substitutes a new nonce/signature. If another actor advances
the provider account nonce without settling that receipt, the lane remains
blocked for operator reconciliation. Do not share the provider signing account
with unrelated transaction senders. The host ownership lock cannot coordinate
independent hosts using copies of the same signing key.

Recovery deletes and checks only exact registered task/container/snapshot/lease
identities. A containerd outage keeps recovery pending. Resource accounting is
provider-attested cgroup CPU milliseconds and peak-memory bytes; duration uses
monotonic elapsed milliseconds. Missing post-start accounting prevents receipt
construction. Zero CPU/memory is reserved for a pre-task launch failure.

## Troubleshooting

Previously confirmed running hosted applications can be restored after task loss
using the [application recovery timer](../deploy/application-recovery/README.md).
It restores retained task identities and network bindings independently of the
deployment controller. Stopped applications and uncertain initial starts are not
automatically replayed.

| Symptom | Inspect | Required response |
| --- | --- | --- |
| TLS verification failure | Configured CA, certificate chain, expiry and endpoint SAN | Fix trust/endpoint configuration; never disable verification |
| Authenticated controller 403 | Signature audience/time, finalized authority, policy freshness and server dependency diagnostics | Reconcile the failed check; 403 is not proof the key is wrong |
| Global controller /ready returns 503 | Signed operation status and capabilities | Global readiness is hardcoded 503; do not infer operation failure from it |
| RESERVED operation | Exact allocation generation, both receiver ACKs, admission and scoped pull credential | Complete prerequisites and resume the same operation |
| START_UNCERTAIN | Persisted start intent and provider task/container/network identity | Inspect and reconcile; never issue a replacement start blindly |
| Expired policy | Publisher timer, signed snapshot expiry, SFTP host-key pin, consumer installer | Restore authorized renewal/distribution; preserve rollback ledgers |
| Socket/EROFS readiness failure | Persistent socket GID, runtime plugins, kernel support and version-matched config | Correct prerequisites in a maintenance window; no snapshotter fallback |
| Public 503 or wrong content | Verified backend, assignment generation, effective Apache route and release-bound assets | Reconcile or withdraw through the managed adapter; do not approve a live response by hashing it |
| Apache reload failure | Configtest, active process configuration and route journal | Verify effective routing before claiming publication or withdrawal |

Use existing service diagnostics and authenticated inspection. Do not print
tokens, private keys, signed request headers or secret configuration into evidence.
Keep operation journals and container ownership intact while diagnosing failures.

## Verification

Commands below require an isolated Linux environment:

```sh
sudo php vendor/bin/phpunit --do-not-cache-result --bootstrap vendor/autoload.php tests/Unit/Provider
sudo env GREENWOOD_WASMTIME_LIB=/path/to/libwasmtime.so php -d ffi.enable=true vendor/bin/phpunit --do-not-cache-result --bootstrap vendor/autoload.php tests
```

Physical acceptance scripts and results are local-only under `docs/acceptance/`.
Test-only checkpoint callbacks are supplied by regression test entrypoints;
the production CLI supplies none.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](README.md) · [Compute protocol v1](GREENWOOD_COMPUTE_V1.md) · [Compute security](compute-security-v1.md) · [Operator API](operator-api.md)
<!-- /docs-nav -->
