# Network-start readiness review

This is a source/deployment-input audit, not physical acceptance. No production keys or cluster services are used by the local tests.
TLS and signing tests use disposable local fixtures. Do not label this the final rebuild until
the exact configuration is complete and the final reviewed revision is qualified.

## Source defects addressed together

- HTTPS RPC upstreams for node-5, with CA, hostname and expiry verification.
- Consistent public Cloudflare HTTPS 443 versus LAN origin 8443 configuration.
- Stage application-hosting settings and control-plane/TLS before provider startup;
  remove the premature provider restart from the hosting-configuration phase.
- Start validator HTTPS origins before relay origins, and verify chain/genesis and
  finalized height before allowing providers to start.
- Pin/install public CA trust for provider-only clients; Apache trust is separate.
- Reject cycles and public-edge dependencies in bootstrap endpoint declarations.
- Stop installation on a control-plane staging failure on any host.

## Seven-node assignment and observer fixes

The protected `assignment_receivers` map now selects the receiver topology for
all distribution, reconciliation and coordinator acknowledgement checks. The
seven-node profile requires exactly nodes 1, 2, 3, 5, 6 and 7. Node 4 remains
outside assignment hosting. Every configured receiver must acknowledge the same
committed generation freshly; this is replication, not an independent-operator
quorum. An unavailable receiver blocks new assignment admission. The explicit
legacy node-1/node-4 profile remains supported for older installations and tests,
but the seven-node installer rejects it.

Receiver certificate URI SANs use
`spiffe://CHAIN_ID/control-plane/assignment/node-N` for each selected node.
Each receiver needs its own pinned certificate, endpoint and TLS DNS name; use
the same complete receiver map in controllers, receivers, edge and timer configs.
Provider observer URIs remain restricted to nodes 1, 2, 3, 6 and 7. New URI support
does not authorize any certificate until its fingerprint and role are configured.

The base provider service enables its authenticated local Unix listener at
`/run/greenwood-provider/operator.sock`, using `/etc/greenwood/operator.token`.
This is distinct from the validator socket. No application TCP listener is enabled
by this change. The installer rejects observers/controllers configured with a
remote operator URL or a different token path. Credentials remain operator inputs.

## Public inputs that can be prepared offline

Build the Application Registry WASM and derive its contract ID using the existing
`ContractId::fromWasm` implementation. Record the contract/code identity separately
from the artifact's file SHA-256. A deterministic ID is not evidence of deployment;
require a finalized deployment receipt after authorized startup.

Public identity manifests can supply the policy verification keys, registry token
issuer public key and separate system-service publisher identity. They confer no
publishing authority by themselves. A token issuer public-key PEM is not an X.509
verification certificate, and neither replaces a registry HTTPS certificate.

Prepare Cloudflare ingress fragments, CA checksum placeholders, SFTP public-key
handoff instructions and unsigned configuration candidates without reading tunnel
credentials or signing keys. Initial signed policies and their renewal remain
operator steps. An empty initial allocation must not overwrite an existing policy.

## Remaining input gates before first start

| Gate | Required completion |
| --- | --- |
| Final source/artifacts | Review and merge the complete pending changes; qualify/build once from the final commit; replace source/image pins. Old 1d8a0bd lacks these fixes. |
| Genesis/policy | Approve exact public genesis, policy and chain-config hashes; record custody review. Identity bindings and validation alone are not approval. |
| RPC TLS | Four issued RPC certificates, per-node private keys installed by operator, public CA PEM checksum and DER fingerprint; all matching protected paths. |
| Internal resolution | Node-5 and providers 6–7 must resolve node-1's RPC name to its LAN IP for direct 8443 HTTPS; no tunnel login dependency. |
| Complete control-plane inputs | Per-host controller/observer/receiver/edge configs, provider identity/URI bindings, signed policy snapshots, renewal/SFTP transport and public trust. Blank dictionaries are not a deployable composition. |
| Application hosts | Approved provider/edge addresses, nftables/network policy, hosted funding configuration and application receiver settings for nodes 6–7. |
| Registry bootstrap | Exact offline seeds and system-service definitions, issued TLS, separate token issuer identity, bearer realm, volume/network settings and reviewed publisher authorization. |
| Operator provisioning | Actual local credential locations, required modes/owners, matching identities. No AI key access. |
| Host/recovery | Fresh identity verification and recovery access; existing gateway/NFS preserved. Read-only runtime checks do not establish outage recovery. |
| Authorization | Explicit scoped installation, secret provisioning, protocol and hosting approvals. Keep false until operator action. |

Cloudflare DNS/ingress and access policy must be ready before public cutover, but
need not be public for direct-LAN protocol startup. No Cloudflare credentials are
required in the public preparation files. Keep existing tunnel routes intact.

## After startup, before claiming service readiness

Verify actual consensus finality, registry authorization, provider registration,
Application Registry contract deployment/authority, funded container and hosted
application execution, metering, settlement/refunds/disputes and restart/recovery.
Governed services require their own approved finalized policy and consent. Never
treat DreamBIG-only telemetry as independent verification. Source tests and a
healthy RPC response do not replace this physical evidence.

## Validation boundary

Local regression tests exercise inventory validation, real Ansible readiness
assertions against public fixtures (wrong chain/genesis/zero finality rejected),
seven-host dependency declarations and cycle rejection. Syntax checks parse the
complete installer without running provisioning. No actual systemd, TLS key
provisioning or physical installation is performed by these tests.
