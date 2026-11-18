# Operator-only production key boundary

The operator generates and provisions every production secret personally. AI may
prepare source, unsigned artifacts, public configuration and public-key validation.
It must stop before generating, reading, copying, signing with, or backing up a
production private key. Do not paste secrets into chat, attach them, or place them
in this checkout or an AI-accessible build context. Synthetic test identities are
not production identities and must never be promoted into production.

## Where exposure can occur

| Operation | Risk and required boundary |
| --- | --- |
| Control-plane `greenwood_control_files` | Ansible copy reads controller files and transfers them; `no_log` only suppresses output. Leave empty during AI preparation. |
| Control-plane HTTPS validation | `https.yml` reads the target TLS private key to check its certificate pair, including in check mode. Human-only. |
| Validator/provider services | Service processes read their installed signing files at startup. Provision and start them yourself after public configuration review. |
| Registry issuer, system publisher, operator tokens | Signing scripts and auth services consume secrets. Human-only provisioning and signing; never return bearer tokens as evidence. |
| SSH authentication | An SSH agent can authenticate Git without exposing private bytes. The agent still grants signing capability. Never forward it to nodes/builders; constrain its lifetime and scope. |
| NFS roots on node-4 | Client signing files would also reside on node-4's SSD. Node-4 administrators and sufficiently privileged NFS access can expose them. Per-node files are not independent custody. |
| Backups, support bundles, logs, core dumps | Rootfs archives, recursive copies, debug output and process environments can capture secrets. Separate public evidence from protected operator-managed backups. |
| AI terminal with sudo or disk access | A written policy is not an access boundary. Once keys exist, revoke AI-accessible privileged access to their hosts/storage or use a separate restricted account/environment. |

The control-plane role now defaults `greenwood_operator_secret_access_authorized`
to false and rejects nonempty file/HTTPS provisioning before any task runs. Only
the operator should enable it in a private manual invocation. This gate does not
sandbox other playbooks or a privileged shell.

## Human handoff

1. Finish source/artifact verification and approve governance before generating
   production identities. Keep permanent activation disabled and payable profiles
   empty until their evidence and authority are approved.
2. End AI access to the key-generation environment. Use a trusted operator terminal,
   restrictive `umask 077`, and a protected directory outside the repository,
   shared build directories, chat attachments and shell transcript capture.
3. Generate separate validator, provider, governance, registry issuer, publisher
   and TLS identities using their reviewed component procedures and required file
   formats. Do not reuse test identities or create unratified governance signers.
4. Provision secrets yourself with restrictive ownership/permissions. Review the
   NFS trust boundary before storing signing material on network root filesystems.
   Keep recovery copies under the approved custody policy; do not upload them here.
5. Export only public keys/certificates and signed public policy documents to a
   dedicated public handoff directory. Supply those public artifacts for genesis
   preparation; never provide a seed, private key, password or authentication token.
6. Personally execute private-key validation, signing, provisioning and activation.
   Return only public fingerprints and redacted success/failure evidence.

Do not run `system-service-publish-sign.php` in an AI-controlled session. Do not
use blanket `ansible --check` as proof of no secret reads. Do not grant ordinary
application publishing permissions to network-system-service publishers.

## Current activation blockers

Node-6's physical Ethernet path and runtime qualification remain outstanding.
Recovery access is deferred, not verified. Final seven-node physical acceptance,
registry endpoint/TLS verification, and production governance approvals remain
required. Allocation ratification, budgets, fees/bootstrap funding, related-party
controls, signer custody and lost-quorum recovery are not supplied by this guide.
No production key generation or genesis activation is authorized by preparing it.
