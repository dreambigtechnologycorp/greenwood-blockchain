# Runtime prerequisite preparation

Use `deploy/ansible/playbooks/runtime_prerequisites.yml` to validate the host runtime separately from chain deployment. Its default invocation and check mode perform a read-only audit. They do not install packages, stop consumers, deploy identities, or activate genesis. Ansible may create temporary module files on the controller and targets.

For the seven-node profile the audit covers nodes 1–3 and 5–7. Node 4 remains the boot/network gateway and is excluded. Do not use the unused SD volumes on nodes 5–7 for runtime storage without a separate storage decision.

## Inputs

Use a private inventory derived from `inventory/seven-node.example.yml`, containing independently confirmed machine IDs, addresses and pinned SSH host keys. Provide a private candidate variables file with:

- Exact `greenwood_php_package_version` (8.5.12 vendor revision) and `greenwood_php_grpc_package_version`; wildcards are rejected.
- `greenwood_php_repository_key_src`, its `greenwood_php_repository_key_sha256`, and `greenwood_php_apt_repository`, using the signed-by path `/etc/apt/keyrings/greenwood-php.asc` and HTTPS Ubuntu noble repository.
- Exact `containerd_version`, `containerd_sha256`, `runc_version`, `runc_sha256`, and `erofs_utils_version`.
- `greenwood_runtime_artifacts.containerd` and `.runc`, paths to the locally downloaded and checksum-verified upstream artifacts.
- `greenwood_containerd_config`, validated with the selected containerd executable. Use the worker group only on provider hosts; do not grant a shared containerd socket group to unrelated users.
- `containerd_snapshotter: erofs`, `greenwood_runtime_install_approved: false`, and `greenwood_runtime_install_hosts: []` during preparation.

Run from the repository root with Ansible Core 2.19.4:

```sh
export ANSIBLE_LOCAL_TEMP=/tmp/greenwood-ansible-local
export ANSIBLE_REMOTE_TEMP=/tmp/greenwood-ansible-remote
export ANSIBLE_ROLES_PATH="$PWD/deploy/ansible/roles"
ansible-playbook -i /private/seven-node.yml \
  deploy/ansible/playbooks/runtime_prerequisites.yml \
  -e @/private/runtime-candidate.yml --syntax-check
ansible-playbook -i /private/seven-node.yml \
  deploy/ansible/playbooks/runtime_prerequisites.yml \
  -e @/private/runtime-candidate.yml --check
```

Do not use `--limit`: every runtime host must pass the identity, platform, artifact and worker-account barriers before installation is allowed. UID/GID collisions fail closed.

## Installation boundary

After reviewing the candidate and confirming recovery access, an explicitly approved installation can select idle nodes using `greenwood_runtime_install_hosts` and set `greenwood_runtime_install_approved: true`. Keep the complete inventory in scope and omit `--check`. Each selected host must also have `greenwood_recovery_access_confirmed: true` based on actual verified recovery readiness.

Installation runs serially through the existing `common` and `container_runtime` roles. It installs PHP, Wasmtime, gRPC, containerd, runc and EROFS tooling and starts containerd. It does not deploy Greenwood application services or production chain configuration. Hosts with active Greenwood or containerd units are rejected; existing nodes 1–3 require a separately coordinated maintenance procedure. Do not disable that guard to force an upgrade.

Passing preparation does not establish package dependency resolution, successful installation, workload execution, EROFS suitability on NFS-backed storage, metering qualification, or production readiness. Preserve package metadata and versions, and run installation verification and physical workload acceptance after an authorized installation. Governance and genesis approvals remain separate.

## November 17, 2026 candidate

The prepared candidate uses PHP `8.5.12-1+ubuntu24.04.1+deb.sury.org+1`, gRPC `1.83.1-1+ubuntu24.04.1+deb.sury.org+1`, EROFS utilities `1.7.1-1build2`, containerd `2.3.7`, and runc `1.5.3`. These are candidate inputs, not formal production approval. The PHP repository public key was retrieved from the verified node-1 installation; its fingerprint is `B8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6`. Independent repository-key/provenance review remains part of release approval.

Local evidence is under ignored `build/runtime-preparation-2026-11-17/`: candidate variables, downloaded artifacts, upstream checksums, Ansible output and regression results. The six-host audit completed with zero changed, unreachable or failed tasks. Identity and artifact checks passed; package-health findings still block installation on nodes 1–3. All 43 Ansible regression tests passed. This is prerequisite preparation evidence, not independent physical qualification.

Actual ARM64 execution on node 5 confirmed the downloaded containerd 2.3.7 and runc 1.5.3 versions. The candidate containerd configuration parses as native version 4. Temporary validation binaries were removed; no daemon was started. This proves executable compatibility and configuration parsing only.

The node-1 APT simulation resolved the selected PHP/gRPC versions but exposed pending package transactions. The final six-host `dpkg --audit` also found unfinished systemd/udev packages and pending triggers on nodes 1–3. Nodes 5–7 returned clean package audits. Repair the existing nodes under a reviewed maintenance/recovery procedure before runtime upgrades; do not blindly run a blanket package configure operation on the NFS-root hosts. Exact dependency resolution on the fresh hosts remains to be validated after repository provisioning.

**Subsequent maintenance incident:** the targeted node-1 systemd configuration retry interrupted network access while its package script restarted networkd. Subsequent guarded repairs use a persistent `RefuseManualStop=yes` networkd drop-in and temporary package-service restart suppression. See the dated maintenance evidence for current per-node completion; the initial audit logs remain historical. See [NFS package maintenance preparation](NFS_PACKAGE_MAINTENANCE.md) for the isolated retention validation, incident evidence and restart limitations.

## Guarded PHP-only rollout

`deploy/ansible/playbooks/php_runtime.yml` installs the exact PHP 8.5.12 package revision and pinned gRPC extension on all seven nodes, including the gateway. Supply the complete private inventory with each host's `greenwood_expected_machine_id` and the reviewed PHP repository/key/version variables described above:

```sh
ansible-playbook -i /private/seven-node.yml \
  deploy/ansible/playbooks/php_runtime.yml \
  -e @/private/runtime-candidate.yml --syntax-check
ansible-playbook -i /private/seven-node.yml \
  deploy/ansible/playbooks/php_runtime.yml \
  -e @/private/runtime-candidate.yml
```

The second command changes the machines and requires operator authorization. Do not use `--limit`. The playbook audits all seven identities, platforms and package states before starting its serial installation. It rejects an existing package-restart policy rather than overwriting it. Known legacy PHP repository entries are validated before consolidation into the signed repository definition.

A persistent `RefuseManualStop=yes` networkd drop-in blocks explicit service stops/restarts. A temporary `policy-rc.d` suppresses package-managed service restarts during installation and is removed afterward, including when an installation task fails. The playbook reloads systemd configuration without restarting networking, verifies the loaded guard, and requires networkd's PID to remain unchanged. These controls do not make arbitrary networking changes or every possible package script safe; use the separate network maintenance procedure for those operations.

The shared runtime task selects `/usr/bin/php8.5` as the default PHP alternative. Final checks invoke `php` through PATH and require version 8.5.12 plus Sodium, PDO SQLite, POSIX, PCNTL, FFI, gRPC, cURL, DOM and mbstring. Package audits and SSH/networkd service checks must also pass. This installs PHP prerequisites only; Wasmtime native libraries, other runtime tooling, application deployment and production acceptance remain separate.

### Completed rollout — November 17, 2026

All seven nodes now use `/usr/bin/php8.5` as the default PHP CLI and report **8.5.12**. Each passed extension checks for Sodium, PDO SQLite, POSIX, PCNTL, FFI, gRPC **1.83.1**, cURL, DOM and mbstring. Exact package revisions match the candidate pins above. Separate read-only verification passed on nodes 1–5 and nodes 6–7 with zero failed or unreachable tasks.

All seven package audits are clean. Networkd PIDs remained unchanged during their installations and persistent restart guards remain active. Temporary package restart policies were removed. Node-4 DNS/DHCP, NFS and Cloudflare services are active; Apache remains inactive. No reboot or network-service restart was performed.

The initial controller run ended with exit status 143 while node-6's remote gRPC task was still completing its notification hook. The signal's origin was not established. After confirming package completion and clearing only the known temporary policy, a resumed run audited all seven hosts and completed nodes 6–7 with zero failures. The final 48 Ansible regression tests passed, and `git diff --check` passed. Local evidence, exact source-file checksums and the result summary are under ignored `build/php-rollout-2026-11-17/`.

This establishes PHP prerequisite installation, not production deployment or independent workload qualification. Node-6's link issue below remains a production reliability follow-up.

### Node-6 observation during the PHP rollout

On November 17, the node-6 repository refresh was delayed while an `apt-check` child process waited in `nfs_file_flush`/`folio_wait_writeback`. Its kernel (`6.8.0-1064-raspi`) logged five `bcmgenet` Ethernet transmit-queue-4 watchdog timeouts. A subsequent counter snapshot showed five TX errors, zero RX errors, and the link remained UP. SSH and networkd were active, networkd retained PID 658, and `RefuseManualStop=yes` remained loaded. The first delayed repository-tooling task eventually completed without a network restart or driver change.

The gateway NFS workers were running, exports retained their expected settings, and its storage had ample free space; no storage errors appeared in the inspected gateway kernel log. These observations identify a client-side Ethernet/NFS reliability concern but do not establish its underlying driver, firmware, hardware or load-related cause. Preserve this as a production reliability follow-up and do not reset a live NFS-root interface without a recovery plan. Diagnostics are in ignored `build/php-rollout-2026-11-17/`.

A subsequent `ethtool eth0` check found node-6 negotiated at **10 Mb/s full duplex**, with the link partner advertising only 10 Mb/s modes. The NFS route correctly used eth0. This is a concrete bandwidth bottleneck; inspect switch port/extend-mode settings and cabling during controlled maintenance. Do not force renegotiation remotely on the running NFS root. The low link rate alone does not prove the cause of the watchdog errors.

After node-6's PHP package configuration and triggers completed, a nonessential update-notifier `apt-check --human-readable` post-install child continued holding the APT task open. The installed hook was inspected: it writes a temporary MOTD cache, cleans up on failure, and the APT hook explicitly tolerates failure (`|| true`). A targeted SIGTERM was sent only to that notification checker; apt/dpkg, networkd, NFS and interfaces were not stopped. The PHP task then returned successfully and its default-version check passed. The notification hook itself was not changed or disabled.

To resume an interrupted rollout after inspecting package processes/state and safely clearing only its own temporary restart policy, pass `-e '{"greenwood_php_install_hosts":["node-6","node-7"]}'` with the unfinished hosts. The first play still audits the complete seven-node inventory; only the serial installation play is narrowed. Do not use `--limit`, and do not remove a temporary restart policy while a package transaction remains active.

### Follow-up read-only comparison — November 17 evening

Nodes 5, 6 and 7 all run `bcmgenet` on `6.8.0-1064-raspi`. Nodes 5 and 7 negotiated 1000 Mb/s full duplex with zero TX/RX errors. Node-6 remained at 10 Mb/s full duplex, advertised its own 10/100/1000 capability, but reported that its link partner advertised only 10 Mb/s. Its boot log already showed 10 Mb/s at 4.706 seconds, establishing that the rate limitation preceded PHP installation. Its TX error count remained five, with no additional watchdog events since the earlier sample; RX/CRC counters were zero and `vcgencmd get_throttled` returned `0x0` on all three nodes.

Node-6's networkd service retained PID 658 and its restart guard. Its DHCP address and NFS route were correct. The inspected persistent configuration locations contained no matching explicit speed/duplex/autonegotiation override. The evidence points first to the switch-side link configuration or physical path; the exact switch port and underlying cause remain unverified. On supported TP-Link switches, Extend mode restricts affected ports to 10 Mb/s (https://www.tp-link.com/tr/document/48986/). Confirm the model and port before changing any switch mode, since it may affect several netboot nodes. If the port is already in normal mode, isolate cable/port faults during controlled maintenance with recovery access. Do not force a gigabit rate or cycle the live NFS-root connection remotely.

Fresh evidence: ignored `build/node6-network-investigation-2026-11-17/comparison.log` and `configuration.log`. All remote commands in this investigation were read-only; Ansible raw output labels them CHANGED because raw commands lack automatic change detection.

## Public source and guarded runtime deployment

The controller now defaults to anonymous HTTPS retrieval from `https://github.com/dreambigtechnologycorp/greenwood-blockchain.git`. An explicitly approved full `greenwood_source_sha` remains mandatory, and the checked-out HEAD and containerd submodule commit are verified. Git prompts are disabled for checkout; SSH agent forwarding is disabled in the Ansible defaults. Public access does not authorize deploying an unreviewed branch tip. Anonymous access was verified at main revision `0baec048b764c26fbf5e01fdc9139719f57a332d`; local preparation changes must be published and reviewed before selecting their commit for a production source deployment.

The runtime prerequisite playbook now requires the existing networkd restart guard before installing selected hosts, wraps package operations in a temporary restart-suppression policy, and verifies unchanged networkd PID plus clean package state afterward. Its recovery-readiness and inactive-runtime gates remain enforced. Nodes 5 and 7 are the next installation candidates; node-6 is deferred for its physical link issue. Nodes 1–3 need coordinated maintenance of their retained containerd daemons before runtime replacement. Node-4 remains the gateway and is outside the system-runtime host group.

### Explicit deferred-recovery authorization

The operator authorized continuing offsite on November 17, accepting that physical recovery may wait until November 18. Do not relabel this as verified recovery access. For this case only, provide the explicitly authorized hosts in `greenwood_runtime_deferred_recovery_hosts` and a nonempty `greenwood_runtime_deferred_recovery_reason`. The default remains fail-closed; all identity, package, idle-runtime and network guard checks still apply. The first batch selects nodes 5 and 7. Nodes 1–3 require inspection and coordinated handling of their retained containerd daemons; node-6 remains deferred for its physical link issue.

For the retained empty runtimes on nodes 1–3, use `playbooks/prepare_idle_runtime_upgrade.yml` with `maintenance_host: node-N` and `maintenance_approved: true`, one host at a time. It rechecks identity, network guards, clean package state, zero containerd namespaces and no active Greenwood services, saves a root-only runtime/configuration rollback archive, then stops only containerd. Immediately run `runtime_prerequisites.yml` with that single host selected (but the full inventory in scope), then verify the installed versions, Wasmtime FFI engine creation, EROFS plugin readiness and networking before advancing. If installation fails, stop the sequence and inspect the recorded rollback archive; do not continue to another host. These are runtime backups, not resurrected testnet chain or key backups.

## Runtime deployment result — November 17, 2026

Runtime prerequisites were installed and verified on **nodes 1, 2, 3, 5 and 7** under the operator's explicit deferred-recovery authorization. Nodes 5 and 7 received fresh installations; the empty retained runtimes on nodes 1–3 were upgraded sequentially after saving root-only runtime/configuration rollback archives. Each host passed verification before the next legacy host was changed.

| Component | Verified result on these five hosts |
|---|---|
| Default PHP | 8.5.12; required extensions retained |
| Wasmtime | 48.0.1; PHP FFI engine create/delete passed |
| Wasmtime library integrity | Matches the checksum-pinned upstream archive; library SHA-256 `6a113cb547f181577eddd0ecc81588ad3306c747b8ba3880d5593f37d8697a74` |
| containerd client/server | 2.3.7 |
| runc | 1.5.3 |
| EROFS tools | `1.7.1-1build2`; EROFS snapshotter reports `ok` |
| Package and networking checks | Clean package audits, unchanged networkd PIDs, active restart guards; temporary restart policies removed |

Networkd PIDs remained 701/704/707/653/651 on nodes 1/2/3/5/7. The final read-only seven-host health and library-integrity play completed with no failed or unreachable hosts. Node-4 remains the gateway, outside this runtime placement; node-6 remains deferred for the 10 Mb/s Ethernet issue. Neither was subjected to runtime installation.

All 50 Ansible regression tests passed. Evidence is in ignored `build/runtime-deployment-2026-11-17/`, including per-host preparation, installation and verification logs, final health, artifact hashes and the explicit authorization inputs. The runtime rollback archive locations for nodes 1–3 are recorded in their preparation logs. No production genesis, registry, validator, provider application service or customer workload was activated. EROFS plugin readiness and FFI execution are prerequisite evidence, not full container-workload or metering acceptance.
