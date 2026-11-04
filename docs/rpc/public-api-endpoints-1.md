# Chain, Block and Account Responses

Checked against `src/Rpc/HttpApi.php` on October 21, 2026. Field descriptions below
describe this checkout, not a captured live response. See [RPC v1](../rpc-v1.md)
for route, validation and error rules.

## Chain Status

`GET /v1/chain/status` returns:

- `chain_id`, `api_version`, and configured process `role`.
- `height` and `finalized_height`: both are the current finalized tip height.
- `block_hash` and `state_root`: lowercase hex.
- `validator_count` and sorted `validator_ids`.
- `block_time_seconds`, `supply`, and `economy`: configuration-derived fields.
- `syncing`: currently returned as false; do not treat this constant as independent
  proof that the node has caught up with its peers.

`supply` includes `hard_cap`, decimal-string `total_supply`, `account_count`,
`emitted_rewards` and `burned_fees`. The latter two are currently zero placeholders,
not an implemented rewards/burn accounting claim. Configuration-dependent fields
can be null. These economic additions are present in the working checkout; they
are not attested as deployed by this documentation change.

## Finality and Validators

`GET /v1/chain/finality` returns `height`, `round`, `block_hash`,
`certificate_present`, `precommit_count`, and `validator_count`. At genesis no
certificate is loaded, the round is null and the vote count is zero.

`GET /v1/chain/validators` returns a JSON array, not a `validators` wrapper.
Each entry contains `validator_id` (genesis label), `public_key` (hex),
`voting_power` (1 for the equal-power profile), and `active: true`.
Active here is membership, not a live heartbeat.

`GET /v1/chain/state-root` returns `state_root` and `finalized_height`.

## Blocks

Both `GET /v1/blocks/{height}` and `GET /v1/blocks/hash/{hash}` return:

```text
height, round, block_hash, parent_hash, state_root,
previous_state_root, transactions_root, proposer,
transactions, finality_certificate_present
```

Hashes and proposer account are lowercase hex; `transactions` is an array of
transaction hashes. This DTO does not return the old example's `timestamp`,
`version`, `validator`, `signature`, or `prev_block_hash` fields.
Certificate presence is not a substitute for independently validating finality.

## Accounts

`GET /v1/accounts/{id}` returns `account_id`, decimal-string `balance`,
integer `nonce`, and `finalized_height`. The `/balance` and `/nonce` suffixes
return the corresponding value plus `account_id` and `finalized_height`.

An absent account returns structured `ACCOUNT_NOT_FOUND`, not a fabricated
zero-balance record. Fresh-account nonce handling is a client/protocol decision
after this specific response; a timeout or arbitrary HTTP error is not account
absence. Malformed identifiers fail validation.

[Documentation hub](../README.md) | [Public API guide](public-api.md)
