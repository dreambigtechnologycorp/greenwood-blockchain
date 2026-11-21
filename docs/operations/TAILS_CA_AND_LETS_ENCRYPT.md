# Greenwood: offline private CA and public HTTPS certificates

Operator runbook · 21 November 2026 · chain `greenwood-prod-v1`

These commands are for **you to run**, outside the AI session. Preparing this guide
has not created a CA, generated keys, issued certificates or changed any server.
Do not paste private keys, passphrases, decrypted vaults or Cloudflare API tokens
into chat. Public certificates, CSRs and public-key fingerprints may be returned.

## 1. Separate the two certificate systems

| Purpose | Issuer | Where private material lives |
| --- | --- | --- |
| Internal controller, receivers, observers and edge mTLS | Your offline Greenwood CA | CA key stays in encrypted Tails Persistent Storage; each node receives only its own service key |
| Public HTTPS for RPC and registry | Let's Encrypt through Certbot DNS-01 | Separate online operator environment and the destination server; never the offline CA volume |

Recommended initial private-CA profile: one offline RSA-4096 root, valid for ten
years, directly issuing RSA-3072 service certificates valid for one year. No online
intermediate is necessary for this initial cluster. Direct issuance keeps the
current Greenwood CA-file integration simple. These are proposed operational
settings: running the creation steps means you accept this profile, not that you
approve treasury spending or activate the network.

The private CA is free software you operate. Its certificate is public; its signing
key is extremely sensitive. Never put that signing key on node-4's shared storage
or any production node. Maintain encrypted backups before relying on it.

## 2. Prepare Tails before going offline

Use the Tails drive with encrypted Persistent Storage enabled. Confirm OpenSSL is
available, save this guide and the public identity inventory to Persistent Storage,
and verify the current date/time. Obtain any required software while online;
finish that preparation before importing or creating CA secret material.

```sh
openssl version
date -u
ls -ld "$HOME/Persistent"
```

Then disconnect networking in Tails, unplug Ethernet and keep wireless disabled.
Do the private-CA work below offline. Do not reconnect this custody session for
Let's Encrypt: its DNS validation belongs in the separate online environment.

## 3. Create the offline CA once

Open a terminal in offline Tails. The following refuses to reuse an existing CA
directory. If it exists, stop and inspect your existing custody records yourself;
do not delete it or rerun initialization over it.

```sh
set -eu
umask 077
CA_DIR="$HOME/Persistent/greenwood-prod-v1-ca"
test -d "$HOME/Persistent"
test ! -e "$CA_DIR"
mkdir -m 700 "$CA_DIR"
cd "$CA_DIR"
mkdir -m 700 private certs newcerts csr services public-handoff
touch index.txt
printf 'unique_subject = no\n' > index.txt.attr
printf '1000\n' > serial
cat > openssl.cnf <<'CONFIG'
[ ca ]
default_ca = GreenwoodCA
[ GreenwoodCA ]
dir = .
database = $dir/index.txt
new_certs_dir = $dir/newcerts
certificate = $dir/certs/greenwood-root-ca.pem
private_key = $dir/private/greenwood-root-ca.key.pem
serial = $dir/serial
default_md = sha256
default_days = 365
policy = service_policy
unique_subject = no
name_opt = ca_default
cert_opt = ca_default
copy_extensions = none
[ service_policy ]
commonName = supplied
[ req ]
prompt = no
distinguished_name = root_dn
x509_extensions = root_ca
[ root_dn ]
CN = Greenwood prod-v1 Offline Root CA
[ root_ca ]
basicConstraints = critical,CA:true,pathlen:0
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always
CONFIG
openssl genpkey -algorithm RSA -aes-256-cbc \
  -pkeyopt rsa_keygen_bits:4096 -out private/greenwood-root-ca.key.pem
openssl req -new -x509 -sha256 -days 3650 -config openssl.cnf \
  -key private/greenwood-root-ca.key.pem -out certs/greenwood-root-ca.pem
openssl verify -CAfile certs/greenwood-root-ca.pem certs/greenwood-root-ca.pem
openssl x509 -in certs/greenwood-root-ca.pem -noout \
  -subject -issuer -dates -ext basicConstraints,keyUsage -fingerprint -sha256
cp certs/greenwood-root-ca.pem public-handoff/
```

OpenSSL prompts for the root-key passphrase. Use a strong unique passphrase kept
in your offline custody records, not a command argument or environment variable.
Expected certificate extensions: `CA:TRUE, pathlen:0`, Certificate Sign and CRL Sign.
This root cannot issue an intermediate CA. Keep the whole CA directory, including
its database and serial files, together in encrypted backups. Never run two
independent copies of this database as simultaneous issuing authorities.

## 4. Review the internal service names

The following is a **proposed internal DNS naming convention**, not a claim that
these names already exist. Confirm it before signing and use the exact names as
`tls_name` in the inventory. Connections can use the reviewed LAN IP in their
`tcp://IP:PORT` endpoint while TLS verifies the explicit DNS name; these names do
not need public DNS or Cloudflare ingress. Do not send internal mTLS through a
public HTTP tunnel.

Create this public plan in the CA directory:

```sh
cat > services.tsv <<'PLAN'
controller-node-1 node-1 deployment-controller
receiver-node-1 node-1 assignment/node-1
receiver-node-2 node-2 assignment/node-2
receiver-node-3 node-3 assignment/node-3
receiver-node-5 node-5 assignment/node-5
receiver-node-6 node-6 assignment/node-6
receiver-node-7 node-7 assignment/node-7
observer-node-1 node-1 provider/node-1
observer-node-2 node-2 provider/node-2
observer-node-3 node-3 provider/node-3
observer-node-6 node-6 provider/node-6
observer-node-7 node-7 provider/node-7
edge-admission-node-5 node-5 edge-admission
PLAN
cat services.tsv
```

Each leaf gets DNS `node-N.control.greenwoodblockchain.com` and exactly one URI
`spiffe://greenwood-prod-v1/control-plane/ROLE`. Multiple distinct service keys may
share a node's DNS name; their URI and certificate fingerprints distinguish them.
If the reviewed configuration also uses a separate edge-controller client, add:

```text
edge-controller-node-5 node-5 edge-controller
```

Node-4 gets no assignment certificate. Do not replace existing validator,
provider signing, governance, registry-token or policy-signing keys with TLS keys.
The supplied public manifests did not contain these internal TLS certificates.
If you already generated a matching service TLS key elsewhere, import only its
public CSR for signing rather than generating a second identity unnecessarily.

## 5. Issue one internal certificate per service

Remain offline, in the same CA directory. Save this helper as `issue-service.sh`:

```sh
#!/bin/sh
set -eu
umask 077
name=${1:?service name required}
node=${2:?node name required}
role=${3:?role required}
# Only a row already reviewed in the public plan may be issued.
grep -Fx -- "$name $node $role" services.tsv >/dev/null
case "$node" in node-1|node-2|node-3|node-5|node-6|node-7) ;; *) exit 1;; esac
case "$name" in *[!a-z0-9-]*|'') exit 1;; esac
dns="$node.control.greenwoodblockchain.com"
uri="spiffe://greenwood-prod-v1/control-plane/$role"
test ! -e "certs/$name.pem"
# Existing public CSR: reuse it after personally reviewing its identity.
# No CSR: create a new, encrypted TLS service key and its public request.
if [ ! -e "csr/$name.pem" ]; then
  test ! -e "services/$name.key.pem"
  openssl genpkey -algorithm RSA -aes-256-cbc \
    -pkeyopt rsa_keygen_bits:3072 -out "services/$name.key.pem"
  openssl req -new -sha256 -key "services/$name.key.pem" \
    -subj "/CN=$dns" -out "csr/$name.pem"
fi
openssl req -in "csr/$name.pem" -noout -verify -subject
cat > "csr/$name.ext" <<EXT
[ service ]
basicConstraints = critical,CA:false
keyUsage = critical,digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth,clientAuth
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
subjectAltName = DNS:$dns,URI:$uri
EXT
# No -batch: personally confirm issuance when OpenSSL asks.
openssl ca -config openssl.cnf -extensions service \
  -extfile "csr/$name.ext" -days 365 -notext \
  -in "csr/$name.pem" -out "certs/$name.pem"
openssl verify -CAfile certs/greenwood-root-ca.pem -purpose sslserver \
  -verify_hostname "$dns" "certs/$name.pem"
openssl verify -CAfile certs/greenwood-root-ca.pem -purpose sslclient "certs/$name.pem"
openssl x509 -in "certs/$name.pem" -noout -dates \
  -ext basicConstraints,keyUsage,extendedKeyUsage,subjectAltName -fingerprint -sha256
cp "certs/$name.pem" public-handoff/
cp "csr/$name.pem" "public-handoff/$name.csr.pem"
```

Run it explicitly for each row, for example:

```sh
sh issue-service.sh receiver-node-6 node-6 assignment/node-6
```

Repeat for the other reviewed rows. The commands prompt for service-key and root
passphrases. Inspect the output: CA:FALSE; serverAuth/clientAuth; the exact DNS SAN;
and exactly one correct chain/role URI SAN. CSR extensions are not copied: the
reviewed extension file determines the issued identity. A CSR's self-signature
proves possession of its key, not permission to assume a Greenwood role.

For a locally generated key, compare these two public-key hashes yourself:

```sh
openssl pkey -in services/receiver-node-6.key.pem -pubout -outform DER | sha256sum
openssl x509 -in certs/receiver-node-6.pem -pubkey -noout \
  | openssl pkey -pubin -outform DER | sha256sum
```

They must match. For an imported CSR, its custodian checks against the original
private key. Do not send that key back for review.

## 6. Export only public handoff material

```sh
cp services.tsv public-handoff/
for cert in public-handoff/*.pem; do
  case "$cert" in *.csr.pem) continue;; esac
  openssl x509 -in "$cert" -noout -subject -fingerprint -sha256
done > public-handoff/certificate-fingerprints.txt
(cd public-handoff && sha256sum *.pem services.tsv certificate-fingerprints.txt > SHA256SUMS)
```

Copy **only `public-handoff/`** to your public-transfer drive. Do not copy its
parent directory, `private/`, `services/`, encrypted vaults or runtime-key exports.
Public certificate fingerprints become the inventory's lowercase, colon-free
64-character pins. PEM checksums are separate. Record your explicit approval of
the root fingerprint; merely receiving a CA certificate must not grant trust.

Create and verify encrypted backups of the complete CA custody directory yourself.
No private material needs to be returned to the assistant, even encrypted.

## 7. Public HTTPS keys: keep them separate from the offline CA

Let's Encrypt/Certbot issues public HTTPS certificates in a separate online
environment. Its normal issuance creates a TLS private key and an ACME account
key; run those operations yourself. These are not blockchain signing keys and
must not be confused with the internal Greenwood CA key.

Follow the [public HTTPS deployment runbook](PUBLIC_HTTPS_CERTBOT.md) for the
online issuance commands, Cloudflare DNS validation, trust chains and renewal.
Keep this offline CA drive disconnected during that work. Never return Certbot's
`privkey.pem`, ACME account private files or API tokens. Return only `cert.pem`,
`chain.pem` and `fullchain.pem`, clearly labelled by hostname.

Decide whether to adopt a new Certbot-managed registry TLS identity or retain the
existing registry CSR/key. Standard Certbot issuance does not use the existing
CSR; the separate `--csr` workflow has different renewal requirements. Do not
silently replace an existing identity.

## 8. Install internal runtime keys yourself, only after approval

Greenwood's internal service does not prompt for a key passphrase. Keep the
passphrase-encrypted master offline; create an unencrypted **installation copy**
only on encrypted operator-controlled media. Example for an existing local leaf:

```sh
umask 077
mkdir -m 700 runtime-export
# Stop if this directory already exists; never overwrite an earlier export.
openssl pkey -in services/receiver-node-6.key.pem \
  -out runtime-export/receiver-node-6.key.pem
chmod 600 runtime-export/receiver-node-6.key.pem
```

This directory contains private material. Never include it in public handoff.
Install only each node's own key/certificate through the documented operator-only
procedure, with root ownership and key mode 0600. Do not install the CA signing key.
NFS-root client private files also reside on node-4's SSD: this is shared operator
custody, not independent isolation. Protect that storage and recovery access.

## 9. Renewal, revocation and final release order

- Track each internal leaf's actual expiry; begin renewal at least 30 days early.
  Issue a new versioned certificate, review its fingerprint, update peer pins and
  restart/reload consumers in a coordinated operator window. Old pins do not
  automatically accept a renewed certificate, even when the key is reused.
- If a leaf is compromised, publish its certificate fingerprint through Greenwood's
  signed revocation-policy workflow, then rotate the credential and approved pins.
  An OpenSSL CA database revocation or CRL alone is not consumed by the current
  Greenwood runtime. Keep revocation snapshots fresh and approved.
- Back up CA database changes after every issuance. Loss of the root key requires
  an explicitly approved trust migration; do not recreate a different root under
  the same filename and assume peers will trust it.
- Bind returned public certificates and final DNS/SFTP information, finish HTTPS
  chain/renewal integration, review and commit source, rebuild and qualify the
  exact release, and approve its hashes before operator provisioning/activation.
- Then run the existing guarded installer and physical acceptance procedure.
  These certificate steps do not approve genesis or spending and do not activate
  validators, providers, registry or application hosting.

## References

- [OpenSSL CA command](https://docs.openssl.org/3.0/man1/openssl-ca/)
- [Certbot Cloudflare DNS plugin](https://certbot-dns-cloudflare.readthedocs.io/en/stable/)
- [Certbot usage and renewal](https://eff-certbot.readthedocs.io/en/stable/using.html)
- [Let's Encrypt challenge types](https://letsencrypt.org/docs/challenge-types/)
- [Seven-node certificate handoff](SEVEN_NODE_CERTIFICATE_HANDOFF.md)
- [RPC DNS/TLS and Cloudflare routing](RPC_DNS_TLS_HANDOFF.md)
