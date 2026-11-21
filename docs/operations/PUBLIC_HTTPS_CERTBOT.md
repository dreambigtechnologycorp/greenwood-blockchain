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

## 2. Resolve trust-chain and renewal installation before cutover

**Do not feed Let's Encrypt files straight into the current inventory and assume
completion.** Greenwood currently pins a CA-file fingerprint and its Ansible TLS
check does not pass a separate intermediate chain to `openssl verify`. Public CA
chains must be bound and the verification path adapted/reviewed before deployment.
A `fullchain.pem` server file alone does not substitute for a correctly configured
verification trust store. The internal Greenwood root must not be used to verify
Let's Encrypt certificates.

Also, the installer rejects symlinked TLS paths; Certbot's `live/` paths are
symlinks. Your deployment procedure must copy the resolved current files into the
reviewed `/etc/greenwood/...` destinations, preserve private-key mode 0600 and
root ownership, verify the pair/chain and then reload the matching standalone
Greenwood HTTPS unit. Do not reload only the unrelated default Apache service.
No renewal hook or reload is authorized by this preparation guide.

Test issuance renewal without activating services:

```sh
sudo certbot renew --dry-run
```

Before go-live, implement and test the operator-approved deployment/renewal hook
and expiry monitoring. Renewal on an online laptop does not automatically update
certificates on the cluster. Certbot also cannot renew while that machine is off.
Do not solve this by bringing the offline CA key online. Keep Cloudflare
`noTLSVerify` false and configure the origin hostname/CA trust correctly.


## References

- [Certbot Cloudflare plugin](https://certbot-dns-cloudflare.readthedocs.io/en/stable/)
- [Certbot issuance and renewal](https://eff-certbot.readthedocs.io/en/stable/using.html)
- [Offline CA and key custody](TAILS_CA_AND_LETS_ENCRYPT.md)
