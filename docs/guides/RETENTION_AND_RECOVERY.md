# Journal retention and governance recovery

## Local journal archival

Archival moves completed payloads out of hot SQLite tables into content-addressed
0600 JSON files under the journal's private `archive/` directory. It writes and
syncs the file and directory before committing pruning. A crash before the SQLite
commit can leave a harmless unreferenced archive file; it cannot silently discard
the only copy of active evidence. Do not delete archive files referenced by a journal.

Small replay guards remain permanently in SQLite: commitment identities, runtime
counter high-water marks, signed transaction sender/nonces and installation identity
ownership. Thus hot payload limits no longer stop work merely because historical
closed records exist. **Total retained evidence still uses disk.** Plan capacity and
backups; this is not a promise of constant total storage or automatic deletion.

Meter checkpoint payloads roll automatically in chunks after 512 hot samples while
preserving sequence/hash lineage and an overlap anchor. Bundle export verifies the
retained chunks. Sampling gaps or uncertain billing boundaries are not repaired by
rollover.

Explicit archival commands:

```sh
php scripts/resource-metering.php meter-archive \
  METER_DIR COMMITMENT EXACT_BUNDLE_HASH
php scripts/resource-metering.php telemetry-archive \
  TELEMETRY_DIR COMMITMENT EXACT_TELEMETRY_HEAD
php scripts/resource-metering.php outbox-archive \
  OUTBOX_DIR CHAIN GENESIS TX_HASH FINALIZED
php scripts/resource-metering.php installation-archive \
  INSTALL_JOURNAL_DIR CHAIN GENESIS POLICY_HASH EXACT_AUDIT_HEAD
```

The cgroup meter refuses open or uncertain-boundary bundles. Universal telemetry
retains unverified observations for reconciliation but never converts them into a
claim. Outbox items must be `FINALIZED` or explicitly `NONCE_CONFLICT`; conflict is
not success. An installation must be `STOPPED`, with an exact matching audit head.
Uncertain installation ownership must be reconciled through existing vacancy checks
before archival. Archived records remain readable and cannot reopen/reuse a nonce
or runtime identity.

Stop the corresponding local writer before a filesystem backup or use a consistent
SQLite online backup plus all immutable archive files. Include archive subdirectories,
SQLite identity/tombstone tables and service configuration. Restore ownership/modes
and verify old bundles/audits before resuming. Never copy a live `.sqlite` file alone
while ignoring its WAL. Never delete a protected archive to bypass a corruption error.
An offline `VACUUM` may reclaim SQLite free pages after a verified backup; never run
it automatically in a billing loop.

For external archival storage, retain a verified local readable copy until an
explicitly reviewed storage adapter exists. Do not replace `archive/` with a public,
mutable, symlinked or untrusted mount. Disk-full, missing archives and integrity
failures stop evidence emission; use the accepted customer refund/dispute policy.

## Governance recovery is separate from service recovery

Restarting a meter, refunding an unavailable workload and replacing lost treasury
signers are different operations. The new native recovery path changes only the
five governance signer keys and guardian keys. It cannot change allocations,
reserve balances, recipients, fees, spending caps, accepted service commitments or
its own recovery authority. Existing proposals become invalid after execution.

Historical genesis policies without recovery remain replayable. Fresh production
validation requires an explicit `recovery_policy` decision. `null` in the production
template is unresolved and blocks preparation. `{"enabled":false}` is an explicit
choice to disable this mechanism; it is not a recommendation or an implicit
approval to launch without recovery.

An enabled version-1 policy requires **all** of these fields:

| Field | Constraint |
|---|---|
| `version` | `1` |
| `enabled` | `true` |
| `keys` | 3–9 distinct 64-hex public keys, separate from ordinary signer/guardian keys |
| `threshold` | Explicit integer majority of council keys, no larger than its size |
| `timelock_blocks` | Explicit positive finalized-height delay |
| `proposal_lifetime_blocks` | Greater than timelock, at most 525,960 blocks |
| `veto_threshold` | Explicit 1–5 existing governance signers |

No default council, five-of-seven recommendation, 30-day duration or production
custody is silently installed. Choose and ratify the parameters outside the tool.
Key separation checks detect identical keys, not independent organizations. Recovery
cannot cryptographically prove that a key was lost; it grants the explicitly
configured emergency authority, with notice, expiry and ordinary-signer veto.

Recovery authority is genesis-bound and cannot be enabled, disabled or replaced by
ordinary policy rotation. Changing it requires a separately reviewed protocol/
genesis transition, not a transaction that invents an emergency council.

## Recovery transaction sequence

Recovery actions use native treasury transaction type 12 and the existing
`treasury-sign` command. Every action binds the genesis hash, current policy version
and sender account nonce. Council accounts need fees under the normal fee policy;
recovery cannot mint funds or bypass fees.

1. A configured council signer submits `recovery_propose` with `nonce` equal to
   `recovery_state.nonce`, five replacement `signers`, replacement `guardians`,
   and a 64-hex `reason_hash` committing the reviewed incident record.
2. Council members submit `recovery_approve` with the exact `proposal` ID. Duplicate
   signatures do not increase quorum. The timelock starts when quorum is reached.
3. Current governance signers can submit `recovery_veto`. Reaching the configured
   veto threshold cancels the proposal. One active recovery is allowed per policy
   version; expiry allows a new proposal with the next recovery nonce.
4. After `ready` height and before expiry, a council signer submits
   `recovery_execute`. This atomically replaces the permitted keys, increments
   `policy_version`, invalidates outstanding ordinary approvals and records the
   terminal recovery event. The transaction cannot be executed twice.

Example action shapes (replace every placeholder with reviewed values):

```json
{"action":"recovery_approve","genesis":"GENESIS_HEX","policy_version":1,"proposal":"PROPOSAL_HEX"}
```

```sh
php scripts/economics-cli.php treasury-sign \
  /private/genesis.json /private/council.key /private/action.json CURRENT_ACCOUNT_NONCE
```

The command produces a signed envelope; it does not broadcast. Inspect and submit
through your existing authenticated transaction workflow, then verify certified
inclusion. Use the original policy version until execution finalizes. Old signer
keys and old version-bound approvals cannot authorize new actions afterward.
Full proposal/approval/veto history remains in finalized blocks; the derived state
keeps the active proposal, monotonic nonce and latest terminal event.
