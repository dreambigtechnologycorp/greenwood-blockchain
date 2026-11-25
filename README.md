# Greenwood

Greenwood connects application developers with operators who supply compute,
storage, and network services. This repository contains the PHP blockchain node,
WASM contract runtime, Linux provider, hosted-application control services, and
Ansible deployment tooling.

A homelab participates by taking on supported **roles**. It does not need to copy
another operator's machine count, addresses, network boot arrangement, or hardware.
Roles may share a host where the supported deployment profile and isolation rules
allow it. Joining an existing network is separate from creating a new genesis.

**Release status:** production deployment tooling is implemented; source changes after the
`bd529a5` baseline include unreleased storage and startup corrections. Review the
[dated status](docs/PROJECT_STATUS.md) before deploying. Source tests, an image
build, and a running process each establish different things; production
acceptance must be recorded for the exact release and intended hosts.

## Start here

| Your goal | Guide |
| --- | --- |
| Contribute code or learn the implementation | [Developer setup](docs/getting-started/development.md), then the [local quickstart](docs/getting-started/quickstart.md) |
| Bring homelab capacity onto an existing network | [Role-based operator onboarding](docs/operations/ROLE_BASED_ONBOARDING.md) |
| Deploy an application | [Hosted application workflow](docs/CURRENT_FUNCTIONALITY.md#hosted-applications), [controller API](system-services/deployment-controller/README.md) |
| Build a contract or API client | [Contract quickstart](docs/contracts/quickstart.md), [public RPC](docs/rpc-v1.md), [PHP CLI](docs/cli.md) |
| Prepare a production release | [Release procedure](docs/RELEASING.md), [deployment entrypoints](deploy/README.md) |
| Find all guides and specifications | [Documentation index](docs/README.md) |

## Roles and responsibilities

| Role | Responsibility | Needs |
| --- | --- | --- |
| Validator | Verify blocks and transactions; sign consensus votes | Genesis-authorized identity, peer connectivity, durable signing journal |
| Provider | Advertise eligible capacity and execute accepted work | Qualified Linux runtime, durable state, provider identity, finalized network authority |
| Deployment controller | Coordinate hosted reservations, assignment acknowledgements, start and readiness | Approved scope, signed policy, provider/operator access, authenticated assignment services |
| Observer and assignment receiver | Check physical/runtime facts and install exact approved assignment generations | Role-bound identities, current signed policies, durable rollback protection |
| Edge / RPC frontend | Serve approved application routes and HTTPS API ingress | Verified destinations, TLS, route and content evidence |
| Registry and authorization issuer | Store immutable OCI artifacts and issue scoped publishing/pull authorization | Trusted registry identity, verified chain authority, persistent storage |
| Meter, attestor and settlement coordinator | Collect resource evidence, attest eligible usage and submit settlement | Supported resource profiles, explicit trust class, funded scope and durable evidence |
| Infrastructure host | Supply storage, routing, DNS or optional network boot | Host-specific recovery and availability plan; no automatic protocol authority |

The reference installer has explicit topology and certificate-identity profiles;
role-based documentation does not make those code constraints disappear. See
[supported placement and onboarding](docs/operations/ROLE_BASED_ONBOARDING.md).
An additional provider is not automatically a validator, trusted attestor, or
network administrator.

## What the network records

The chain records canonical transactions, finalized blocks, application ownership
and release authority, compute assignments, and eligible resource settlement.
Contracts execute deterministically through a restricted Wasmtime ABI. Workload
execution happens on provider hosts; a receipt or successful container start is
not independent verification of physical usage.

GWD is the transferable token; GRU is resource accounting, not a second token.
Customer-funded service programs can have zero subsidy budgets while retaining
funding, quote, capacity and trust checks. See the [economics index](docs/economics/README.md).

Consensus uses a genesis-fixed, equal-power validator set with quorum
`floor(2 * n / 3) + 1` (minimum three validators). The local four-validator fixture
requires three votes; a three-validator network requires all three. Operating
several machines under one administrator does not create independent operators.

## Developer setup

Use PHP 8.5 (release qualification pins **8.5.12**), Composer 2, Sodium,
PDO SQLite and FFI. Contract/source integration tests require the pinned
**Wasmtime 48.0.1** library for the host architecture. Provider work additionally
requires the Linux runtime described in the [execution manifest](docs/linux-execution-manifest.md).

From a clean source checkout:

```bash
composer install
composer validate --no-check-publish
composer run test:source
composer run test:contracts
```

Follow [developer setup](docs/getting-started/development.md) for library paths,
Python tooling and test boundaries. The [local quickstart](docs/getting-started/quickstart.md)
creates disposable development identities and runs a private fixture; it never
joins or initializes the production network.

## Repository map

| Path | Contents |
| --- | --- |
| `src/`, `bin/` | Protocol, node, provider and PHP command-line entrypoints |
| `system-services/` | Controller, registry authorization, metering and service runtimes |
| `sdk/javascript/` | Resource-client and signing helpers |
| `tests/` | Protocol vectors, PHP suites, resource and release checks |
| `deploy/` | Runtime preparation, guarded installation, storage and acceptance procedures |
| `docs/` | Role-based guides, API references, specifications and labeled historical records |
| `scripts/` | Source packaging, qualification, OCI builds and operator helpers |
| `runtime/` | Pinned ABI/schema inputs and generated containerd bindings |

`bin/greenwood` is the PHP RPC/operator client. `bin/greenwood-cli` is the local
key/genesis/signing helper. The Python application-deployment CLI lives in a
separate repository; its commands are not interchangeable with either PHP tool.

## Operations and contribution

Production installation requires exact source/image/configuration pins, verified
public identities, operator-controlled secret provisioning, current signed
policies and explicit activation approval. Canonical state and containerd storage
must use the qualified durable filesystem path. Current source uses SQLite
`DELETE` rollback journals with `synchronous=EXTRA`; existing WAL databases require
the guarded offline migration. Changing journal mode does not qualify NFS for
canonical state. See [storage preparation](deploy/ansible/STATE_STORAGE.md).

Read [CONTRIBUTING](CONTRIBUTING.md), [testing](docs/testing/overview.md), and
[SECURITY](SECURITY.md). Original Greenwood source is licensed under
[Apache-2.0](LICENSE); [third-party notices](THIRD_PARTY_NOTICES.md) and the
[trademark policy](TRADEMARKS.md) cover their respective materials.
