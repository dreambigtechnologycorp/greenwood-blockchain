# Project and release status

Status date: **2026-11-24**. This page separates source implementation, release
qualification and operator-reported installation progress. It is not an
activation authorization or a reproducible release manifest.

## Current source and release boundary

The existing deployment artifact baseline is
`bd529a5254bbaf1eb2f16cadf89b5c53ccb09f1e`. This source revision includes additional
startup-order, interrupted-installation, durable-storage and SQLite rollback
journal changes. Artifacts built from that baseline do **not** contain all those
changes. Do not combine old OCI images with new source and call them one release.

Current source centralizes SQLite durability on `journal_mode=DELETE` and
`synchronous=EXTRA`. Guarded Ansible installation/startup workflows reject
unqualified canonical storage, stage recovery timers until explicit approval,
and support continuation after a partial installation. Direct daemon entrypoints
do not independently qualify the filesystem: manual operators must perform the
equivalent durable-mount checks. See [storage](../deploy/ansible/STATE_STORAGE.md) and
[deployment entrypoints](../deploy/README.md).

Before activation, approve the final source revision, run the complete applicable
qualification, rebuild immutable release artifacts, and approve their exact
source revision, digests, genesis and configuration hashes. Prior test counts or
previous image digests do not substitute for evidence from that final revision.
Follow [release preparation](RELEASING.md).

## Existing operator installation: reported evidence

This is a record of the initial DreamBIG deployment, **not a required topology
for other operators**. New operators should start with
[role-based onboarding](operations/ROLE_BASED_ONBOARDING.md).

| Milestone | Evidence and boundary |
| --- | --- |
| Credential custody and placement | Reported completed by the human operator; no private material was opened during this documentation audit |
| Durable storage preparation | Operator supplied a successful seven-host Ansible recap on November 24: zero failed and zero unreachable hosts |
| State migration | Completion reports state preserved, bound and migrated; the run uses the guarded copy/bind/offline-migration procedure |
| Service startup | Completion explicitly reports containerd and Greenwood remain stopped |
| Production activation and physical acceptance | No successful activation or full physical-acceptance result supplied for the updated release |

The completed storage procedure is not a claim that restart recovery, sustained
workload performance, metering independence or finality has been accepted.
Hardware qualification must identify the actual host, resource profile and trust
class. Unsupported hardware and missing independent attestation remain fail-closed.

The [November 18 adoption record](economics/PRODUCTION_POLICY_ADOPTION_2026-11-18.md)
is the existing operator's governance record. It does not approve future source
or configuration hashes automatically. Templates and other operators' policies
must be reviewed in their own network context.

## How to interpret the documentation

- General guides describe roles, interfaces and supported behavior.
- Four- and seven-node documents describe specific reference installer profiles.
  Their identifiers and placement must not be copied as an external operator's identity.
- Dated release notes, capacity reports and economics evidence are snapshots.
  Their outcomes are not current deployment authorization.
- Local fixtures, native runtime tests and intended-host physical acceptance are
  distinct evidence. Report skipped and unavailable checks explicitly.

Documentation maintenance in this pass changes no network state, approves no
release hashes, provisions no credentials and starts no service.
