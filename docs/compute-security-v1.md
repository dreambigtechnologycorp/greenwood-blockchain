# Greenwood Compute Security V1 (Pass 4F)

Status: spec-level controls frozen; Linux runtime acceptance pending on greenwood-dev.

## Threat model

Arbitrary OCI workloads are hostile. They may attempt resource exhaustion
(fork bombs, memory/CPU saturation, output floods, disk writes), sandbox
escape (namespace/mount/ptrace attacks, capability abuse), host asset access
(signing keys, state databases, containerd socket, other workloads), and
network abuse. The execution path must contain all of these without weakening
receipts, scheduling, or reassignment semantics.

## Sandbox guarantees (OCI spec, `OciSpecBuilder` + `ComputeSecurityProfile::V1`)

- All five capability sets empty; `noNewPrivileges = true`.
- Private pid/ipc/uts/mount/network/cgroup namespaces. Host namespace sharing
  and privileged mode are **not representable** — the builder has no setters
  for them.
- Devices: exactly null/zero/full/random/urandom/tty, with a matching cgroup
  device allowlist.
- Mounts: proc, /dev tmpfs, devpts, shm, mqueue, sysfs read-only. No host
  bind mounts; the sole caller adds none.
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
validator RPC) are unreachable. A future workload profile may add isolated
outbound networking; it must be separately versioned.

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
containerd API. This boundary is accepted for v1 launch; a narrow execution
broker is future work. Do not overstate it.

## Trust classes and security

Trust class (OPEN/TRUSTED/SOVEREIGN) affects **provider eligibility only**.
Every class executes under the identical sandbox baseline. SOVEREIGN does not
mean privileged containers.

## Known v1 limitations

- **No user namespace**: container uid 1000 maps to host uid 1000. It is
  unprivileged, but shares the host uid-1000 identity. Isolation depends on
  runc namespaces + dropped capabilities + seccomp.
- **Writable-layer bound**: the `erofs` snapshotter enforces a fixed-size
  writable upper layer per execution (containerd
  `default_size`, currently provider-policy). A workload that passes the
  bound gets ENOSPC and its backing image cannot grow. An operator-dedicated
  containerd filesystem remains as a second, host-protection boundary.
- **No insecure snapshotter fallback**: a provider configured for `erofs`
  that lacks a quota-capable snapshotter must fail readiness, never fall
  back to unbounded `overlayfs`.
- **Seccomp is default-allow**: the denylist covers known-dangerous kernel
  interfaces but is not a full sandbox policy.

## Operator requirements

- Run provider on a dedicated filesystem/partition (disk-abuse bound).
- Keep validator and provider keys in separate UID-owned paths (already
  required by Pass 4C); co-located dev hosts are acceptable because OCI
  workloads never receive host mounts.
- Do not run the provider worker as root.

## Linux-only acceptance

Namespace/cgroup/seccomp/runc acceptance is Linux-only (greenwood-dev).
Darwin runs spec-level unit tests only; no simulated security acceptance.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](README.md) · [Compute protocol v1](GREENWOOD_COMPUTE_V1.md) · [Provider operations](provider-operations.md)
<!-- /docs-nav -->
