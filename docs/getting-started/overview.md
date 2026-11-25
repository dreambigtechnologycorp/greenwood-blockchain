# Getting started

Choose a workflow before installing anything. Greenwood has separate interfaces
for application developers, infrastructure operators, and protocol contributors.

| You want to… | Start here |
| --- | --- |
| Develop the node or understand consensus | [Developer setup](development.md), then the [local network](quickstart.md) |
| Supply compute from a homelab | [Role-based onboarding](../operations/ROLE_BASED_ONBOARDING.md), then [provider operations](../provider-operations.md) |
| Deploy a hosted application | [Application workflow](../CURRENT_FUNCTIONALITY.md#hosted-applications), then [controller documentation](../../system-services/deployment-controller/README.md) |
| Build a smart contract | [Contract quickstart](../contracts/quickstart.md) and [WASM ABI](../GREENWOOD_WASM_ABI_V1.md) |
| Integrate a client | [RPC reference](../rpc-v1.md), [PHP CLI](../cli.md), [JavaScript resource SDK](../../sdk/javascript/README.md) |
| Operate network infrastructure | [Roles and dependencies](../operations/ROLE_BASED_ONBOARDING.md), [deployment guide](../../deploy/README.md) |

## Concepts to establish first

A **validator** signs consensus votes under genesis authority. A **provider**
executes work under eligibility and assignment rules. A **controller** coordinates
hosted application deployment. An **edge** publishes approved routes. The registry,
policy distribution, observers and resource services support these roles. They
are logical responsibilities, not a required number of physical computers.

Joining a network means using its approved public genesis, chain configuration,
trust roots and endpoints. Running the local genesis helper creates a separate
network. It cannot grant membership in an existing validator set. See
[core concepts](../concepts/overview.md) and [current source functionality](../CURRENT_FUNCTIONALITY.md).

## Tools in this checkout

| Entry point | Intended use |
| --- | --- |
| `bin/greenwoodd` | Node daemon and offline replay; validator mode needs an authorized validator identity |
| `bin/greenwood-provider` | Linux provider supervisor using a protected configuration |
| `bin/greenwood` | RPC queries, transaction submission and authenticated operator calls |
| `bin/greenwood-cli` | Disposable genesis, local key creation and signing supported envelope types |

The separate Python CLI handles application registration, build/push and hosted
deployment workflows. Check its own release and guide before using it; PHP tool
names and flags are not substitutes.

For production, start with [project status](../PROJECT_STATUS.md) and the exact
release manifest. A successful tutorial is development evidence, not acceptance
of production custody, storage, ingress or recovery.
