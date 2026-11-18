# Testnet removal — November 17, 2026

The user authorized removal of the obsolete Greenwood testnet, its deployments, identities, chain/application data and backups, while retaining OS/network access and runtime prerequisites. No replacement network was installed or activated.

The deployed testnet was removed from nodes 1–4. Nodes 5–7 had no Greenwood deployment. The removal included validator/provider configuration and keys, chain and application databases, registry/application containers and images, old deployment/release trees, dedicated application logs, testnet web routes, historical state directories, and the old full node-1 root-filesystem backup on node 4's SSD. No replacement testnet backup was created. Temporary cleanup helpers were removed after evidence collection.

Old testnet writers were stopped before deletion. Each target was bound to its approved machine ID; container namespaces were checked for unrelated workloads, and deletion refused paths containing live mounts. Large NFS-backed trees were removed locally on node 4's SSD using the reviewed per-node manifests after the client writers were stopped. The helper constrained paths to the matching client root filesystem.

Retained prerequisites and infrastructure:

- OS, SSH/host identities and Cloudflare connectivity.
- Node 4's DHCP/DNS, NFS, TFTP, gateway firewall and network configuration.
- All active client boot/root filesystems and netboot recovery records; unused SD storage was untouched.
- PHP and installed extensions, Wasmtime libraries/tools, containerd, runc and EROFS tools/configuration. EROFS tool paths referenced by containerd were explicitly retained.
- Persistent networkd restart guards on nodes 1–3 and OS maintenance/package records.
- OS journals and boot-recovery metadata; these are not a retained testnet ledger or deployment backup.

The unused Apache default listeners on nodes 1–4 were stopped and disabled; the package remains installed. Containerd was restarted with empty state on nodes 1–3. No runtime upgrades were performed: their default PHP remains 8.5.10, while node 4 reports 8.5.12. Nodes 5–7 still require runtime installation.

Verification:

- All seven nodes reachable with verified SSH identities and expected root filesystems.
- No failed systemd units in the post-removal audit.
- No Greenwood testnet unit files or running testnet workloads remained in the audited deployment locations.
- Containerd namespaces empty on nodes 1–3.
- PHP Sodium, PDO SQLite, POSIX, PCNTL and FFI retained on nodes 1–4; gRPC retained on nodes 1–3.
- Wasmtime C API engine creation/deletion succeeded through PHP FFI on nodes 1–4.
- Node 4's protected boot/network/NFS-export configuration and gateway firewall hashes matched before and after cleanup.
- SSH/networkd remained active on every node; Cloudflare, DHCP/DNS, NFS and TFTP services remained active on node 4.

Private operational evidence, path manifests and per-node results are in ignored `build/testnet-removal-2026-11-17/`. This is a clean deployment baseline, not production installation or physical workload qualification. Earlier package-repair backup paths documented in this repository are historical: those Greenwood maintenance backups were removed during this authorized cleanup.
