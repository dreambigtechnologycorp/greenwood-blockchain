# Generate production keys yourself

This is an operator-controlled creation reference for genuinely missing identities
or an approved replacement, not a joining requirement to copy the reference
cluster's full key set. Choose only your [service roles](ROLE_BASED_ONBOARDING.md).
The reference installation already has its credentials; retain them and consult
[project status](../PROJECT_STATUS.md) before any continuation. Do not generate
a second identity merely because an installation was interrupted.

Run this only in your own trusted terminal, outside an AI-controlled session.
Do not ask AI to run it, verify your vault, or export your private keys. First end
AI access to the computer/account/storage that will hold them. Use an encrypted
local disk or offline removable volume; do not use the shared node-4 NFS root,
a Git checkout, cloud-synced folder, or chat attachment storage.

The script is `scripts/operator-key-vault.php`. It requires Linux, PHP 8.5 with
Sodium, OpenSSL, POSIX and PCNTL, and `/usr/bin/stty`. It makes no network calls,
installs nothing, does not activate genesis and never prints secret values.
Passphrases are entered twice through `/dev/tty` with echo disabled, not through
arguments, environment variables, stdin pipes or files. Use a unique, randomly
chosen long passphrase stored separately in your password manager; the 20-character
minimum alone does not guarantee strength.

## Infrastructure identities

`deploy/production/operator-keys.infrastructure.json` is the seven-node reference
plan, not a universal homelab enrollment manifest. Review a role-appropriate plan
for your own environment. The supplied reference plan creates:

- Three validator identities for nodes 1–3.
- Five separate provider identities and operator tokens for nodes 1–3, 6 and 7.
- Six separate metering-source identities for runtime nodes 1–3 and 5–7.
- One registry issuer and a separate network-system-service publisher identity.

These are DreamBIG-operated identities, not independent attestors. Their generation
grants no registration, payment, treasury or publisher authority. Metering sources
still require their approved bindings. Node-4 needs no blockchain signing identity
in this topology. The coordinator accepts signed inputs and needs no invented
unrestricted treasury signing key.

From the Greenwood checkout, **you** run (change the vault location if necessary):

```sh
umask 077
mkdir -m 700 "$HOME/greenwood-key-custody"
php scripts/operator-key-vault.php create \
  deploy/production/operator-keys.infrastructure.json \
  "$HOME/greenwood-key-custody/infrastructure"
```

The parent must already be owned by you with mode 0700. The output directory must
not exist. Existing files and symlinks are never overwritten. Output is:

- `keys.gwkv`: authenticated encrypted vault, mode 0600; **never share it with AI**.
- `public-identities.json`: public signing keys/fingerprints and optional TLS CSRs,
  mode 0600. It contains no operator tokens or private keys. This is the only file
  to copy into a separate public handoff directory for genesis preparation.

Storage uses libsodium Argon2id (moderate cost: 256 MiB, three operations) and
XSalsa20-Poly1305 authenticated encryption, with fresh random salt and nonce.
The script verifies the written vault by decrypting it before reporting success.
It never writes plaintext secrets during creation. Secret data necessarily exists
in RAM; PHP cannot guarantee erasure of every copy. Core dumps are disabled, but
use encrypted swap or no swap and a trusted, uncompromised operator environment.
File permissions do not protect secrets from root or another process under your UID.

## Governance, recovery, witnesses and other separately held identities

Do **not** generate five governance signers or a recovery council in one shared
infrastructure vault. Use the selected production policy and confirmed custody appointments; generating keys does not itself appoint a signer or authorize recovery.
Each approved custodian uses a separate private device/account, passphrase and vault.
Copy `deploy/production/operator-keys.custodian.example.json`, replace its entry
name with the approved identity, and run `create` with that plan and a new directory.
The `greenwood-ed25519` format also supports separately authorized attestors,
recovery guardians, acceptance customer accounts and other Greenwood signers.
Do not create an independent witness claim from another key you control.

## TLS identities

Copy `deploy/production/operator-keys.tls.example.json` into a public planning
folder. Add an entry for each approved controller, registry, registry-auth and RPC
TLS endpoint, with its real DNS name. `.invalid` placeholders are rejected.
Each `tls-rsa` entry produces a separate RSA-3072 private key in the encrypted vault,
and a public PEM key and CSR in the public manifest. Run `create` into a separate
TLS vault directory. Have your approved CA issue the certificate with the exact
DNS subjectAltName recorded in `san_must_be_set_by_issuer`; the CSR's common name
alone is not sufficient. This tool does not create a CA, trust root, self-signed
production certificate or registry issuer verification certificate. Provision those
through the approved certificate/trust workflow. Existing SSH and Cloudflare
credentials are neither read nor replaced.

## Verify backups and export only what a host needs

Keep at least two operator-controlled encrypted vault backups on separate media.
Store the passphrase separately. Losing it loses the keys; there is no backdoor.
Verify a restored backup yourself before relying on it:

```sh
php scripts/operator-key-vault.php verify \
  "$HOME/greenwood-key-custody/infrastructure/keys.gwkv"
```

Export is intentionally a separate command and writes **one plaintext file**. Its
parent must be your canonical 0700 directory outside Git; the target must be new.

```sh
mkdir -m 700 "$HOME/greenwood-key-custody/node-1-install"
php scripts/operator-key-vault.php export \
  "$HOME/greenwood-key-custody/infrastructure/keys.gwkv" \
  node-1-validator "$HOME/greenwood-key-custody/node-1-install/validator.key"
```

Repeat only for that host's provider, token and authorized metering identity. Do not
copy the entire vault onto a node. Transfer/install exports yourself with the approved
Ansible procedure and root ownership/mode 0600 on the destination. Only the human
operator enables `greenwood_operator_secret_access_authorized`. Secure the temporary
plaintext exports afterward; deleting a file is not guaranteed secure erasure on SSDs,
snapshots or journaled filesystems. Prefer an encrypted staging volume.

Greenwood signing files are `public(32) || secret(64)` binary, while the registry
issuer uses libsodium's native `secret(64) || public(32)` binary. Both are 96 bytes
and **not interchangeable**. Operator tokens are 32 random bytes encoded as 64 hex
characters plus a newline. TLS exports are unencrypted PKCS#8/PEM private keys;
protect them as plaintext secrets once exported.

The plaintext public manifest is a handoff aid, not a signed authorization document.
Compare public fingerprints through your trusted custody/approval process before
permanent genesis. Review [the key custody boundary](../../deploy/production/OPERATOR_KEY_CUSTODY.md)
and all unresolved governance, registry, recovery and physical acceptance gates.

## Deployment policy keys, SFTP and control-plane TLS

Use `deploy/production/operator-keys.policy.example.json` in a separate operator-held
vault for `revocations` and `allocations`. Both entries use `sodium-ed25519`, the
96-byte libsodium keypair format required by receiver-policy-publisher.php. Do not
substitute `greenwood-ed25519` or an OpenSSH key. Export only when the human operator
installs the publisher's protected `revocations.key` and `allocations.key` files.
Initial signed policy snapshots are separately required; key creation does not
approve allocations or initialize those snapshots.

Generate dedicated SFTP host and per-consumer client identities with OpenSSH
`ssh-keygen -t ed25519 -a 100` in encrypted offline storage, with passphrase prompts.
Keep these separate from administrative SSH identities. Transfer only `.pub` files
and SHA-256 fingerprints for review. Unattended SFTP needs operator-provisioned
runtime credentials usable without an interactive passphrase; decrypt only a
separate installation copy, never the cold master, and never in an AI session.

Create separate TLS vault entries for each configured endpoint using `tls-rsa` and
its reviewed DNS name. Each control-plane certificate also needs exactly one
approved chain-specific URI SAN, `spiffe://CHAIN/control-plane/ROLE`. Allowed roles
include deployment-controller, edge-controller, edge-admission, assignment/node-1,
assignment/node-2, assignment/node-3, assignment/node-5, assignment/node-6,
assignment/node-7, and provider/node-1, node-2, node-3, node-6 or node-7.
The legacy assignment/node-4 URI is supported only for the old two-receiver profile;
do not provision it for the seven-node installation. DNS names,
chain identity, CA trust and fingerprint-to-principal mappings must be reviewed
before certificate issuance. The vault CSR carries a CN only: the authorized issuer
must add DNS and URI SANs and suitable key usages. Do not self-assign CA trust.
Do not deploy any of these new topology roles using older pre-fix release images.

See the [seven-node certificate handoff](SEVEN_NODE_CERTIFICATE_HANDOFF.md) for
current receiver roles, certificate verification and public-only return files.

## Offline Tails runbook

Download the [offline key custody worked example](GREENWOOD_TAILS_KEY_CUSTODY.pdf)
before disconnecting, together with the current Markdown and certificate
supplement. It covers the reference deployment's tool preparation and key families,
backups, public-only handoff and the operator-only provisioning boundary.
The pinned key-generator revision in the guide remains valid for key generation;
seven-node service deployment requires release artifacts containing the topology fixes.
