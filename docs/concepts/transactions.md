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
| `PROVIDER_CAPABILITY` | Register or change provider schedulable capability. |

The envelope implementation accepts only the protocol types above. Hosted
application deployment is a separate controller operation, not another envelope type.

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
