# Greenwood: offline private CA and public HTTPS certificates

This is an offline-custody worked example for the supported seven-node reference
composition. Another operator selects its own [service roles](ROLE_BASED_ONBOARDING.md),
chain, names and approved trust; do not copy reference identities. Reuse your
existing CA, keys and issuance records. For the reference installation, credentials
are already provisioned: use [project status](../PROJECT_STATUS.md) and the
applicable continuation rather than repeating creation/issuance steps.

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

If you already have the Greenwood CA, skip creation in section 3. Unlock its
existing custody directory yourself and use the existing database and serial.
For just the new controllers, follow section 5A after reviewing sections 4-5.
Never initialize a replacement root merely to add two leaves.

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
controller-node-6 node-6 deployment-controller
controller-node-7 node-7 deployment-controller
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

The current inventory uses role-specific DNS SANs:

| Service name | DNS SAN |
| --- | --- |
| controller-node-N | controller-node-N.greenwoodblockchain.com |
| receiver-node-N | assignment-node-N.greenwoodblockchain.com |
| observer-node-N | provider-node-N.greenwoodblockchain.com |
| edge-admission-node-5 | edge-node-5.greenwoodblockchain.com |

Each leaf gets exactly one URI `spiffe://greenwood-prod-v1/control-plane/ROLE`.
All three controllers use the supported `deployment-controller` role URI, but
have distinct keys, certificates and fingerprints. This replaces the older
`node-N.control.greenwoodblockchain.com` examples. Compare any previously issued
certificates with the current inventory before reuse; do not overwrite existing
keys or silently treat a mismatched SAN as acceptable. The selected profile has
15 internal service leaves. Node-1 remains the sole Ansible artifact build controller;
controllers on nodes 6 and 7 are application services using their local providers.

Node-4 gets no assignment certificate. Do not replace existing validator,
provider signing, governance, registry-token or policy-signing keys with TLS keys.
The initial public-manifest handoff did not contain these internal TLS certificates;
that historical observation is not a statement that current credentials are missing.
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
case "$role" in
  deployment-controller) dns="controller-$node.greenwoodblockchain.com";;
  assignment/"$node") dns="assignment-$node.greenwoodblockchain.com";;
  provider/"$node") dns="provider-$node.greenwoodblockchain.com";;
  edge-admission) test "$node" = node-5
    dns="edge-node-5.greenwoodblockchain.com";;
  *) exit 1;;
esac
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

## 5A. Add only the node-6 and node-7 controller leaves

Stay offline. Use the EXISTING CA directory and the updated `issue-service.sh`
from section 5. Save that helper yourself; do not run the section 3 initialization
again. These commands add only the two missing public plan rows, without replacing
your existing plan or touching other service credentials:

```sh
set -eu
umask 077
CA_DIR="$HOME/Persistent/greenwood-prod-v1-ca"
cd "$CA_DIR"
test -f openssl.cnf
test -f index.txt
test -f serial
test -f certs/greenwood-root-ca.pem
test -f services.tsv
test -f issue-service.sh
for node in node-6 node-7; do
  row="controller-$node $node deployment-controller"
  grep -Fx -- "$row" services.tsv >/dev/null || printf '%s\n' "$row" >> services.tsv
done
sh issue-service.sh controller-node-6 node-6 deployment-controller
sh issue-service.sh controller-node-7 node-7 deployment-controller
```

Before signing, confirm your recorded CA fingerprint matches the existing root.
The helper refuses existing certificate filenames. If either leaf already exists,
stop and review it yourself; do not delete a key or certificate to force a rerun.
If issuance stops partway through, inspect the CA database and resume only the
missing leaf. Import an existing public CSR if appropriate rather than generating
a duplicate private identity. Each newly generated service key is separately
passphrase-encrypted; the CA passphrase is prompted interactively.

Expected leaf identities:

| Node | DNS SAN | URI SAN suffix |
| --- | --- | --- |
| node-6 | controller-node-6.greenwoodblockchain.com | deployment-controller |
| node-7 | controller-node-7.greenwoodblockchain.com | deployment-controller |

Both use the full URI prefix `spiffe://greenwood-prod-v1/control-plane/`,
`CA:false` and both serverAuth/clientAuth EKUs. Verify the keys match their leaves
locally; the following reads private keys ONLY when YOU run it on offline Tails:

```sh
for node in node-6 node-7; do
  name="controller-$node"
  openssl pkey -in "services/$name.key.pem" -pubout -outform DER \
    | sha256sum
  openssl x509 -in "certs/$name.pem" -pubkey -noout \
    | openssl pkey -pubin -outform DER | sha256sum
done
```

Compare each consecutive pair: they must match for that node. The two controllers
must have distinct keys and leaf fingerprints. Back up the updated CA database,
serial and custody directory yourself after issuance. Run section 6 to refresh
the public handoff, including the root, both leaves, CSRs and fingerprints.
Existing provider identities, operator tokens and policy-signing keys are reused;
no new blockchain or treasury identity is needed for this placement change.

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

## 7A. Obtain separate public HTTPS certificates for controllers 6 and 7

Use the separate ONLINE operator environment, with the offline CA drive removed.
The internal leaves from section 5A are not public HTTPS certificates. Confirm
these hostnames before issuance; each needs a separate Let's Encrypt identity.
Cloudflare routing configuration remains in RPC_DNS_TLS_HANDOFF.md and
PUBLIC_HTTPS_CERTBOT.md. The credential preparation required for issuance is
included here so you can complete it yourself:

```sh
sudo apt-get update
sudo apt-get install certbot python3-certbot-dns-cloudflare
sudo certbot plugins
sudo install -d -m 700 /root/.secrets/certbot
sudo nano /root/.secrets/certbot/cloudflare.ini
```

Enter `dns_cloudflare_api_token = YOUR_TOKEN_ENTERED_LOCALLY` in that file.
Use a token limited to Zone / DNS / Edit for greenwoodblockchain.com; never paste
it into chat or command arguments. If this file already exists, review and reuse
the existing credential yourself rather than replacing it unnecessarily.

```sh
sudo chmod 600 /root/.secrets/certbot/cloudflare.ini
printf 'ACME contact email: '
read -r CONTACT_EMAIL
test -n "$CONTACT_EMAIL"
for node in node-6 node-7; do
  host="controller-$node.greenwoodblockchain.com"
  sudo certbot certonly --dry-run --dns-cloudflare \
    --dns-cloudflare-credentials /root/.secrets/certbot/cloudflare.ini \
    --dns-cloudflare-propagation-seconds 60 \
    --key-type rsa --rsa-key-size 3072 \
    --cert-name "$host" -d "$host" --email "$CONTACT_EMAIL" || break
done
```

Only after BOTH dry runs succeed, repeat the loop without `--dry-run` to issue
production certificates. Review the terms prompts yourself. Do not install
staging certificates. DNS-01 needs online DNS validation but no inbound port
opening. These operations create HTTPS and ACME private material in your online
custody environment, not on the offline CA drive.

For each hostname, Certbot's `/etc/letsencrypt/live/HOSTNAME/` contains `cert.pem`,
`chain.pem`, `fullchain.pem` and `privkey.pem`. Keep the last file private. Return
only the public three files plus the actual public root CA, labelled by node and
role, with leaf DER fingerprints and PEM checksums. Follow the existing public
HTTPS guide to verify the complete trust chain and renewal before deployment.
Do not reuse an internal controller key for this HTTPS certificate.

The node-6 HTTPS origin is 192.168.72.105:19449; node-7 is 192.168.72.106:19449.
Both forward to their own loopback listener on 19450. The prepared Cloudflare
routes use public port 443. Issuing a certificate does not enable these routes or
start any service. The current inventory expects nine public HTTPS frontends in
all, including the retained node-1 controller.

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

## 8A. Controller runtime export and public-only return checklist

After approval, YOU can export each encrypted internal master to a separate
installation copy. Remain offline and use encrypted operator-controlled media:

```sh
set -eu
cd "$CA_DIR"
umask 077
mkdir -m 700 controller-runtime-export
# mkdir must succeed; stop if an earlier export directory already exists.
for node in node-6 node-7; do
  name="controller-$node"
  openssl pkey -in "services/$name.key.pem" \
    -out "controller-runtime-export/$name.key.pem"
  chmod 600 "controller-runtime-export/$name.key.pem"
done
```

On each corresponding node, you later install its own internal key at
`/etc/greenwood/tls/internal/controller.key`, its leaf (or leaf/intermediate chain)
at `/etc/greenwood/tls/internal/controller.fullchain.pem`, and the public CA at
`/etc/greenwood/tls/internal/ca.pem`. This guide's direct-root profile needs only
the leaf in the service chain file; trust is supplied separately. Keys must be
root-owned, mode 0600. Do not copy node-6's key onto node-7 or install the CA key.

For public HTTPS, you alone transfer that hostname's four-file Certbot bundle to
`/etc/greenwood/acme-incoming/controller/` on its matching node and run the existing
managed import procedure from PUBLIC_HTTPS_CERTBOT.md. Do not mix internal and
public certificate chains. Keep private exports out of public handoff directories.

Bring back only the two internal leaves/CSRs, their public CA, both public HTTPS
leaf/intermediate chains and public root certificates, role/node labels, DER
fingerprints and PEM checksums. The assistant can then replace certificate-pin
placeholders consistently. Provider signing public keys are not certificate pins.
Never return runtime exports, private keys, vaults, API tokens or ACME account
files. You retain sole control of secret installation and final activation.

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
- Then select the guarded fresh-install or bounded-continuation procedure that
  matches the installed state, followed by physical acceptance.
  These certificate steps do not approve genesis or spending and do not activate
  validators, providers, registry or application hosting.

## References

- [OpenSSL CA command](https://docs.openssl.org/3.0/man1/openssl-ca/)
- [Certbot Cloudflare DNS plugin](https://certbot-dns-cloudflare.readthedocs.io/en/stable/)
- [Certbot usage and renewal](https://eff-certbot.readthedocs.io/en/stable/using.html)
- [Let's Encrypt challenge types](https://letsencrypt.org/docs/challenge-types/)
- [Seven-node certificate handoff](SEVEN_NODE_CERTIFICATE_HANDOFF.md)
- [RPC DNS/TLS and Cloudflare routing](RPC_DNS_TLS_HANDOFF.md)
