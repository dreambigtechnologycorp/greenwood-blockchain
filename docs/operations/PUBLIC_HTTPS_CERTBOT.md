# Public HTTPS with Let’s Encrypt, Certbot and Cloudflare

Network and deployment runbook · 21 November 2026

This document covers online certificate acquisition, DNS validation, trust-chain
integration and renewal. It does not authorize live changes. It complements
[RPC DNS and tunnel ingress](RPC_DNS_TLS_HANDOFF.md), which describes actual routes.
API-token entry and any command handling private keys remain human-operator steps;
public DNS and routing review do not require disclosing credentials.

## 1. Prepare online certificate acquisition

Use your trusted online Ubuntu operator machine, with the offline CA storage
unplugged. Certbot requires Internet access; this section is not an offline Tails
operation. It creates/manages public-HTTPS private keys and an ACME account key,
which remain under your control. It does not replace blockchain signing keys.

On that machine, install the DNS plugin and confirm it is available:

```sh
sudo apt-get update
sudo apt-get install certbot python3-certbot-dns-cloudflare
sudo certbot plugins
sudo install -d -m 700 /root/.secrets/certbot
sudo nano /root/.secrets/certbot/cloudflare.ini
```

In that file, enter the following using a token you create yourself in Cloudflare:

```ini
dns_cloudflare_api_token = YOUR_TOKEN_ENTERED_LOCALLY
```

Limit the token to **Zone / DNS / Edit** for `greenwoodblockchain.com`. Do not use
Cloudflare's global API key or paste the token into the terminal command line/chat.

```sh
sudo chmod 600 /root/.secrets/certbot/cloudflare.ini
```

The DNS plugin creates validation TXT records. DNS-01 does not require opening
inbound ports, exposing nodes publicly or changing existing tunnel routing.

Issue separate certificates, one per endpoint, using this command with a reviewed
hostname and your actual contact email. First test against staging; staging
certificates are not production-trusted and must not be installed as production:

```sh
sudo certbot certonly --dry-run --dns-cloudflare \
  --dns-cloudflare-credentials /root/.secrets/certbot/cloudflare.ini \
  --dns-cloudflare-propagation-seconds 60 \
  --key-type rsa --rsa-key-size 3072 \
  --cert-name rpc-node-1.greenwoodblockchain.com \
  -d rpc-node-1.greenwoodblockchain.com --email YOUR_CONTACT_EMAIL
```

After it succeeds, run the same command **without `--dry-run`**, reviewing the
terms prompt yourself. Repeat separately for:

- `rpc-node-2.greenwoodblockchain.com`
- `rpc-node-3.greenwoodblockchain.com`
- `rpc-node-5.greenwoodblockchain.com`
- `registry.greenwoodblockchain.com`
- `registry-auth.greenwoodblockchain.com` only after you confirm that proposed name.

Standard Certbot issuance creates new HTTPS private keys. Your previously prepared
registry CSR/key will not be used by these commands: decide whether to adopt the
new Certbot-managed registry identity before running that issuance. Keeping the
existing CSR is a different `certonly --csr` workflow and does not provide the
same automatic renewal handling. Do not silently replace an installed identity.

Certbot outputs under `/etc/letsencrypt/live/HOSTNAME/`:

| File | Treatment |
| --- | --- |
| `cert.pem` | Public leaf certificate |
| `chain.pem` | Public intermediate certificates |
| `fullchain.pem` | Public leaf plus intermediates, for server presentation |
| `privkey.pem` | PRIVATE: operator installation only; never return it |

Return only the first three public files, clearly labelled by hostname. Public
HTTPS renewals are separate from the fingerprint-pinned internal mTLS leaves.

## 2. Bind the returned chain to reviewed root trust

The installer now accepts `intermediates` separately from `ca`. It verifies the
server purpose, hostname and validity using the pinned CA and the intermediate
file as **untrusted chain-building material**. System CA directories/stores are
excluded from that check. Apache presents `fullchain.pem` and continues to verify
HTTPS upstreams against the approved root.

Obtain the appropriate root from the issuer's official certificate repository and
review its certificate SHA-256 fingerprint independently. Do not trust a root
simply because it arrived with a CSR or leaf. Do not assume every future Let's
Encrypt chain uses the same root. A root change requires explicit review and new
public trust pins; renewal never replaces the root file.

Public-only checks for the actual issued chain:

```sh
openssl verify -no-CApath -no-CAstore -CAfile approved-root.pem \
  -untrusted chain.pem -purpose sslserver \
  -verify_hostname rpc-node-1.greenwoodblockchain.com cert.pem
openssl x509 -in approved-root.pem -noout -fingerprint -sha256
sha256sum approved-root.pem
```

Keep the certificate DER fingerprint (lowercase, no colons) distinct from the PEM
file checksum. Use the DER fingerprint for `ca_sha256`; the provider-only trust
copy uses the PEM checksum. Provision that reviewed public root on provider-only
clients and in cloudflared's CA pool. Never use the internal Greenwood mTLS CA
as a substitute for the Let's Encrypt root. Keep `noTLSVerify` false.

## 3. Declare managed HTTPS certificate paths

For each existing HTTPS frontend, use this shape (merge it into the full frontend
entry; do not replace its address, backend, request limit or TLS name):

```yaml
name: rpc
tls_name: rpc-node-1.greenwoodblockchain.com
ca: /etc/greenwood/tls/approved-public-root.pem
ca_sha256: REPLACE_REVIEWED_ROOT_CERTIFICATE_DER_SHA256
renewal:
  source_dir: /etc/letsencrypt/live/rpc-node-1.greenwoodblockchain.com
  minimum_validity_seconds: 86400
  certbot_hook: false
```

Use the corresponding service names `rpc`, `controller`, `registry` or
`registry-auth` on each host. Paths are isolated per service. Keep
`certbot_hook: false` during preparation; selecting `true` explicitly authorizes
installation of a local Certbot deploy hook for future successful renewals.
Installing the hook does not run it, issue certificates or activate services.
The control-plane role remains behind the operator secret-provisioning gate.

The helper accepts only the declared Certbot lineage, resolves its symlinks into
that hostname's archive, and rejects mixed archive generations. It copies into
regular files under Greenwood's dedicated directory, so the installer's no-symlink
rule remains intact. Certificate, chain and key are validated together before
publication. The root CA stays outside this directory and is never renewed by the
helper.

If Certbot runs on your operator machine rather than this node, use
`source_dir: /etc/greenwood/acme-incoming/rpc` and `certbot_hook: false` instead.
You must securely transfer the four files (`cert.pem`, `chain.pem`,
`fullchain.pem`, `privkey.pem`) yourself into that root-owned protected directory
on the destination. The key must be 0600. This transfer handles private keys and
must not run through the assistant. There is no automatic cross-node SSH transfer
or credential distribution in the renewal implementation.

## 4. Bootstrap the first generation yourself

Initial import precedes the full installer because it checks the provisioned TLS
files. From your reviewed source checkout on each destination, install the helper
and a **public metadata-only** configuration. The JSON uses exactly these fields:

```json
{
  "service": "rpc",
  "tls_name": "rpc-node-1.greenwoodblockchain.com",
  "ca": "/etc/greenwood/tls/approved-public-root.pem",
  "ca_sha256": "REPLACE_REVIEWED_ROOT_CERTIFICATE_DER_SHA256",
  "source_dir": "/etc/letsencrypt/live/rpc-node-1.greenwoodblockchain.com",
  "minimum_validity_seconds": 86400
}
```

Save it as `rpc-renewal.json` locally and replace the root fingerprint placeholder.
You run these commands yourself after installing the reviewed public root and
obtaining the matching Certbot files. The helper reads private keys to verify the
pair; the explicit flag records that this is an operator-controlled operation.

```sh
sudo install -m 0755 scripts/renew-https.py /usr/local/sbin/greenwood-renew-https
sudo install -d -m 0700 /etc/greenwood/control-plane
sudo install -m 0600 rpc-renewal.json /etc/greenwood/control-plane/rpc-renewal.json
sudo /usr/local/sbin/greenwood-renew-https \
  --config /etc/greenwood/control-plane/rpc-renewal.json \
  --approve-private-key-access
sudo /usr/local/sbin/greenwood-renew-https \
  --config /etc/greenwood/control-plane/rpc-renewal.json \
  --approve-private-key-access --install
```

The first helper invocation validates a temporary private copy and removes it;
it does not replace the installed generation. The second imports it without
starting or reloading any service. If an existing Apache configuration is present,
it must use `Include /etc/greenwood/tls-managed/SERVICE/active.conf` instead
of static certificate/key directives, and its configuration test must pass.
For a pre-activation migration from a staged static configuration, the operator
must first update those two directives to this Include; do not reload until the
initial import and configuration check succeed. Otherwise the helper rolls back. Never overwrite an existing renewal
JSON with a template without reviewing it first. The normal Ansible role stages
the same helper and metadata config on later operator-controlled runs.

## 5. Renewal and rollback

For Certbot installed on the destination node, explicitly select
`renewal.certbot_hook: true` in the approved inventory and stage the role yourself.
The hook is `/etc/letsencrypt/renewal-hooks/deploy/greenwood-SERVICE`. It ignores
other lineages and exits without action if the matching Greenwood service is
inactive. Certbot supplies `RENEWED_LINEAGE` after successful renewal. The hook
imports the new generation and reloads only `greenwood-SERVICE-https.service`;
it never starts or restarts a stopped service.

Test Certbot issuance renewal first (normal dry-run does not run deploy hooks):

```sh
sudo certbot renew --dry-run
```

After authorized initial activation, a human operator can perform the same guarded
import/reload manually, including when certificates were transferred from an
operator machine:

```sh
sudo /usr/local/sbin/greenwood-renew-https \
  --config /etc/greenwood/control-plane/rpc-renewal.json \
  --approve-private-key-access --install --reload-active
```

Imports are serialized with a lock. Each validated bundle is retained in an
immutable `generation-<id>` directory. An atomic regular-file rename publishes
`/etc/greenwood/tls-managed/SERVICE/active.conf`, containing the matching certificate
and key paths. Apache includes this file; do not specify static certificate/key
paths for a managed frontend. This avoids symlinks and directory-exchange operations
that NFS roots may not support. Physical NFS validation is still required.
Configuration or reload failure restores the previous include. This restores disk
configuration; inspect the running service after any failed reload. Generations
are retained, including failed candidates, for operator recovery. A process crash
can leave a `.staging-*` directory; subsequent imports stop for manual review.
Review retained certificates locally and revalidate Apache before recovery. Remove
obsolete generations yourself only after identifying the active pair and confirming
no configuration references them; retained directories contain private keys.
An in-flight TLS session may continue using the previously loaded certificate.

Verify DNS, actual served certificate/expiry and RPC chain identity after renewal.
The staging and unit tests do not replace this physical acceptance. Certbot's
scheduler must be active on its online host and expiry monitoring must exist;
renewing on a powered-off laptop cannot work. These tasks remain operator actions.
To disable an already-installed hook, remove that exact hook yourself and set its
inventory flag false. Staging intentionally does not delete existing hooks or
alter Certbot's scheduler automatically.

## References

- [Certbot Cloudflare plugin](https://certbot-dns-cloudflare.readthedocs.io/en/stable/)
- [Certbot issuance and renewal](https://eff-certbot.readthedocs.io/en/stable/using.html)
- [Offline CA and key custody](TAILS_CA_AND_LETS_ENCRYPT.md)
