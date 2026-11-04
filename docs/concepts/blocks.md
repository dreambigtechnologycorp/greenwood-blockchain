# Blocks

Blocks are the fundamental unit of chain history.

## Block Structure

```json
{
  "version": 1,
  "height": 1,
  "timestamp": 1234567890,
  "prev_block_hash": "genesis_or_previous_hash_hex",
  "transactions": [
    "tx_hash_1_hex",
    "tx_hash_2_hex"
  ],
  "state_root": "state_root_hash_hex",
  "validator": "validator_address_hex",
  "signature": "validator_signature_hex"
}
```

### Fields

| Field | Type | Description |
|-------|------|-------------|
| version | u32 | Block format version |
| height | u64 | Block position in chain |
| timestamp | u64 | Unix timestamp |
| prev_block_hash | hex64 | Previous block hash |
| transactions | array | Transaction hashes |
| state_root | hex64 | Root of state Merkle tree |
| validator | hex64 | Proposer address |
| signature | hex | Proposer signature |

## Block Production

### 1. Proposer Selection

- Round-robin through validator set
- Deterministic based on height and validator order

### 2. Transaction Collection

- Mempool transactions sorted by fee
- Fuel limit enforced per block
- Deterministic ordering

### 3. Execution

- Transactions executed in order
- State updated incrementally
- State root calculated

### 4. Consensus

- Proposer broadcasts block
- Validators prevote
- 3+ prevotes → precommit
- 3+ precommits → finalize

## Block Storage

### Filesystem Layout

```
blockchain/
├── 0000000000000001.json  # Block 1
├── 0000000000000002.json  # Block 2
└── ...
```

### Block File Format

```json
{
  "header": { ...block header... },
  "transactions": [
    {
      "hash": "tx_hash",
      "envelope": "base64_envelope"
    }
  ],
  "finality_certificate": {
    "height": 1,
    "round": 0,
    "votes": [...]
  }
}
```

## Block Hash Calculation

```
SHA3_256(
  version ||
  height ||
  timestamp ||
  prev_block_hash ||
  transactions_root ||
  state_root ||
  validator ||
  signature
)
```

## Genesis Block

The first block (height 0):

```json
{
  "version": 1,
  "height": 0,
  "timestamp": 1234567890,
  "prev_block_hash": "0000...0000",
  "transactions": [],
  "state_root": "initial_state_root",
  "validator": "genesis_validator",
  "signature": "genesis_signature"
}
```

## RPC Endpoints

```bash
# Get block by height
curl http://localhost:9201/v1/blocks/1

# Get block by hash
curl http://localhost:9201/v1/blocks/hash/<hash>

# Get chain status
curl http://localhost:9201/v1/chain/status

# Get finality info
curl http://localhost:9201/v1/chain/finality
```

## Block Finality

### Finality Certificate

```json
{
  "height": 1,
  "round": 0,
  "precommits": [
    {
      "validator": "validator_address",
      "signature": "signature"
    }
  ]
}
```

### Finality Requirements

- **4 validators** in default config
- A strict `>2/3` quorum of the genesis validator set is required (3-of-3 for
  three validators, 3-of-4 for four).
- **Certificate** stored with block

## Chain History

### Rebuilding State

State can be rebuilt from blocks:

```bash
# Reset state
rm ~/greenwood-data/state.sqlite

# Restart node
bin/greenwood start --data-dir=~/greenwood-data
```

Node replays all blocks and rebuilds state.

### Verification

Each block's state root must match:

1. Execute all transactions
2. Calculate new state
3. Compare to state_root in block
4. Reject if mismatch

## Block Time

- **Target**: ~1 second per block
- **Adjustable**: Based on network conditions
- **Consensus-driven**: Finality determines time

## Security

- **Immutable**: Cannot modify past blocks
- **Chain of custody**: Each block links to previous
- **Validator signatures**: Prevents tampering

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Core concepts](overview.md) · [Architecture overview](../architecture/overview.md)
<!-- /docs-nav -->
