# Seven-node read-only capacity assessment — November 18, 2026

> Historical snapshot: the findings and commands below describe their dated
> source/infrastructure state, not the current installation procedure. Start with
> [project status](../PROJECT_STATUS.md) and [operator onboarding](README.md).
> Retain the recorded evidence and counts; do not repeat completed initialization,
> removal or credential-generation steps from this snapshot.

Status: **capacity snapshot and draft planning assessment, not production acceptance**.
Collected approximately 10:56–11:00 America/Chicago. No installs, reboots, service
restarts, mounts, configuration edits or activation. SSH agent authentication only,
forwarding disabled, previously verified host keys enforced. No private key contents
read. Normal SSH/audit logs may record these read-only sessions. Evidence is local at
`build/capacity-audit-2026-11-18/node-1.json` through `node-7.json`; scripts live alongside.

## Follow-up after network reboot and switch Extend disabled

At **15:37 America/Chicago on November 18**, node-6 reached SSH authentication
immediately with its existing verified host key. The prior SSH banner timeout did
not recur. Authentication failed with `Permission denied (publickey,password)`.
A comparison against node-4 also failed public-key authentication. The available
SSH agent listed only an RSA identity; the operator was asked to load the existing
cluster Ed25519 identity themselves. No private key file was opened.

After the operator loaded the existing cluster identity, the read-only audit
**succeeded at 15:43 America/Chicago**. Node-6 reports four cores, 7.62 GiB visible
RAM, 7.21 GiB available, 1,000-Mb/s full-duplex Ethernet and 48.7 °C. Five-second
CPU busy was approximately 0.10%, with no I/O wait in that sample; throttle flags
were `0x0`. Uptime was approximately 2 hours 8 minutes. This confirms current link
negotiation and reachability, not a sustained throughput or workload qualification.

The containerd unit reports `LoadState=not-found`; `containerd`, `ctr` and `runc`
were not found in the SSH command PATH. PHP exists at `/usr/bin/php` (version not
checked in this follow-up). This was the pre-installation state. The subsequently authorized runtime preparation
completed successfully; see the runtime update below. No production activation occurred.

Evidence: `build/capacity-audit-2026-11-18/node-6-after-agent-load.json` and
`node-6-runtime-status.json`. The failed earlier probes are retained. Other nodes'
measurements below remain the morning snapshot, not a simultaneous post-reboot
cluster sample.

## Observed hardware and operating state

All reachable boards identify as Raspberry Pi 4 Model B / ARM64, four cores each.
RAM figures are Linux-visible GiB; available includes reclaimable cache and is not a
production application reservation.

| Node | Cores | RAM GiB | Available GiB | Temperature | Wired link |
| --- | ---: | ---: | ---: | --- | --- |
| node-1 | 4 | 7.62 | 7.03 | 39.9 °C | 1000 Mb/s full duplex |
| node-2 | 4 | 7.62 | 7.13 | 51.6 °C | 1000 Mb/s full duplex |
| node-3 | 4 | 7.62 | 7.14 | 45.3 °C | 1000 Mb/s full duplex |
| node-4 | 4 | 3.70 | 3.27 | 70.6 °C | 1000 Mb/s full duplex |
| node-5 | 4 | 7.62 | 7.18 | 39.9 °C | 1000 Mb/s full duplex |
| node-6 | 4 | 7.62 | 7.21 | 48.7 °C | 1000 Mb/s full duplex (15:43 follow-up) |
| node-7 | 4 | 7.62 | 7.16 | 39.4 °C | 1000 Mb/s full duplex |

Node-6's earlier timeout and authentication failures are superseded by the successful
afternoon audit. All seven nodes now have observed hardware inventory: **28 physical
Pi cores and approximately 49.41 GiB visible RAM**, including node-4. Observation
times differ. Cloud vCPUs are not equivalent to Pi cores; no performance factor is
established by this audit.

Five-second CPU snapshots showed approximately 0.05–0.35% busy on nodes 1–3, 5 and
7; node-4 approximately 2.35% busy plus 0.51% I/O wait. Memory pressure averages were
zero. These are idle/preproduction samples, not load capacity measurements.
Node-4 I/O pressure `some avg10` was 3.29%, `full avg10` 2.54%; this does not by itself
establish a bottleneck or attainable throughput. Node-4 temperature was 70.6 °C and
69.1 °C on a follow-up read. No cooling or clock changes were made. Throttle query
worked on nodes 5, 6 and 7 (`0x0`); nodes 1–4 lacked accessible `/dev/vcio`, so their
throttle state is unknown and the device was not created.

## Running services versus planned services

Nodes 1–3, 5 and 7 ran containerd during the original audit; node-6 now also runs
containerd after its separately authorized runtime preparation. `systemctl list-units --all
'greenwood*'` returned no units on reachable nodes. Process summaries showed no
running application fleet. This does not prove every possible custom service name
is absent; it does establish that planned Greenwood production consumption has not
been measured under activation.

Node-4 runs Cloudflare, dnsmasq, TFTP and NFS-related services alongside the OS.
Its root filesystem is local SD, not NFS. Nodes 1–3, 5, 6 and 7 root on node-4 NFS.
The seven-node source template assigns:

- Nodes 1–3: validators/providers, observer/receiver, controller additionally on node-1.
- Node-4: protected gateway, netboot and shared storage infrastructure.
- Node-5: edge and OCI registry hosting.
- Nodes 6–7: application providers, observer/receiver.

These are source topology roles, not a claim that those future services are already
running. Do not count all idle node memory as app capacity or change placement
implicitly. The source provider defaults include a 60% CPU/memory fraction; that
is not proof of a live enforced application allocation.

## Storage and network constraints

All observed netboot roots reference the **same** node-4 `/dev/sda1` ext4 filesystem:
approximately 786.4 GiB total, 154.0 GiB used, **592.34 GiB available** in the morning
(592.26 GiB through node-6 in the afternoon). Count this
capacity once. The drive is USB attached; the kernel reports a rotational flag,
which can be misleading through a bridge. No media-type qualification or disk
throughput test was performed. Unmounted partitions and node-7's unmounted SD
storage are not included as usable application capacity; nothing was mounted.

The baseline cloud model provisioned **640 GiB** primary storage plus **200 GiB**
retained backup. Primary capacity alone exceeds current available NFS space. A
local copy on the same disk would not be an independent backup and would consume
additional capacity. The light model's 384 GiB primary capacity fits nominal free
space, but still needs growth, ledger, registry, logs and recovery allowances.
Cloud disk allocations were conservative assumptions, not measured app data; lower
explicit storage quotas may be suitable after requirements are known.

Node-4 and every other reachable node report 1-Gb/s full-duplex Ethernet. All NFS
clients share node-4's link and USB storage. 1 Gb/s is a nominal 125 MB/s line-rate
ceiling per direction, not measured application throughput. WAN bandwidth, Wi-Fi
uplink capacity, SSD/NFS latency and sustained concurrent writes were not tested.
No iperf, fio, stress workload, packet capture or broad filesystem scan was run.

## Can 25 apps fit?

**Not the baseline as currently sized and placed.** Baseline asks for 8.25 CPU units
and 33 GiB RAM before production infrastructure, plus more disk than is available.
The dedicated app placement now has verified hardware on nodes 6–7: approximately
**15.24 GiB total RAM**, before overhead. This remains less than the light model's
16.5 GiB. Node-6 runtime preparation has now passed, but workload execution and production
application-provider activation remain unqualified/unperformed.

For transparent sensitivity only, consider these **unenforced draft reserves**:
exclude node-4 and node-5 from app placement; reserve 3 GiB on each validator and
2 GiB on each application provider for OS/runtime/network services and headroom.
This leaves approximately:

| Placement scenario | Potential app RAM | Result |
| --- | ---: | --- |
| Node-7 alone (historical comparison) | 5.62 GiB | 25-app model does not fit |
| Dedicated nodes 6–7, runtime prerequisites prepared | 11.24 GiB | Even light 16.5-GiB model does not fit |
| Explicit app co-location on nodes 1–3 and 7 | 19.48 GiB | Light model fits arithmetically, little resilience |
| Hypothetical co-location on nodes 1–3, 6–7 | 25.10 GiB | Light fits arithmetically; baseline still exceeds it |

Those infrastructure reserves are judgment-based, not measured consumption under
production. Co-location requires deliberate placement changes and limits protecting
consensus. Losing a host, database spikes, image unpacking, backup jobs and rolling
updates need additional headroom. Arithmetic RAM fit does not qualify CPU, I/O,
placement fragmentation, reliability or metering. No co-location was enabled.

**Practical direction:** preserve node-4 and node-5's
infrastructure roles, and use a smaller measured pilot before admitting 25 apps.
Twenty-five lightweight low-traffic apps may become realistic with shared services,
tighter actual per-app limits and planned co-location; this audit cannot certify
that promise. Twenty-five apps at the baseline's 1-GiB-per-app plus 8-GiB shared
services are not a safe current commitment.

Next evidence requires authorized workload testing after prerequisites are ready:
production service RSS/CPU under finality and registry activity, representative app
p95 RAM, database and backup I/O, concurrent NFS/containerd behavior, deployment
headroom and host-loss behavior. None was activated for this read-only request.

## Subsequently authorized node-6 runtime preparation

Following the read-only audit, the operator explicitly authorized node-6 preparation
through Ansible while preserving networking guards, with no keys or production
activation. This later installation is separate from the original read-only audit.

The existing interrupted `initramfs-tools` transaction was finished under temporary
package restart suppression. The canonical `runtime_prerequisites.yml` then audited
all six runtime hosts and selected only node-6 for installation. Other audited nodes
reported zero changed tasks. Node-4 was not modified.

Verified PHP 8.5.12 with Sodium, PDO SQLite, POSIX, PCNTL, FFI and gRPC; containerd
2.3.7 client/server; runc 1.5.3; EROFS tools 1.7.1-1build2 and healthy EROFS snapshotter;
Wasmtime 48.0.1 checksum and FFI engine creation/deletion. Package audit is clean.
Networkd PID remained **654** across repair and installation, `RefuseManualStop=yes`
remains set, SSH stayed available and Ethernet remains at 1,000 Mb/s. The temporary
`policy-rc.d` restart suppression was removed.

Final read-only checks found no active Greenwood units and no containerd namespaces.
No signing keys were generated or accessed; SSH used the operator's existing agent.
No production services were activated. No container workload was executed in this
verification; this is runtime-prerequisite verification, not physical acceptance.

Installation recap: node-6 `ok=94 changed=21 failed=0 unreachable=0`; verification
`ok=2 changed=0 failed=0`. Local logs, agent-only inventory, exact selected versions,
repair/verification playbooks and checksums: `build/node6-runtime-2026-11-18/`.

Local toolchain note: automatic approval review rejected an unpinned remote pip
bootstrap. It was not executed. Ansible 2.19.4 was instead restored in an isolated
local environment using Ubuntu's repository-verified pip wheel package.
