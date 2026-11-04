# Application Registry

[ApplicationRegistry.ts](ApplicationRegistry.ts) implements application ownership,
delegated capabilities and immutable digest-pinned releases. Consumers must bind
authority to chain ID, registry contract ID, application and signer; numeric
application IDs alone are not globally unique.

## Authority and Methods

Capabilities are PUBLISH=1, DEPLOY=2, OPERATE=4 and ADMIN=8. The controller account
has all capabilities. Delegates are scoped to the application's current epoch.
Ownership acceptance advances that epoch, invalidating prior delegate grants.

| Method | Calldata | Effect or return |
| --- | --- | --- |
| cre | empty | Caller becomes controller; returns u32 application ID |
| pro | u32 app + account32 | Only controller proposes a successor |
| acc | u32 app | Only pending controller accepts ownership |
| gra | u32 app + account32 + u16 mask | Controller/ADMIN grants nonzero known capability bits |
| rev | u32 app + account32 | Controller/ADMIN revokes delegate |
| pub | u32 app + u16 count + service entries | PUBLISH creates release; returns u32 release number |
| act | u32 app + u32 release | DEPLOY selects an existing release |
| qap | u32 app | 80-byte application record |
| qde | u32 app + account32 | Current-epoch delegate mask; missing delegate traps |
| qau | u32 app + account32 | Effective u16 capability mask, zero for unauthorized account |
| qre | u32 app + u32 release | 38-byte release header |
| qrs | u32 app + u32 release + service + NUL | Raw 32-byte digest |
| qac | u32 app | u32 active release number |

All integers are big-endian. Each pub entry is u8 name length, name bytes and raw
32-byte digest. A release has 1-32 unique services. Names match
[a-z][a-z0-9-]{0,62}. The contract validates complete framing before publication.
Its total invocation input is bounded to 4,096 bytes, including the outer ABI.

Outer input is u32 method length, three method bytes, u32 calldata length, then
calldata, as defined by [the contract ABI](../../docs/contracts/overview.md).
Denied or malformed operations trap. ID results use return_value; do not interpret
the WASM scalar return as receipt output. Confirm finalized poststate as well as
receipt identity.

The application record contains version, controller32, pending_controller32,
epoch, active_release and release_count. The release header contains release
number, publisher32 and u16 service count. Code has no deployer-admin dependency;
application authority belongs to each application's controller/delegates.

## Reproducible Build

From the repository root, use an approved local AssemblyScript 0.28.20 package:

```sh
GREENWOOD_ASSEMBLYSCRIPT_ROOT=/approved/assemblyscript-0.28.20 \
  contracts/application-registry-next/build.sh /tmp/application-registry.wasm
```

The path identifies the compiler package directory containing package.json and
bin/asc.js. build.sh verifies its exact version and applies fixed runtime,
memory and optimization flags. It does not install dependencies or submit anything.
package-lock.json provides the dependency lock for a separately approved npm ci
installation in this directory.

Verify the generated code identity against the contract pinned by the intended
client and service configuration before use. A build result alone does not
establish finalized contract state or authorize replacing a registry.

Application registration, release publication and activation use the native
developer CLI and finalized-state checks. They do not require redeploying the
registry for each application. Preserve transaction journals and reconcile uncertain
submissions before any new operation. See [controller operations](../../system-services/deployment-controller/README.md).
