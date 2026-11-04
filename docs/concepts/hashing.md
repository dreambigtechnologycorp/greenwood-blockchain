# Protocol hashing

Greenwood v1 uses **BLAKE2b-256** with explicit domain separation. It does not
use SHA-3, JSON serialization, or PHP object serialization for consensus
identity.

## Hash construction

For a domain string and raw payload bytes:

```text
H(domain, payload) = BLAKE2b-256(short(ASCII(domain)) || payload)
short(x)           = u16 big-endian length || x
```

The domain is part of the preimage. A signature digest, transaction hash,
block hash, account address, and Merkle node are therefore different objects
even when they contain related data.

## Important domains

| Domain | Purpose |
|---|---|
| `GREENWOOD_ACCOUNT_V1` | 32-byte account address from an Ed25519 public key. |
| `GREENWOOD_TX_SIGN_V1` | Digest signed by an account key. |
| `GREENWOOD_TX_V1` | Identity of a complete signed transaction envelope. |
| `GREENWOOD_BLOCK_V1` | Identity of a canonical block header. |
| `GREENWOOD_CONTRACT_V1` | Contract identity from WASM bytes. |
| `GREENWOOD_PROPOSAL_V1`, `GREENWOOD_PREVOTE_V1`, `GREENWOOD_PRECOMMIT_V1` | Consensus message signatures. |
| `GREENWOOD_TX_LEAF_V1`, `GREENWOOD_TX_NODE_V1`, `GREENWOOD_TX_EMPTY_V1` | Ordered transaction Merkle tree. |
| `GREENWOOD_STATE_LEAF_V1`, `GREENWOOD_STATE_NODE_V1`, `GREENWOOD_STATE_EMPTY_V1` | Logical state-root commitment. |

Hex strings are presentation values. Protocol fields and hashes are raw bytes;
API identifiers are lowercase 64-character hex encodings of those bytes.

## What is hashed

- An account address is `H(GREENWOOD_ACCOUNT_V1, public_key)`.
- The signing digest covers canonical unsigned envelope bytes, including the
  sender's public key. The signature itself is excluded from that digest.
- The transaction ID hashes the complete canonical signed envelope.
- A block ID hashes the canonical header, whose commitments include parent,
  transaction root, prior state root, resulting state root, and genesis hash.

The authoritative field layout and bounds are in
[GREENWOOD_PROTOCOL_V1](../GREENWOOD_PROTOCOL_V1.md).

## Practical rules

1. Never hash a decoded JSON object and expect a protocol identifier.
2. Never change a domain string without a protocol version change.
3. Never compare identifiers after normalizing case; lowercase hex is required
   at public API boundaries.
4. Do not use host timestamps, filesystem paths, or runtime output in consensus
   commitments unless the protocol explicitly includes them.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Core concepts](overview.md) · [Architecture overview](../architecture/overview.md)
<!-- /docs-nav -->
