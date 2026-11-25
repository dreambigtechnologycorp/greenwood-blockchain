# Operator-controlled durable state preparation

Check [project status](../../docs/PROJECT_STATUS.md) before selecting a phase.
Use the section matching the observed state and existing receipts; completed
credential placement and target creation must not be repeated. Storage-copy
completion and later production activation require separate observed evidence.

This is a seven-node reference procedure with a fixed public media plan and
bounded continuation phases. Other operators should start with
[role-based onboarding](../../docs/operations/ROLE_BASED_ONBOARDING.md), then
review a plan for their actual storage; do not copy its devices or phase flags.

This procedure prepares the reference machines for the reviewed rollback-
journal release. It does not start Greenwood. The older runtime revision must
not be activated after this migration: rebuild, qualify and approve the source
that uses verified SQLite `DELETE` mode and `synchronous=EXTRA` first.

Nodes 1–3 retain NFS boot and receive separate 64 GiB ext4 block volumes backed
by new preallocated files on Node 4's existing SSD. Each target accepts one
unique initiator with a separately supplied CHAP credential. Nodes 5–7 use their
existing empty ext4 SD partitions, bound by UUID and exact partition size. The
procedure never partitions or formats those cards, Node 4's SSD, or any existing
block device. `/etc` credentials and netboot/NFS/network configuration remain in
place. NFS originals of migrated state are retained underneath the bind mounts.

The filesystem transport remains a shared failure domain: loss of Node 4 or its
SSD interrupts both boot and validator state access. An ext4 mount does not prove
physical durability or recovery readiness; test those separately before launch.

## Inputs and credential boundary

Start with `inventory/state-storage.example.yml` or the prepared public candidate
`build/storage-production-20261124/storage-plan.json`. Fill exact public machine
identities, existing media UUIDs/capacities and distinct new filesystem UUIDs for
the three *new* backing files. The latter are filesystem metadata, not signing
keys. The confirmed cloned initiator name is repaired to the node-specific name
in the plan only while there are no iSCSI sessions. Its original public file is
preserved in `/etc/greenwood-storage/previous-initiatorname.iscsi`.

Prepare CHAP yourself using the operator credential instructions. Each JSON file
contains `username` and `password`; the password is 16–64 characters drawn from
`A–Z`, `a–z`, `0–9`, `_` and `-`. Install it root-owned, mode `0600`, under
`/root/.secrets/greenwood-storage/node-N.json` on Node 4 and the corresponding
client only. No values belong in inventory, command arguments or shared output.
Check mode inspects these files' metadata; apply reads them inside the helper.
Both are operator-only on credential-bearing hosts unless a narrowly scoped
public-metadata inspection has been explicitly authorized.
No blockchain, governance, offline CA or policy signing key is used.

If storage CHAP credentials have already been placed, retain them and skip this
generation/placement example. For a new installation only, run the generator
yourself on your normal operator computer, outside the
repository and AI-controlled input directories, then place the files with the
operator-only playbook. These are new storage credentials, not signing keys:

```bash
export ANSIBLE_CONFIG="$PWD/deploy/ansible/ansible.cfg"
export ANSIBLE_ROLES_PATH="$PWD/deploy/ansible/roles"
export GW_STORAGE_PLAN="$PWD/build/storage-production-20261124/storage-plan.json"
export GW_STORAGE_AUTH="$HOME/greenwood-storage-auth"
python3 scripts/prepare-storage-auth.py --directory "$GW_STORAGE_AUTH" \
  --generate --approve-storage-credential-generation

ansible-playbook -i "$GW_INVENTORY" \
  deploy/ansible/playbooks/install_storage_auth.yml \
  -e "@$GW_STORAGE_PLAN" \
  -e "greenwood_storage_auth_directory=$GW_STORAGE_AUTH" \
  -e '{"greenwood_operator_secret_access_authorized":true,"greenwood_storage_preparation_approved":true,"greenwood_recovery_access_confirmed":true}'
```

The generator preserves existing credentials; the placement playbook refuses
implicit replacement. Do not print or share the generated JSON files.

Credential placement checks each ancestor, including `/root`, before creating
the dedicated directory. A rejected ancestor is reported with its pathname,
ownership, mode and type only. For example, group-writable `/root` fails even
when `.secrets` does not exist yet. Correct only the identified directory's
metadata after reviewing it; never apply recursive ownership or permission
changes to its contents. Retry the placement playbook with the existing local
credentials after correction; generating replacement credentials is unnecessary.

Hash the `greenwood_storage_plan` object as UTF-8 JSON with sorted keys, compact
separators and ASCII escaping. Store the SHA-256 in
`greenwood_storage_plan_sha256`. A change to any media, initiator, scope or content
approval requires recalculating and reviewing that hash.

## Check before placement

The human operator runs the existing trusted inventory and SSH-agent workflow:

```bash
export ANSIBLE_CONFIG="$PWD/deploy/ansible/ansible.cfg"
export ANSIBLE_ROLES_PATH="$PWD/deploy/ansible/roles"
export GW_STORAGE_PLAN="$PWD/build/storage-production-20261124/storage-plan.json"

ansible-playbook -i "$GW_INVENTORY" \
  deploy/ansible/playbooks/prepare_state_storage.yml \
  -e "@$GW_STORAGE_PLAN" \
  -e '{"greenwood_storage_operation":"check","greenwood_storage_preparation_approved":true,"greenwood_recovery_access_confirmed":true}'
```

This checks all seven machine identities before any apply phase, confirms the
shared SSD UUID/free reserve and absence of an exported backing directory,
requires unused iSCSI configuration, verifies networking guards, and rejects
running Greenwood processes or container workloads. Existing SDs must be
unmounted, clean ext4 filesystems containing only an empty `lost+found`.
Unexpected files or recovered contents stop the procedure without deletion.
Before any provisioning mutation, all six state clients also check protection
of `/etc/machine-id`, the canonical source roots and their ancestors. These are
metadata-only checks; source contents and service-owned descendants are not
read or changed. A cloned, user-owned or group-writable machine-ID file must
have its metadata repaired without regenerating the identity. The healthy
cluster files are root-owned, mode `0444`, with one link.

On Node 4, systemd may report both an `autofs` entry and the mounted ext4 SSD at
`/mnt/greenwood`. The probe selects the filesystem actually opened by its kernel
mount ID and device number, then checks the approved UUID, ext4 type and writable
mount. Do not disable the automount or change the SSD layout to remove this
normal duplicate listing.

On the cloned validator roots, check `/etc/iscsi` and the public
`/etc/iscsi/initiatorname.iscsi` file if preflight reports `UNPROTECTED_PATH`.
The diagnostic identifies the offending path and metadata without printing file
contents. After confirming neither path is a symlink and the initiator file is
a regular file with one link, correct only their ownership to `root:root`, the
directory mode to `0755` and the file mode to `0600`. Do not recurse into iSCSI
records, edit the initiator contents manually or restart networking. The apply
phase handles the reviewed unique initiator names after every host passes.

After reviewing those results, record `empty_media_approved: true` for Nodes
5–7 and recalculate the plan hash. Review the exact final plan before applying.
The procedure repeats the content check; approval never permits clearing data.

For the prepared JSON candidate, this operator-run command requires an explicit
confirmation and updates only its public media approvals and canonical hash:

```bash
python3 - "$GW_STORAGE_PLAN" <<'PY'
import hashlib, json, os, sys, tempfile
from pathlib import Path
p = Path(sys.argv[1])
document = json.loads(p.read_text())
plan = document['greenwood_storage_plan']
for node in ('node-5', 'node-6', 'node-7'):
    item = plan['clients'][node]
    print(node, item['filesystem_uuid'], item['device_bytes'])
with open('/dev/tty') as tty:
    print('After successful read-only media checks, type APPROVE EMPTY SD MEDIA: ', end='', flush=True)
    answer = tty.readline().strip()
if answer != 'APPROVE EMPTY SD MEDIA':
    raise SystemExit('No approval changed')
for node in ('node-5', 'node-6', 'node-7'):
    plan['clients'][node]['empty_media_approved'] = True
digest = hashlib.sha256(json.dumps(plan, sort_keys=True, separators=(',', ':'), ensure_ascii=True).encode()).hexdigest()
document['greenwood_storage_plan_sha256'] = digest
fd, temporary = tempfile.mkstemp(prefix='.storage-plan-', dir=p.parent)
with os.fdopen(fd, 'w') as stream:
    json.dump(document, stream, indent=2)
    stream.write('\n')
    stream.flush()
    os.fsync(stream.fileno())
os.replace(temporary, p)
print('Approved public plan SHA256:', digest)
PY
```

## Apply storage only

```bash
ansible-playbook -i "$GW_INVENTORY" \
  deploy/ansible/playbooks/prepare_state_storage.yml \
  -e "@$GW_STORAGE_PLAN" \
  -e '{"greenwood_storage_operation":"apply","greenwood_storage_preparation_approved":true,"greenwood_recovery_access_confirmed":true,"greenwood_operator_secret_access_authorized":true}'
```

Only newly created exclusive backing files are formatted. Target exports use
write-through storage, explicit CHAP authentication and one initiator ACL per
LUN. Each SCSI unit serial remains the approved filesystem UUID across reboots.
The initiators bind the exact portal group `1` and default TCP interface; both
node and connection startup are automatic. Existing dormant node records stop
preparation before initiator repair or automatic login is enabled.
A dedicated target unit restores those exports after the SSD and network
are available; generic target restoration is disabled to avoid an unscoped
configuration restore. All six client checks must pass before Node 4 is changed.
The role separately creates a missing protected `/etc/rtslib-fb-target`
directory. Ubuntu normally creates this inside its package startup service;
blocking that service also blocks this prerequisite. Greenwood supplies only
the directory and never loads an existing saved target configuration.

Clients are handled serially. Their exact UUID volumes mount at
`/var/lib/greenwood-state`; the verified idle containerd daemon is stopped before
state copying. The existing forwarding oneshot is marked inactive after runtime
emptiness is established; its unit has no network teardown command. Networkd,
SSH, Cloudflare and NFS are not restarted.

Copies preserve ownership, permissions, ACLs, xattrs, timestamps and hardlinks.
A checksum/metadata comparison and unchanged-source proof are required before
canonical paths are bound to the new volume. Original state is not removed.
The copied SQLite databases are then backed up and converted offline through
the reviewed migration helper. Containerd databases are copied while stopped
but are not SQLite-converted. Storage dependencies prevent subsequent service
startup when required mounts are missing. Containerd and Greenwood remain stopped.

The SQLite helper requires Linux, Python 3.12 or later with `os.unshare`, and
root's `CAP_SYS_ADMIN` capability. It creates a private mount namespace before
opening database files so a host-side unmount cannot redirect SQLite paths to
the retained NFS directories. Unsupported or denied isolation stops conversion;
there is no fallback. Copies and backup-directory entries are flushed before
their completion records. These checks still require physical recovery testing.

## Interruption and later activation

This is a bounded first-placement procedure, not an active-cluster mover.
Do not use `--limit`: the all-seven-host preflight rejects omitted machines,
and every mutation phase also requires each host's matching same-run check fact.
Re-running the whole playbook after a partial apply intentionally stops on
existing backing files, sessions, mounts or prior configuration. Do not remove
those guards, delete receipts or repeat formatting to get past a failure.
Preserve the files and inspect the exact completed phase. The low-level copy
helper can resume its own matching interrupted copy before bind mounting, but
must not overwrite state after SQLite conversion or service use.

### Continue after target startup failed before any client changes

If all three backing files and their prepared receipt were created, but the
first `greenwood-state-target.service` start failed before any target exports,
backstores or client changes, use the bounded prepared-target continuation.
For example, the missing target metadata directory described above raises
`RTSLibError` with Ubuntu's `python3-rtslib-fb` 2.1.74. Inspect only the helper's
sanitized failure line and public target metadata; do not paste saved target
configuration or authentication files.

This mode requires the same approved plan, all seven hosts, exact creation and
prepared receipts, all three fully allocated UUID-bound backing files, the
reserved SSD free space, an unused iSCSI listener and empty target configuration.
All six clients must still pass the original untouched-storage checks. Existing
installed helper hashes are checked rather than silently replaced. This mode
cannot resume after any client state mount, login record, or partial target
configuration, and never repeats file creation or formatting.

Optional read-only continuation check:

```bash
ansible-playbook -i "$GW_INVENTORY" \
  deploy/ansible/playbooks/prepare_state_storage.yml \
  -e "@$GW_STORAGE_PLAN" \
  -e '{"greenwood_storage_operation":"check","greenwood_storage_resume_prepared_target":true,"greenwood_storage_preparation_approved":true,"greenwood_recovery_access_confirmed":true}'
```

The operator-controlled continuation repeats those checks before changing anything:

```bash
ansible-playbook -i "$GW_INVENTORY" \
  deploy/ansible/playbooks/prepare_state_storage.yml \
  -e "@$GW_STORAGE_PLAN" \
  -e '{"greenwood_storage_operation":"apply","greenwood_storage_resume_prepared_target":true,"greenwood_storage_preparation_approved":true,"greenwood_recovery_access_confirmed":true,"greenwood_operator_secret_access_authorized":true}'
```

If this continuation stops, preserve the existing state and review the specific
phase. Do not clear configfs, remove backing files or use `--start-at-task` to
skip the all-node checks.

### Continue after Node 1 mounted but copying had not begun

Use `resume_mounted_state_copy.yml` only for this specific interrupted phase:
Node 4's exact three targets are running, Node 1's approved ext4 state volume
is mounted, no copy marker or canonical bind mounts exist yet, and Nodes 2–3
and 5–7 have no storage changes. This is distinct from the earlier failed
target-start phase. Leave the targets and Node 1's state mount in place.

The continuation checks the active targets and their public configuration,
the unchanged plan/receipts/backing files, Node 1's exact mount and session,
stopped services, source-root metadata, and the untouched remaining clients.
Only Node 1 skips reconnecting and the unmounted-media probe. The normal
verified copying, bind mounts and offline SQLite migration still run. No
target creation, target restart, formatting or original-state deletion occurs.

The operator may first run the same checks without further changes:

```bash
ansible-playbook -i "$GW_INVENTORY" \
  deploy/ansible/playbooks/resume_mounted_state_copy.yml \
  -e "@$GW_STORAGE_PLAN" \
  -e '{"greenwood_storage_operation":"check","greenwood_storage_resume_mounted_client":"node-1","greenwood_storage_preparation_approved":true,"greenwood_recovery_access_confirmed":true}'
```

Then continue storage preparation with the exact same inputs:

```bash
ansible-playbook -i "$GW_INVENTORY" \
  deploy/ansible/playbooks/resume_mounted_state_copy.yml \
  -e "@$GW_STORAGE_PLAN" \
  -e '{"greenwood_storage_operation":"apply","greenwood_storage_resume_mounted_client":"node-1","greenwood_storage_preparation_approved":true,"greenwood_recovery_access_confirmed":true,"greenwood_operator_secret_access_authorized":true}'
```

All seven nodes must participate; do not use `--limit`. Existing copied state,
later-phase changes, other mounted clients or altered identities stop this
continuation for review. Greenwood remains stopped when storage completes.

Public preparation/copy receipts, final filesystem identities and read-only
mount reports can be returned for review. CHAP files, `/etc/iscsi` authentication
records, private inputs, state/database contents and backups remain operator
controlled. Next approve the rebuilt release, validate storage recovery and run
the guarded deployment continuation. This storage playbook does not grant
production activation approval or establish physical acceptance.

## Verify the rebuilt runtime and restore containerd readiness

Use the existing `release_runtime.yml` controller/build and distribution phases
for the newly reviewed source revision, with Greenwood still stopped. The
runtime-only distribution does not reset genesis or copy production identities.
Update the inventory's approved revision and release evidence first; do not run
`fresh_cluster.yml` again against initialized installation files.

After distribution, the following playbook verifies every installed source tree
against canonical controller evidence, requires the new SQLite policy file,
checks exact volume/copy/migration evidence, and starts **only containerd**:

```bash
ansible-playbook -i "$GW_INVENTORY" \
  deploy/ansible/playbooks/verify_state_runtime.yml \
  -e "@$GW_STORAGE_PLAN" \
  -e '{"greenwood_storage_runtime_start_approved":true,"greenwood_storage_preparation_approved":true,"greenwood_recovery_access_confirmed":true}'
```

It requires containerd and all Greenwood units to be stopped during the
migration check, then verifies the approved containerd version, EROFS plugin
and empty Greenwood namespace. If a check fails, preserve the evidence and
resolve that specific condition. Production service startup remains the
separate guarded continuation and acceptance procedure.
