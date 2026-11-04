# PHP RPC and Operator CLI

This page describes this repository's `bin/greenwood`, not the separate Python
`greenwood-cli-v0.3` executable. That client implements encrypted developer keys,
registration, scoped registry authentication, build/push, release operations and
signed deployment/resume/status. Its server lives in
`system-services/deployment-controller/` here. See [current functionality](CURRENT_FUNCTIONALITY.md).

`bin/greenwood` — first-party operator CLI. Pure RPC client: it never reads
`state.sqlite`, `provider.sqlite` or block files. No shell execution of user
values. Signing stays local and explicit (`bin/greenwood-cli transfer-sign`);
private keys are never sent to any endpoint.

## Configuration

Flags (highest precedence), then `--config=<file>` JSON, then defaults:

```json
{
  "rpc_endpoint": "http://127.0.0.1:9201",
  "operator_endpoint": "unix:///run/greenwood/operator.sock",
  "operator_token_file": "/etc/greenwood/operator.token",
  "output": "human"
}
```

Flags: `--json --rpc=URL --operator=URL --token-file=PATH --config=PATH`.
No private keys in CLI configuration.

## Commands

```
greenwood chain status|finality|validators
greenwood block get <height|hash>
greenwood account get|balance|nonce <account-hex>
greenwood contract get <contract-hex-id>           # contract metadata (abi, wasm_hash, balance)
greenwood contract call <contract-hex-id> <method> <base64-calldata>  # read-only execution via RPC
greenwood tx submit <envelope.json>          # {type:"transaction",payload:base64} from local signing
greenwood tx get|status <tx-hash-hex>
greenwood compute request|history|receipt <request-hex>
greenwood compute providers
greenwood compute provider <provider-hex>
greenwood provider status|jobs|runtime       # operator endpoint + bearer token
greenwood provider drain|resume
greenwood provider capability [--status=ACTIVE|DRAINING|SUSPENDED]
    [--arch=linux/arm64/v8,linux/amd64] [--cpu=N] [--memory=N] [--pids=N] [--jobs=N]
```

## Output modes

Default human output is `key: value` lines. `--json` (or `"output":"json"`)
prints the RPC DTO unchanged; errors are structured
(`{"error":{"code","message","details"}}`). No commentary is mixed into JSON
stdout; human errors go to stderr.

## Exit codes

| Code | Meaning |
|---|---|
| 0 | success |
| 1 | other failure (e.g. endpoint unreachable) |
| 2 | invalid local input (bad args/files; also HTTP 400) |
| 3 | authentication failure (HTTP 401/403) |
| 4 | remote not found (HTTP 404) |
| 5 | conflict / not ready (HTTP 409/422/429/503) |

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](README.md) · [Public RPC v1](rpc-v1.md) · [Operator API](operator-api.md)
<!-- /docs-nav -->
