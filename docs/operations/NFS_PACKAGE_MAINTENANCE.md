# NFS-root package maintenance preparation

The incident and repair results below are dated November 17 evidence. Consult
[project status](../PROJECT_STATUS.md) before any new maintenance. The
[authorized testnet removal](TESTNET_REMOVAL_2026-11-17.md) later removed the
historical Greenwood package-repair backups; their paths below are provenance,
not a claim that a recovery archive is still available. Verify current backups
and recovery access yourself before applying this operator-only procedure.

## November 17 incident

A targeted `dpkg --configure systemd` retry on node 1 reached its package script's explicit `systemctl try-restart systemd-networkd.service`. The persistent journal ended while networkd was stopping; SSH and ping then failed both directly and through node 4. Package completion was not confirmed. Do not repeat this procedure on other NFS clients while recovery access is unavailable.

Node 1's backup is `/var/backups/greenwood-package-repair-20261117/pre-repair.tar`, accessible through its node-4 NFS root. It contains protected account/configuration files and must remain private. Do not restore the package database alone over a live filesystem or modify package state from node 4 while the client may still be running.

The precise failure mechanism is not yet proven. Nodes 2–3 already have `KeepConfiguration=true` in generated networkd configuration, originating from initramfs-provided `/run/netplan/eth0.yaml`. Their persistent `/etc/netplan` configuration lacks `critical: true`. A persistent declaration closes that persistence gap, but is not evidence that a package-script restart is safe.

## Preparation without live changes

`deploy/ansible/playbooks/prepare_nfs_network_safety.yml` invokes the `nfs_network_safety` role. The helper checks the inventory machine ID and NFS root, copies netplan inputs to a temporary directory, and generates this candidate overlay there:

```yaml
network:
  version: 2
  ethernets:
    eth0:
      critical: true
```

It validates the merged boot/persistent configuration, rejecting changes beyond retention in the current generated Ethernet configuration. It then removes the temporary boot input and confirms that persistent inputs alone still generate retention. It does not promise that persistent-only settings preserve boot-supplied DNS or other settings; those need separate review before any boot-network redesign.

Run from the repository root, using the existing private inventory and trusted host keys:

```sh
export ANSIBLE_LOCAL_TEMP=/tmp/greenwood-ansible-local
export ANSIBLE_REMOTE_TEMP=/tmp/greenwood-ansible-remote
export ANSIBLE_ROLES_PATH="$PWD/deploy/ansible/roles"
ansible-playbook -i /private/seven-node.yml \
  deploy/ansible/playbooks/prepare_nfs_network_safety.yml \
  --limit node-2,node-3 --check
```

This preparation play may be limited to reachable clients. It never calls live `netplan generate`, `netplan apply`, `networkctl reload`, package configuration, or a service restart. It writes only disposable validation/module files. It is separate from the complete-host runtime installation barrier.

On November 17, both nodes passed with `changed=0`, `unreachable=0`, `failed=0`. Evidence is in ignored `build/package-repair-2026-11-17/nfs-retention-preparation.log`. The candidate is not installed and restart qualification is false.

## Before resuming repairs

1. Recover node 1 with onsite power control; verify host identity, NFS root, SSH, package state and service health.
2. Have tested onsite recovery available for the next maintenance window. Inspect remaining ownership anomalies and the journal before treating retention as the incident's root cause.
3. Back up and review the persistent overlay alongside all existing netplan inputs. Apply it only in a controlled maintenance window, verify effective live retention, and test recovery before package repairs on another node.
4. Account for direct networkd restarts and systemd re-execution in the installed package script. `policy-rc.d` alone does not intercept its direct `systemctl try-restart`. Do not edit maintainer scripts, falsify package completion, or bypass the runtime installation package-health guard.
5. Complete configuration and pending triggers one node at a time, preserving boot artifacts and verifying network, DNS, NFS, udev, services and a clean `dpkg --audit` after each node. Revalidate runtime prerequisites afterward.

Writing a cron file into the shared root cannot force an unreachable client to reboot. A running client-side process must read and execute it; a client stalled on NFS may not do so. A delayed reboot trigger can instead execute after recovery. No such trigger was installed. Future watchdog or managed-power recovery requires its own configuration and physical testing; it is not a recovery mechanism that can be assumed active now.

## Guarded configuration method

The installed systemd package directly invokes `systemctl try-restart systemd-networkd`; this bypasses `policy-rc.d`. The previous incident reached that operation immediately before IPv4/NFS access was lost. The record does not prove why address retention failed, so `critical: true` alone is not a sufficient repair claim.

`scripts/repair-nfs-pending-packages.sh EXPECTED_MACHINE_ID` is an operator-invoked root maintenance script for these NFS-root hosts. It backs up package/account/network/boot configuration, installs this persistent networkd drop-in, reloads unit definitions without restarting networkd, and checks the effective guard and unchanged networkd PID:

```ini
# /etc/systemd/system/systemd-networkd.service.d/90-greenwood-nfs-root.conf
[Unit]
RefuseManualStop=yes
```

It temporarily installs `policy-rc.d` returning 101 to defer package-helper service restarts, runs only `dpkg --configure --pending`, checks package audit and service health, and removes its temporary policy on exit. It refuses to overwrite an existing policy or guard; review those explicitly before retrying a partially completed run. The persistent guard remains installed. It blocks explicit stop/restart requests, not dependency-driven shutdown, crashes, or every possible network change. Do not remove it to force a remote network restart.

The script must be run as a durable, logged systemd oneshot, one host at a time, after review of the pending packages and their maintainer scripts. It does not install package upgrades, edit package scripts, alter status records to fake success, or qualify a reboot. Some service restarts are deliberately deferred; assess loaded service versions and schedule controlled activation with recovery access. Protected backups remain on the host under `/var/backups/greenwood-guarded-packages-*`.

## November 17 repair outcome

The guarded repair completed sequentially on nodes 1, 2 and 3. Each node had an empty `dpkg --audit`, no failed systemd units, active SSH/networkd/resolved/udevd/containerd services and successful DNS resolution. Networkd PIDs remained 701, 704 and 707 respectively, unchanged throughout each repair. The direct package-script networkd restart was refused by the installed guard. Temporary `policy-rc.d` files were removed; persistent guards remain installed.

All pending triggers, including initramfs generation and the boot-file hook, completed. No reboot, runtime upgrade or production activation was performed. These results demonstrate guarded package completion, not safe unguarded network restarts or boot qualification. Node 3 was reached via the trusted node-4 SSH gateway because the direct management route refused SSH.

Per-node results and private backups are indexed in ignored `build/package-repair-2026-11-17/`. This outcome supersedes the earlier paused-package status; the incident journal remains evidence of the unresolved low-level retention failure.

The final runtime-prerequisite Ansible audit passed on all six runtime hosts with zero changed, unreachable or failed tasks, and no package-audit findings. Nodes 5–7 required a separate trust file verified against their public keys on the trusted node-4 NFS server; machine IDs and Ethernet MACs matched the inventory. The original trust file was preserved.
