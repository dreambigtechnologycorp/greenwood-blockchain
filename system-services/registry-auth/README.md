# GREENWOOD_REGISTRY_AUTH_V1

Network-owned OCI authorization service.

Responsibilities:

1. Issue Greenwood registry challenges.
2. Verify Ed25519 Greenwood account identity.
3. Query finalized ApplicationRegistryV1 authority.
4. Persist one-time challenges.
5. Persist short-lived registry sessions.
6. Issue scoped EdDSA OCI bearer JWTs.

This service MUST NOT:

- depend on Greenwood Studio;
- generate its issuer identity at runtime;
- contain a developer signing key;
- act as an application controller;
- decide application authority from local configuration.

Required runtime configuration:

- GREENWOOD_CHAIN_ID
- GREENWOOD_GENESIS_HASH
- GREENWOOD_RPC_ENDPOINT
- GREENWOOD_APP_REGISTRY_CONTRACT
- GREENWOOD_APP_REGISTRY_WASM_HASH
- GREENWOOD_REGISTRY_ISSUER_KEY
- GREENWOOD_REGISTRY_AUTH_DB

The issuer private key is provisioned independently.
Only its public counterpart is provided to GREENWOOD_REGISTRY_V1.

## Current HTTP Surface

`public/index.php` provides `GET /health`, `GET /issuer-public-key`,
`POST /challenge`, `POST /authorize` and `GET|POST /token`. Token POST supports
JSON and form encoding. Docker's token exchange can use Basic credentials with
username `greenwood` and the short-lived server-issued session credential; this
is not a permanent password. Authorization and token refresh recheck finalized
application permissions, and requested repository/actions cannot exceed the session.

Repository scope is
`chains/<chain>/registries/<contract>/apps/<application_id>/<service>`.
Push requires `PUBLISH`; tokens are not transferable to another application scope.
Challenges and sessions are persisted in a protected SQLite database. The issuer
key must be an existing readable non-symlink private file (0600), owned by root or
the service identity. A health response is process availability, not OCI acceptance.

The chain ID, genesis hash, RPC origin, registry contract and registry WASM hash
must be explicit; there is no default physical chain identity. Default key/database
paths are in [bootstrap.php](src/bootstrap.php) and must be provisioned, not treated
as installed artifacts. Changing identity pins requires a process restart.
The local checkout uses SQLite `DELETE`/`EXTRA`; an existing WAL database requires
the reviewed [offline migration](../../deploy/ansible/STATE_STORAGE.md).
Align the HTTPS token realm and issuer public key with Zot's verification certificate.
See [the control-plane handoff](../../deploy/ansible/CONTROL_PLANE_HANDOFF.md) and
[project status](../../docs/PROJECT_STATUS.md). Configure issuer/service URLs for
the selected network; this service guide does not assert a live RPC endpoint.
