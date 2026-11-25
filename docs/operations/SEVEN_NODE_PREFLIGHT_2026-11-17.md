# Seven-node production preflight — November 17, 2026

> Historical snapshot: the findings and commands below describe their dated
> source/infrastructure state, not the current installation procedure. Start with
> [project status](../PROJECT_STATUS.md) and [operator onboarding](README.md).
> Retain the recorded evidence and counts; do not repeat completed initialization,
> removal or credential-generation steps from this snapshot.

This checkpoint records base infrastructure readiness, not production activation or
workload qualification. The read-only audit verified all seven machine identities,
ARM64 architecture, pinned SSH host keys and passwordless sudo. Private inventory
and raw evidence are retained outside tracked source under
`build/production-preparation/seven-node-2026-11-17/`.

## Completed infrastructure work

- Nodes 1–3 and 5–7 use `BOOT_ORDER=0xf12` and their own NFS roots on node-4's SSD.
  Node-4 retains local SD boot (`0x1`) and supplies DHCP, TFTP and NFS.
- Nodes 5–7 were copied from their own independently installed SD cards. Copy
  verification and preservation checks preceded staged boot configuration changes
  and publication. Their machine IDs and SSH identities were preserved for the
  same physical machines, not duplicated between machines.
- Dedicated serial-specific TFTP trees and MAC-specific DHCP reservations were
  installed. Each new NFS root is restricted to its client's reserved address.
  Firmware files were copied from each node's own boot partition; cloud-init
  provisioning files were excluded from TFTP. Boot configuration retrieval was
  tested from the cluster LAN, and actual NFS boot was subsequently verified.
- The original SD cards on nodes 5–7 were explicitly authorized for reformatting
  after successful network boot. Each now has one ext4 partition, remains
  unmounted and is not configured for storage use. They are no longer recovery
  OS images. Do not allocate them to containerd, applications or exports without
  a separate storage decision.
- Restored node-2's package-required `_rpc` system account after its reboot exposed
  a failed RPC service. Restored root ownership on node-4's NFS state directory,
  tracking directory and tracking database, with protected backups. No recursive
  ownership change was applied to exported roots or container snapshots.

## Observed readiness

| Hosts | Runtime observation | Boot/storage observation |
|---|---|---|
| Nodes 1–3 | PHP CLI 8.5.10 with gRPC/FFI; containerd 2.3.5; runc 1.1.12 | NFS roots; containerd data currently resides on those NFS roots |
| Node 4 | PHP CLI 8.5.12; no containerd/runc | Local SD root; shared SSD hosts six client roots |
| Nodes 5–7 | No PHP, containerd or runc installed | NFS roots; reformatted local SD cards unused |

The seven-node profile does not require containerd on node-4. Missing runtimes on
nodes 5–7 are installation prerequisites, not failed workload tests. Nodes 1–3 need
host-runtime reconciliation with the reviewed production pins; do not confuse
their PHP CLI version with the separately packaged PHP OCI runtime.

All seven hosts reported no failed systemd units, synchronized NTP, and
`throttled=0x0`. The spot temperatures were 44.3, 56.4, 50.6, 70.6, 40.9, 49.1 and
40.4 degrees Celsius for nodes 1 through 7 respectively. These are observations,
not sustained thermal qualification. Node-4 still requires cooling/load evaluation.

Cgroup controllers are present on all hosts. This does not prove delegated
container cgroups, workload attribution, storage durability, or payable metering.
Containerd reports usable plugins on existing provider hosts; real execution and
recovery must qualify the selected snapshotter and NFS-backed storage arrangement.

## Installation inputs and remaining gates

The protected inventory uses schema version 2 and `topology_profile: seven`, with
exact host-key/address records. Its Ansible draft retains all activation approvals
as false, preserves node-4 gateway services, and leaves production signing keys,
provider RPC TLS origins and other unresolved production inputs as placeholders.
It is not a runnable production installation approval.

The repository's SSH preflight completed with 7/7 verified identities. Recovery
access and production acceptance remain false. The Ansible installation preflight
has not been executed: Ansible is not installed in this workspace and independently
verified recovery remains unresolved. Do not set the recovery flag merely to bypass
that gate. The existing testnet and production governance state were not replaced.

Before cutover:

1. Reconcile runtime versions and install reviewed runtime prerequisites on the
   selected hosts through the existing roles. Preserve node-4 boot/network services
   and leave the spare SD volumes unused.
2. Build and qualify release artifacts against the final reviewed source revision.
   The retained `50c71d8` release bundle predates the merged seven-node source at
   `0baec04`; it is not evidence for the final revision. Bind source, OCI digests,
   configuration hashes and test results in one release manifest.
3. Complete registry endpoint/TLS, offline registry/auth bootstrap, service consents,
   fresh production identities, and the approved genesis configuration. Ratification,
   spending limits, fee/bootstrap policies, signer custody and lost-quorum recovery
   remain activation blockers.
4. Verify recovery of node-4 and its shared SSD, including replacement boot media
   and access when LAN services are unavailable. Six clients share this failure
   dependency; more machines do not constitute independent operators.
5. Review and explicitly authorize the exact testnet-replacement plan, then run
   physical consensus, container execution, registry, metering, settlement and
   recovery acceptance. Keep unsupported payable meters disabled and distinguish
   Epoch 0 operator telemetry from independent attestation.

The five preflight tests and eight expanded-topology tests passed for the small
preflight/template corrections made at this checkpoint. These are targeted tests,
not a rerun of the full production release qualification suite.
