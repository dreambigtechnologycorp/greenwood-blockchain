# Accounts

An account is identified by a 32-byte address derived from an Ed25519 public
key. It holds an integer balance and a monotonically increasing nonce.

## Identity and authorization

```text
address = H(GREENWOOD_ACCOUNT_V1, ed25519_public_key)
```

The public key is carried in every signed transaction envelope. A validator
derives the address again, verifies that it matches the sender field, and
verifies the detached Ed25519 signature over the canonical signing digest.
There is no account-password API and private keys must never be sent to the
node or included in CLI configuration.

## Balance and nonce

Balances are non-negative integer base units; floating-point values are never
valid consensus inputs. The nonce is a `u32` and a state transition requires
the transaction nonce to equal the account's current nonce. A successful
transaction advances the sender nonce exactly once.

This exact-match rule prevents replay and makes concurrent client submission
explicit: sign transactions from one account in nonce order and wait for
finality before assuming the next account state.

## Create development keys

The local helper creates a raw serialized 96-byte Ed25519 keypair file and
prints its public key and address:

```bash
php bin/greenwood-cli account create ./development-account.key
```

The command refuses to overwrite an existing path. Use restrictive permissions
and a proper key-management process outside development. Validator and provider
keys have additional operational requirements in their respective guides.

## Query finalized account state

The public API uses lowercase 64-character hexadecimal account IDs:

```bash
php bin/greenwood account get ACCOUNT_HEX --rpc=http://127.0.0.1:9201
php bin/greenwood account balance ACCOUNT_HEX --rpc=http://127.0.0.1:9201
php bin/greenwood account nonce ACCOUNT_HEX --rpc=http://127.0.0.1:9201
```

All values come from finalized state. An unknown account returns
`ACCOUNT_NOT_FOUND`; it is not silently represented as an account with zero
balance.

## Related documents

- [Transactions](transactions.md)
- [Protocol hashing](hashing.md)
- [Public RPC v1](../rpc-v1.md)

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Core concepts](overview.md) · [Architecture overview](../architecture/overview.md)
<!-- /docs-nav -->
