# Transactions

A Greenwood transaction is a canonical, signed envelope. It is the only normal
way to request a consensus state transition; public RPC does not create a
separate server-side transaction format.

## Envelope

The v1 envelope contains:

```text
version | chain_id | sender_address | sender_public_key | nonce | type | payload | signature
```

All integers and variable-length fields use the canonical big-endian encoding
defined in [Protocol v1](../GREENWOOD_PROTOCOL_V1.md). The signed digest is
domain-separated with `GREENWOOD_TX_SIGN_V1`; the transaction ID is the hash of
the complete signed canonical envelope under `GREENWOOD_TX_V1`.

`sender_address` must equal the domain-derived address of `sender_public_key`.
The signature is a 64-byte Ed25519 signature, and the chain ID must match the
node's genesis. Neither JSON field order nor a PHP object's layout is relevant
to the transaction ID.

## Supported v1 types

| Type | Meaning |
|---|---|
| `TRANSFER` | Move a positive integer balance between regular accounts. |
| `DEPLOY_CONTRACT` | Validate/deploy restricted WASM and run initialization. |
| `CALL_CONTRACT` | Execute an existing contract with bounded input/value/fuel. |
| `COMPUTE_REQUEST` | Create a finalized compute request. |
| `COMPUTE_ASSIGN` | Record a schedulable provider assignment/generation. |
| `COMPUTE_RECEIPT` | Record a canonical terminal provider result. |
| `PROVIDER_CAPABILITY` (10) | Register or change base provider schedulable capability. |
| `TRANSFER_U64` (11) | Transfer an integer amount using the extended native amount encoding. |
| `TREASURY` (12) | Native governance, reserve and policy actions. |
| `SERVICE` (13) | Native service funding, claims, settlement and retention actions. |
| `PROVIDER_CAPABILITY_V2` (14) | Extend a provider capability with a versioned resource manifest. |

The [envelope decoder](../../src/Transaction/Envelope.php) accepts types
1–3 and 7–14; constants 4–6 are not accepted. Types 11–14 require native economics
to be enabled by genesis. Each action must still pass its own state/authority
checks. Native service actions can financially bind a hosted interval, but
developer application deployment remains a separate controller operation.
See [economic implementation](../economics/PRODUCTION_IMPLEMENTATION_2026-11-15.md)
and [project status](../PROJECT_STATUS.md) for scope and qualification.

On a native economic genesis, successful transactions also pay the policy-defined
native transaction fee. WASM fuel is a separate execution limit; it is not a
client-supplied gas price. A failed contract transition is rejected rather than
recorded as a finalized failed receipt.

## Lifecycle

```text
locally construct + sign
          |
          v
POST /v1/transactions  --> PENDING admission response
          |
          v
candidate block execution and BFT finality
          |
          v
FINALIZED status + durable block history + new state root
```

`PENDING` only means the node accepted the envelope into its admission path.
Clients must poll `/v1/transactions/{hash}/status` or use the CLI until
`FINALIZED`; an unknown or pending transaction is not an application commit.

## Nonces and client sequencing

The sender nonce must exactly equal current finalized account nonce when the
transaction executes. A successful state transition increments it once. Clients
issuing multiple transactions from one account should serialize their nonce
allocation and avoid assuming that broadcast order equals finalized order.

## Signing and submission

The local helper supports development key generation, genesis construction,
transfer signing, contract deployment and contract-call signing. For example:

```bash
php bin/greenwood-cli transfer-sign GENESIS KEYFILE RECIPIENT_HEX AMOUNT NONCE > tx.json
php bin/greenwood tx submit tx.json --rpc=http://127.0.0.1:9201
```

`transfer-sign` chooses type 11 when the supplied trusted genesis enables native
economics, otherwise legacy type 1. Its amount is integer base units; the helper
does not obtain a nonce or funding from a node.

Never pass a private key to public RPC, put it in a CLI configuration file, or
ask a node to create a signature. See [CLI](../cli.md) and
[Public RPC v1](../rpc-v1.md).

## Related documents

- [Accounts](accounts.md)
- [Blocks](blocks.md)
- [Contract quickstart](../contracts/quickstart.md)
- [Compute protocol](../GREENWOOD_COMPUTE_V1.md)

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Core concepts](overview.md) · [Architecture overview](../architecture/overview.md)
<!-- /docs-nav -->
