# Consensus

Greenwood uses a BFT (Byzantine Fault Tolerant) consensus algorithm.

## BFT Consensus

### Fault Tolerance

- **Genesis-fixed equal-power validators**; current certificate quorum requires at least three.
- **Strict >2/3 quorum**: `intdiv(2*n,3)+1` votes.
- Three validators require all three; the four-validator local fixture requires
  three and tolerates one unavailable validator for liveness.

### Safety Properties

- **No double signing**: durable signing journals prevent conflicting local signatures.
- **Finality guarantee**: verified strict-quorum certificates.
- **Restart recovery**: Resume from finality

## Consensus Phases

### 1. Proposal

```
Proposer broadcasts block to all validators
```

### 2. Prevote

```
All validators:
- Verify block
- Sign prevote message
- Broadcast to all validators
```

### 3. Precommit

```
If the required quorum of matching prevotes is received:
- Sign precommit message
- Broadcast to all validators
```

### 4. Finalize

```
If the required quorum of matching precommits is received:
- Mark block as final
- Store finality certificate
- Begin next round
```

## Message Types

The JSON below is conceptual, not the wire schema. Actual transport uses a
`type`/base64 `payload` envelope containing canonical binary message bytes; source
is `src/Consensus/Messages` and `src/Consensus/Network/Transport.php`.

### Proposal

```json
{
  "type": "proposal",
  "height": 1,
  "round": 0,
  "block": "block_hash",
  "timestamp": 1234567890,
  "proposer": "validator_address",
  "signature": "proposer_signature"
}
```

### Prevote

```json
{
  "type": "prevote",
  "height": 1,
  "round": 0,
  "block": "block_hash",
  "timestamp": 1234567890,
  "validator": "validator_address",
  "signature": "validator_signature"
}
```

### Precommit

```json
{
  "type": "precommit",
  "height": 1,
  "round": 0,
  "block": "block_hash",
  "timestamp": 1234567890,
  "validator": "validator_address",
  "signature": "validator_signature"
}
```

## Finality Certificate

```json
{
  "height": 1,
  "round": 0,
  "precommits": [
    {
      "validator": "validator_address_1",
      "signature": "signature_1"
    },
    {
      "validator": "validator_address_2",
      "signature": "signature_2"
    },
    {
      "validator": "validator_address_3",
      "signature": "signature_3"
    }
  ]
}
```

## Round-Robin Proposer

Proposer selected deterministically:

```
proposer = sorted_validators[(height + round) % validator_count]
```

## Safety Journal

Tracks validator behavior:

```
SigningJournal:
- Prevents double signing
- Detects equivocation
- Stores signed messages
```

## Network Transport

Messages sent via HTTP:

```
Validator A → Validator B: POST /consensus/proposal
Validator B → Validator A: POST /consensus/prevote
```

## Consensus State

```php
class ConsensusState {
    public int $height;
    public int $round;
    public string $proposer;
    public ?string $proposedBlock;
    public array $prevotes;
    public array $precommits;
}
```

## Recovery

On restart:

1. Load last finality height
2. Load last state root
3. Verify and replay authoritative block history from genesis
4. Rebuild current state
5. Join consensus at current height

## RPC Endpoints

```bash
# Get finality info
curl http://localhost:9201/v1/chain/finality

# Get validators
curl http://localhost:9201/v1/chain/validators

# Get chain status
curl http://localhost:9201/v1/chain/status
```

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Core concepts](overview.md) · [Architecture overview](../architecture/overview.md)
<!-- /docs-nav -->
