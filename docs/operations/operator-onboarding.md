# Operator onboarding candidate — 2026-11-14

> Historical snapshot: the findings and commands below describe their dated
> source/infrastructure state, not the current installation procedure. Start with
> [project status](../PROJECT_STATUS.md) and [operator onboarding](README.md).
> Retain the recorded evidence and counts; do not repeat completed initialization,
> removal or credential-generation steps from this snapshot.

DreamBIG is the disclosed genesis operator. A second key, VM, validator process or
physical machine controlled by DreamBIG is not an independent administrator.
Existing physical addresses are topology references, not qualification targets.
`qualification.ini` and `metering.ini` remain empty; do not run a fresh-cluster
installer against node-1 through node-4.

The new `resource_network` treasury proposal kind commits a version-1 operator,
system-service or epoch record. It uses Institute policy authority, four existing
signers and the existing 20,160-block timelock. It spends no funds. Genesis/policy
version, proposal expiry, pause and signed-envelope rules still apply. This is a
candidate protocol extension requiring review and activation authorization.

An operator record declares controller, reviewed administrative domain, roles,
independence evidence/reviewer, qualified profiles, revision and effective heights.
Independent records require a reviewed historical participation window and evidence
digest. Future participation cannot be approved. Qualification remains an externally
reviewed assertion: software cannot establish beneficial ownership or physical truth.
Revoked identities cannot be resurrected; controller/domain identity cannot be rewritten.

Roles are PROVIDER, METERING, ATTESTOR, SYSTEM_SERVICE, VALIDATOR and GOVERNANCE_SIGNER.
Role registration does not grant consensus votes or treasury signing powers.
E1 counts distinct reviewed provider domains after a sustained activation window;
E2 additionally requires separately controlled qualified witnesses and measurement
source. Participation must span the declared window and remain within a ratified
maximum age. Mere registration age is insufficient. Epoch proposals need notice,
timelock and current qualification. Approved stronger epochs cannot be downgraded.
Qualification loss denies new eligible independent claims; it never selects founder
self-attestation. E3 and dynamic validator membership remain unavailable.

New explicitly independent programs bind exact operator IDs and evidence profile.
Their signed offers must disclose trust profile and immutable program-policy hash.
Native funding/usage checks require current E2 eligibility, qualified source and
distinct witness domains; provider-controlled sources fail. Existing legacy programs
retain their encodings and commitments and must be described as signature-quorum
programs with physical independence unqualified. Bootstrap/simulated observations
cannot activate native payments through a configuration flag.

Governed service policy pins image/source, command, role, config schema, budget
reference, revision, activation, grace and rollback fields. Only reviewed metering,
attestor and coordinator classes are accepted. `GovernedServiceDefinition` creates
the existing containerd definition only with exact local opt-in and eligible role.
It explicitly uses uid/gid 65532, no host network and no mounts. The current factory
does not implement artifact distribution, durable cutover, health qualification or
rollback orchestration; those remain source gaps. Existing Ansible seed allowlist is
intentionally still limited to registry and registry-auth until that enforcement is
integrated. Do not replace it with an arbitrary-image allowlist.

Infrastructure compensation is a nonpayable `InfrastructureCostModel` projection.
It never signs its own invoices or debits escrow/reserves. Actual payments require
separate existing treasury governance and ratified cost/budget policy; a meter cannot
certify its own protected subsidy. External equipment/electricity/connectivity costs
and GWD exchange assumptions have not been invented.

Public, bounded native-policy views: `/v1/resources/operators`,
`/v1/resources/system-services`, `/v1/resources/epochs`. Each includes finalized
chain/genesis/hash/root. These are node views; use a trusted locally verified node.
They explicitly distinguish reviewed assertions from independently established facts.
