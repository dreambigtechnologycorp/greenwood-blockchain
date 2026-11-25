# Greenwood Compute Security V1 (Pass 4F)

Reviewed November 24, 2026. This is the finite-compute V1 security profile and its
source-level controls, not certification of a host or every runtime path. Physical
Linux acceptance remains a separate gate; see [project status](PROJECT_STATUS.md)
and [runtime prerequisites](linux-execution-manifest.md). The requirements apply
to any operator deployment, independently of cluster size or node placement.

## Threat model

Arbitrary OCI workloads are hostile. They may attempt resource exhaustion
(fork bombs, memory/CPU saturation, output floods, disk writes), sandbox
escape (namespace/mount/ptrace attacks, capability abuse), host asset access
(signing keys, state databases, containerd socket, other workloads), and
network abuse. The execution path must contain all of these without weakening
receipts, scheduling, or reassignment semantics.

## Finite-workload sandbox controls (`OciSpecBuilder` + `ComputeSecurityProfile::V1`)

- All five capability sets empty; `noNewPrivileges = true`.
- The finite-workload path uses private pid/ipc/uts/mount/network/cgroup
  namespaces and no privileged mode. The shared builder also exposes
  `setHostNetwork()` and `addMount()` for system-service callers. Those APIs
  are not authority for an ordinary compute workload to use host networking
  or host mounts; review the specific caller and its consent/policy checks.
- Devices: exactly null/zero/full/random/urandom/tty, with a matching cgroup
  device allowlist.
- Baseline mounts: proc, /dev tmpfs, devpts, shm, mqueue, sysfs read-only.
  Finite compute adds no host bind mounts. Governed system services have a
  separately validated mount/host-access boundary and must not be described
  as having identical privileges to arbitrary customer jobs.
- Masked: /proc/kcore, /proc/keys, /proc/sched_debug, /sys/firmware, etc.
  Read-only: /proc/bus, /proc/fs, /proc/irq, /proc/sys, /proc/sysrq-trigger.
- Seccomp: default-allow with EPERM denylist for mount/umount2/pivot_root,
  ptrace, keyctl/add_key/request_key, bpf, perf_event_open, kexec_*,
  *_module, open_by_handle_at/name_to_handle_at, reboot, swap on/off,
  setns/unshare, acct, quotactl*, and the new mount API (fsopen…mount_setattr).
- Environment: explicit list only; provider process environment is never
  inherited.
- Resources: cgroup memory limit, pids limit, CPU shares + optional CFS
  quota/period hard ceiling, RLIMIT_NOFILE 1024.

## Network policy (explicit v1 choice)

Private network namespace with no external interface ⇒ **no connectivity**.
Container loopback is namespace-local; host loopback services (provider RPC,
validator RPC) are unreachable. This statement applies to finite V1 jobs. Hosted applications already use a
separate managed networking/edge path; governed system services can also have
explicitly reviewed networking. Neither changes the finite-job wire profile or
allows a customer request to select host networking.

## Digest enforcement

Execution uses only content resolved to an immutable digest
(`ResolvedImage`, sha256:<64hex>); the resolved digest must equal
`ComputeRequest.workload_digest` before launch. Input digests are verified
before execution; output facts are reconstructed and validated by the
supervisor (`ReceiptBuilder`), never trusted from worker-local files.

## Worker / containerd privilege boundary (honest statement)

The provider worker runs as a separate UID/GID and cannot reach signing keys
(supervisor-only signing). However, **containerd access is itself a
privileged runtime capability**: a compromised worker could abuse the
containerd API. This is a material privilege boundary requiring operator acceptance and
physical isolation checks. Do not treat a separate UID as containment of a
compromised process that can command containerd.

## Trust classes and security

Trust class (OPEN/TRUSTED/SOVEREIGN) affects **provider eligibility only**.
Every class executes under the identical sandbox baseline. SOVEREIGN does not
mean privileged containers.

## Known v1 limitations

- **No user namespace**: container uid 1000 maps to host uid 1000. It is
  unprivileged, but shares the host uid-1000 identity. Isolation depends on
  runc namespaces + dropped capabilities + seccomp.
- **Writable-layer bound**: the selected EROFS block-mode runtime must enforce
  a configured fixed-size writable layer. Validate exhaustion/ENOSPC behavior
  on the exact snapshotter, kernel and filesystem; selecting a profile is not
  proof of enforcement. A dedicated containerd filesystem provides a second
  host-capacity boundary. Unbounded overlayfs is not equivalent.
- **No insecure snapshotter fallback**: a provider configured for `erofs`
  that lacks a quota-capable snapshotter must fail readiness, never fall
  back to unbounded `overlayfs`.
- **Seccomp is default-allow**: the denylist covers known-dangerous kernel
  interfaces but is not a full sandbox policy.

## Operator requirements

- Run provider on a dedicated filesystem/partition (disk-abuse bound).
- Keep validator/provider signing authority and worker runtime authority
  separated. A provider joining a network does not acquire validator or
  treasury authority. Co-location needs an explicit assessment of the
  containerd privilege boundary and shared storage; machine count alone
  establishes neither isolation nor independent administration.
- Do not run the provider worker as root.

## Linux-only acceptance

Namespace/cgroup/seccomp/runc acceptance is Linux-only and must run against the
intended operator profile. Source/unit checks on another OS are not physical
security acceptance. Include digest/input enforcement, resource exhaustion,
network isolation, restart cleanup, storage failure and stale-funding behavior.
Customer-funded operator-attested service authorization does not qualify
independent metering or permit a weaker workload sandbox.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](README.md) · [Compute protocol v1](GREENWOOD_COMPUTE_V1.md) · [Provider operations](provider-operations.md)
<!-- /docs-nav -->
