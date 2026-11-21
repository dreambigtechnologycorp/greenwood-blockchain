# Seven-node certificate and activation handoff

Use only a reviewed release containing the seven-node receiver and HTTPS startup
fixes. This guide does not approve genesis, spending, CA authority or activation.
Private-key operations below are for the human operator, outside the AI session.
The existing offline custody PDF remains a key-generation reference; use this
supplement for the expanded assignment certificate roles.

## 1. Review the public certificate inventory

For chain `greenwood-prod-v1`, use these exact control-plane URI SANs:

| Service | Node | URI SAN |
| --- | --- | --- |
| Deployment controller | 1 | `spiffe://greenwood-prod-v1/control-plane/deployment-controller` |
| Assignment receiver | 1 | `spiffe://greenwood-prod-v1/control-plane/assignment/node-1` |
| Assignment receiver | 2 | `spiffe://greenwood-prod-v1/control-plane/assignment/node-2` |
| Assignment receiver | 3 | `spiffe://greenwood-prod-v1/control-plane/assignment/node-3` |
| Assignment receiver | 5 | `spiffe://greenwood-prod-v1/control-plane/assignment/node-5` |
| Assignment receiver | 6 | `spiffe://greenwood-prod-v1/control-plane/assignment/node-6` |
| Assignment receiver | 7 | `spiffe://greenwood-prod-v1/control-plane/assignment/node-7` |
| Edge admission | 5 | `spiffe://greenwood-prod-v1/control-plane/edge-admission` |
| Edge controller | 5, if configured | `spiffe://greenwood-prod-v1/control-plane/edge-controller` |
| Provider observer | 1, 2, 3, 6, 7 respectively | `spiffe://greenwood-prod-v1/control-plane/provider/node-N` |

Use separate receiver credentials, never a node-4 identity on node-5. Node-4
remains gateway/netboot-only. Each control-plane certificate must contain exactly
its intended URI SAN, an approved DNS SAN matching its configured `tls_name`, and
serverAuth/clientAuth extended key usages. Supply each public CSR and approved DNS
name to your approved certificate issuer. A key manifest or CN alone is insufficient.
The issuer must enforce the reviewed extensions; do not blindly copy CSR extensions.

RPC HTTPS certificates are separate server certificates for
`rpc-node-1.greenwoodblockchain.com`, `rpc-node-2.greenwoodblockchain.com`,
`rpc-node-3.greenwoodblockchain.com`, and `rpc-node-5.greenwoodblockchain.com`.
Registry HTTPS uses `registry.greenwoodblockchain.com`; the proposed separate
registry-auth hostname still requires operator confirmation. See
[RPC DNS/TLS handoff](RPC_DNS_TLS_HANDOFF.md) for Cloudflare and direct-LAN routing.

## 2. Verify certificates yourself and return public material

On your custody machine, substitute the reviewed paths and DNS name below. Run
for each issued leaf (add `-untrusted intermediates.pem` for an intermediate CA):

```sh
openssl verify -CAfile ca.pem -purpose sslserver -verify_hostname APPROVED_DNS leaf.pem
openssl x509 -in leaf.pem -noout -dates -ext subjectAltName,extendedKeyUsage
openssl x509 -in leaf.pem -noout -fingerprint -sha256
openssl x509 -in leaf.pem -pubkey -noout | openssl pkey -pubin -outform DER | sha256sum
openssl pkey -in /PRIVATE/existing-service.key -pubout -outform DER | sha256sum
```

The last two hashes must match. This last command accesses a private key: run it
yourself, never through the assistant. For mTLS leaves, also verify `-purpose
sslclient` and inspect the URI SAN. A public TLS CA may not issue these private
control-plane URI identities; use the explicitly approved control-plane issuer.

Return public CA/intermediate/leaf certificates, each role/node mapping and DNS
name, certificate SHA-256 fingerprints, and PEM file SHA-256 checksums. Do not
return private keys, decrypted vaults, CA signing keys, SFTP private files or
Cloudflare tunnel credentials. Fingerprints hash certificates; file checksums
hash PEM files and are not interchangeable.

For each existing policy-transfer SSH identity, export only its public key:

```sh
umask 077
ssh-keygen -y -f /PRIVATE/existing-policy-transfer-key > policy-transfer.pub
ssh-keygen -lf policy-transfer.pub -E sha256
```

Keep administrative SSH and policy-transfer identities separate. The SFTP host
key must be independently verified and pinned before clients are enabled.

## 3. Bind public inputs and qualify the final release

Populate the same complete `assignment_receivers` map on all assignment
consumers: nodes 1, 2, 3, 5, 6, 7, each with its reviewed endpoint, DNS name and
principal identity. Populate certificate fingerprint-to-identity and
fingerprint-to-URI maps. Every configured receiver must acknowledge the same
committed generation freshly. This is not an independent-attestation quorum.

Observers/controllers use `unix:///run/greenwood-provider/operator.sock` and
`/etc/greenwood/operator.token`; the validator socket remains separate.

Review the complete public inventory, genesis and policy hashes. Then commit and
rebuild the reviewed source; run clean-revision qualification and record the new
source revision, OCI digests and bundle checksum. Do not approve an old release
hash as though it includes these source fixes. Keep activation flags false until
you approve the exact final artifacts and installation scope.

## 4. Operator-only installation and acceptance

Use the existing [fresh-cluster procedure](../../deploy/ansible/FRESH_CLUSTER.md)
and [custody boundary](../../deploy/production/OPERATOR_KEY_CUSTODY.md).
Install private credentials yourself with the required owners/modes. Sign initial
policy snapshots yourself, close to startup because they expire; enable their
approved renewal/transfer workflow. Never reset an existing policy to an empty
snapshot. Initial snapshots do not approve an application allocation or spending.

Run the guarded preflight yourself once secrets are installed: it may inspect
private files. Only after all gates pass, authorize and run the documented
installation/activation procedure. Verify finality, RPC identity, registry,
provider registration, funded workloads, metering, settlement and recovery.
Local tests do not replace physical acceptance evidence.

## Detailed runbooks

- [Offline Tails CA creation, issuance and public handoff](TAILS_CA_AND_LETS_ENCRYPT.md).
- [Public HTTPS, Certbot and Cloudflare deployment](PUBLIC_HTTPS_CERTBOT.md).
