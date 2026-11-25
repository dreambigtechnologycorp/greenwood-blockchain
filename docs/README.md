# Greenwood documentation

Use these guides to develop applications, contribute code, or bring a homelab
online as a provider of network services. Documentation is organized by **role
and workflow**, not by an operator's machine count. The supported inventory
profiles remain explicit implementation constraints.

Read [project and release status](PROJECT_STATUS.md) before operational work.
It is the single dated record of release boundaries and reported installation
progress. A role guide describes how a service works; it does not certify a host.

## Choose your starting point

| Goal | First | Next |
| --- | --- | --- |
| New contributor | [Developer setup](getting-started/development.md) | [Local quickstart](getting-started/quickstart.md), [contribution guide](../CONTRIBUTING.md) |
| Homelab operator joining the network | [Role-based onboarding](operations/ROLE_BASED_ONBOARDING.md) | [Provider operations](provider-operations.md), [Linux runtime](linux-execution-manifest.md) |
| Application developer | [Hosted workflow](CURRENT_FUNCTIONALITY.md#hosted-applications) | [Controller](../system-services/deployment-controller/README.md), [registry issuer](../system-services/registry-auth/README.md) |
| Contract developer | [Contract quickstart](contracts/quickstart.md) | [ABI](GREENWOOD_WASM_ABI_V1.md), [storage](contracts/storage.md) |
| Client/SDK developer | [Public RPC](rpc-v1.md) | [PHP CLI](cli.md), [JavaScript SDK](../sdk/javascript/README.md) |
| Validator or infrastructure operator | [Role dependencies](operations/ROLE_BASED_ONBOARDING.md) | [Deployment entrypoints](../deploy/README.md), [recovery](architecture/recovery.md) |
| Release maintainer | [Release procedure](RELEASING.md) | [Testing](testing/overview.md), [production packaging](../deploy/production/README.md) |

Joining an existing chain uses its approved genesis and authorities. It does not
require creating your own validators, treasury, CA or network registry. Permissions
for compute, hosted services, independent attestation and validation are distinct;
see the eligibility boundaries in the onboarding guide.

## Protocol, roles and APIs

- [Concepts](concepts/overview.md): accounts, transactions, blocks, consensus,
  contracts and compute.
- [Architecture](architecture/overview.md): component responsibilities and data
  flow; [chain history](architecture/chain-history.md), [state](architecture/state.md),
  [consensus](architecture/consensus-layer.md), [recovery](architecture/recovery.md).
- [Current functionality](CURRENT_FUNCTIONALITY.md) and
  [implementation inventory](IMPLEMENTATION-INVENTORY.md): supported behavior,
  restrictions, source locations and verification.
- [Protocol v1](GREENWOOD_PROTOCOL_V1.md), [WASM ABI v1](GREENWOOD_WASM_ABI_V1.md),
  [compute v1](GREENWOOD_COMPUTE_V1.md): compatibility specifications.
- [Public RPC v1](rpc-v1.md), [operator API](operator-api.md),
  [CLI](cli.md): integration surfaces. RPC detail pages cover
  [queries](rpc/public-api-endpoints-1.md), [transactions](rpc/public-api-transactions.md)
  and [compute](rpc/public-api-compute.md).
- [Compute security](compute-security-v1.md), [execution requirements](linux-execution-manifest.md)
  and [security policy](../SECURITY.md): runtime and trust boundaries.

## Operator workflows

Start with the [operations index](operations/README.md). Select responsibilities,
verify the release and identities, prepare durable state, provision credentials
under operator control, then use the existing guarded installation and acceptance
entrypoints. General participation does not require rebuilding an existing chain.

| Task | Guide |
| --- | --- |
| Select and place roles | [Role-based onboarding](operations/ROLE_BASED_ONBOARDING.md) |
| Configure services and private transport | [Control-plane handoff](../deploy/ansible/CONTROL_PLANE_HANDOFF.md) |
| Prepare storage and migrate retained state | [Durable state storage](../deploy/ansible/STATE_STORAGE.md) |
| Verify ingress, DNS and TLS | [DNS/TLS handoff](operations/RPC_DNS_TLS_HANDOFF.md), [public HTTPS and renewal](operations/PUBLIC_HTTPS_CERTBOT.md) |
| Keep secret operations under human control | [Operator custody policy](../deploy/production/OPERATOR_KEY_CUSTODY.md) |
| Qualify and operate resource services | [Metering](guides/RESOURCE_METERING.md), [resource programs](../system-services/runtime/RESOURCE_WORKFLOWS.md) |
| Preserve evidence and recover | [Retention and recovery](guides/RETENTION_AND_RECOVERY.md), [application restart recovery](../deploy/application-recovery/README.md) |
| Update your deployment | [Self-managed update](guides/SELF_MANAGED_UPDATE.md) |
| Validate physical behavior | [Physical acceptance](../deploy/production/PHYSICAL_ACCEPTANCE.md) |

Named four-/seven-node instructions, certificate inventories and the Tails PDFs
are **reference-profile procedures**. They remain useful for the deployment they
name; adapt roles only within the source's supported identity/topology contract.
They do not prescribe another operator's addresses or custody policy.

- [Seven-node installation](operations/SEVEN_NODE_INSTALLATION.md)
- [Seven-node certificate handoff](operations/SEVEN_NODE_CERTIFICATE_HANDOFF.md)
- [Offline CA and HTTPS custody](operations/TAILS_CA_AND_LETS_ENCRYPT.md)
  ([operator PDF](operations/TAILS_CA_AND_LETS_ENCRYPT.pdf))
- [Initial deployment custody PDF](operations/GREENWOOD_TAILS_KEY_CUSTODY.pdf)

## Economics and policy

The [economics index](economics/README.md) separates protocol invariants, editable
policy templates, the initial operator's adopted policies and historical modeling.
GWD amounts, GRU resource accounting, reserve budgets, customer funding and trust
classes must not be inferred from an assumed token market price.

Dated adoption records retain their approved bytes. A policy example is not a
network-wide approval for a new operator or a changed release.

## Tests, releases and historical material

- [Test overview](testing/overview.md), [unit tests](testing/unit-tests.md),
  [integration tests](testing/integration-tests.md).
- [Source/release procedure](RELEASING.md), [branch protection policy](branch-protection.md).
- [Historical v0.1.0-alpha.1 release](releases/v0.1.0-alpha.1.md).
- [Documentation coverage and audit](DOCUMENTATION-COVERAGE.md).

Tracked reusable tests, installation code and physical-acceptance procedures are
part of source. Operator-specific inventories, credentials, runtime state and
private acceptance outputs are not. Ignored `docs/acceptance/` is for local retained
material, not the only place a reusable verification script may exist.

## Reading authority

Versioned specifications define compatibility. Source and tests show what the
current implementation enforces. Guides explain those behaviors and must surface
any mismatch instead of inventing a command or bypass. Dated evidence establishes
only what was actually tested at its recorded revision and scope.

Changes to protocol behavior require compatibility review. Changes to release
artifacts require new qualification and approval. Editing documentation provides
neither automatically.
