# Operator onboarding and runbooks

Start with [role-based onboarding](ROLE_BASED_ONBOARDING.md) to run a node,
provider, hosting or infrastructure service in your own environment. It separates
joining an existing chain from creating a new network, and identifies current
installer limitations. [Documentation home](../README.md) covers architecture,
development and economics. [Project status](../PROJECT_STATUS.md) records the
checkout's release state and the separate reference deployment's progress.

## Read in this order

1. [Service roles and participation](ROLE_BASED_ONBOARDING.md) explains dependencies,
   operator identity, existing-chain participation and supported provisioning.
   [Four/seven-node placement](SEVEN_NODE_INSTALLATION.md) is a reference example.
2. [Operator custody boundary](../../deploy/production/OPERATOR_KEY_CUSTODY.md)
   separates public review from credential-bearing commands. Reuse existing matching identities; the reference operator has already provisioned
   its credentials. Do not restart generation merely to continue an installation.
3. [Deployment entrypoints](../../deploy/README.md) selects fresh installation,
   bounded continuation or maintenance. For the interrupted reference installation,
   follow [durable storage](../../deploy/ansible/STATE_STORAGE.md), rebuilt-release
   verification and then the guarded
   [control-plane continuation](../../deploy/ansible/FRESH_CLUSTER.md#continue-after-runtime-and-identity-staging).
4. [Release preparation](PRODUCTION_REBUILD.md),
   [control-plane operations](../../deploy/ansible/CONTROL_PLANE_HANDOFF.md) and
   [physical acceptance](../../deploy/production/PHYSICAL_ACCEPTANCE.md) define
   the remaining artifact, configuration, startup and evidence boundaries.

Do not rerun fresh initialization against installed genesis or identities. Do not
use `--limit` or `--start-at-task` to bypass a continuation's all-host barriers.
Retain state, journals, receipts and credential identities after any interruption.
Private inventories, key validation, signing, storage authentication and live
activation remain human-operator operations. A read-only label or check mode does
not establish that a command avoids private-file access.

## Task references

| Task | Procedure |
| --- | --- |
| Review source and build an immutable release | [Self-managed update](../guides/SELF_MANAGED_UPDATE.md), [production bundle](../../deploy/production/README.md) |
| Understand first-start ordering and outstanding gates | [Network-start readiness](NETWORK_START_READINESS.md) |
| Validate or install runtime prerequisites | [Runtime prerequisites](RUNTIME_PREREQUISITES.md) |
| Maintain packages on NFS-root hosts | [NFS package maintenance](NFS_PACKAGE_MAINTENANCE.md) |
| Review internal TLS identities and public return material | [Certificate handoff](SEVEN_NODE_CERTIFICATE_HANDOFF.md) |
| Maintain the existing offline CA | [Offline CA procedure](TAILS_CA_AND_LETS_ENCRYPT.md) |
| Configure public HTTPS and renewal | [Certbot procedure](PUBLIC_HTTPS_CERTBOT.md), [RPC/DNS/tunnel routing](RPC_DNS_TLS_HANDOFF.md) |
| Generate identities for a genuinely new installation or approved replacement | [Operator key generation](OPERATOR_KEY_GENERATION.md); skip for existing identities |
| Enable hosted recovery after readiness and funding configuration | [Application restart recovery](../../deploy/application-recovery/README.md) |
| Collect and settle resource usage | [Resource metering](../guides/RESOURCE_METERING.md), [source adapters](../guides/SOURCE_ADAPTER_INTEGRATION.md) |
| Back up evidence and understand governance recovery | [Retention and recovery](../guides/RETENTION_AND_RECOVERY.md) |

## Historical evidence

These records concern the reference deployment. They preserve their dated findings, counts and constraints. They are
not current installation instructions. In particular, references to missing
runtimes, ungenerated identities, unratified selected policy, four-node-only
support or node-6's old link problem must be read in their dated context.
The [November 18 policy adoption](../economics/PRODUCTION_POLICY_ADOPTION_2026-11-18.md)
records the selected operator decisions; it does not prove custody, on-chain
execution or physical acceptance.

- November 14: [operator-registry candidate](operator-onboarding.md) and
  [metering candidate](resource-metering.md).
- November 15: [production preparation](PRODUCTION_PREPARATION_2026-11-15.md).
- November 16: [node-4 assessment](NODE4_AND_SEVEN_NODE_ASSESSMENT_2026-11-16.md),
  [Ansible expansion audit](ANSIBLE_EXPANSION_INSPECTION_2026-11-16.md),
  [source scope](../guides/SOURCE_SCOPE_STATUS.md) and
  [source validation](../guides/SOURCE_VALIDATION.md).
- November 17: [seven-node preflight](SEVEN_NODE_PREFLIGHT_2026-11-17.md) and
  [testnet removal](TESTNET_REMOVAL_2026-11-17.md).
- November 18: [capacity audit](CAPACITY_AUDIT_2026-11-18.md) and
  [policy/release review](PRODUCTION_POLICY_RELEASE_REVIEW_2026-11-18.md).
  The latter retains its original bytes and historical source/image hashes;
  those hashes are not current deployment pins.
