# Consensus Layer

The consensus layer implements BFT finality.

## BFT Algorithm

Greenwood uses a variant of Tendermint consensus.

### Algorithm Properties

- **Safety**: No two validators finalize different blocks at same height
- **Liveness**: Requires the strict quorum of participating validators and the
  consensus protocol's timing assumptions, not merely three online hosts.
- **Quorum**: `intdiv(2*n,3)+1`; three-validator profile is 3-of-3, four-validator
  local fixture is 3-of-4. Membership is fixed by genesis.

Message/class examples below are explanatory sketches, not wire schemas or exact
PHP class definitions. Current implementation anchors are
`src/Consensus/Runtime/ConsensusDriver.php`, `src/Consensus/Messages/Certificate.php`
and `src/Consensus/Network/Transport.php`.

### Phases

```
Phase 1: Proposal
├── Proposer selected (round-robin)
├── Broadcast block to all validators
└── Validators receive block

Phase 2: Prevote
├── Each validator votes
├── Broadcast prevote to all validators
└── Collect prevotes

Phase 3: Precommit
├── If a matching strict quorum of prevotes is received
├── Broadcast precommit to all validators
└── Collect precommits

Phase 4: Finalize
├── If a matching strict quorum of precommits is received
├── Mark block as final
└── Store finality certificate
```

## Message Types

### Proposal Message

```php
namespace Greenwood\Consensus\Messages;

class Proposal {
    public int $height;
    public int $round;
    public string $blockHash;
    public int $timestamp;
    public string $proposer;
    public string $signature;
}
```

### Prevote Message

```php
class Prevote {
    public int $height;
    public int $round;
    public string $blockHash;
    public int $timestamp;
    public string $validator;
    public string $signature;
}
```

### Precommit Message

```php
class Precommit {
    public int $height;
    public int $round;
    public string $blockHash;
    public int $timestamp;
    public string $validator;
    public string $signature;
}
```

### Finality Certificate

```php
class FinalityCertificate {
    public int $height;
    public int $round;
    public array $precommits;  // validator + signature
}
```

## Consensus State

```php
namespace Greenwood\Consensus;

class ConsensusState {
    public int $height;
    public int $round;
    public ?string $proposedBlock;
    public array $prevotes;      // validator => signature
    public array $precommits;    // validator => signature
    public int $prevoteTimestamp;
    public int $precommitTimestamp;
}
```

## Proposer Rotation

```php
function getProposer(int $height, array $validators): string {
    $index = $height % count($validators);
    return $validators[$index]['address'];
}
```

## Round Timers

```php
// Proposal timeout: 1 second
// Prevote timeout: 1 second  
// Precommit timeout: 1 second
```

## Safety Journal

Tracks validator signatures to prevent double signing:

```php
namespace Greenwood\Consensus\Journal;

class SafetyJournal {
    public function recordSignature(
        string $validator,
        int $height,
        int $round,
        string $signature
    ): void {
        // Store in SQLite
    }
    
    public function checkEquivocation(
        string $validator,
        int $height,
        int $round
    ): bool {
        // Check for conflicting signatures
    }
}
```

## Network Transport

```php
namespace Greenwood\Consensus\Network;

class Transport {
    public function broadcast(
        string $messageType,
        array $message,
        array $peers
    ): void {
        // HTTP POST to each peer
    }
    
    public function receive(): array {
        // HTTP endpoint for incoming messages
    }
}
```

## Consensus Driver

```php
namespace Greenwood\Consensus;

class ConsensusDriver {
    public function run(): void {
        while (true) {
            $state = $this->loadState();
            
            if ($this->shouldPropose($state)) {
                $this->propose($state);
            }
            
            if ($this->shouldPrevote($state)) {
                $this->prevote($state);
            }
            
            if ($this->shouldPrecommit($state)) {
                $this->precommit($state);
            }
            
            if ($this->shouldFinalize($state)) {
                $this->finalize($state);
            }
            
            $this->sleep($interval);
        }
    }
}
```

## Recovery

On node restart:

```php
public function recover(): void {
    // Load last finality height
    // Load last state root
    // Verify finality certificate
    // Join consensus at current height
}
```

## RPC Endpoints

```bash
# Get finality info
GET /v1/chain/finality

# Get validators
GET /v1/chain/validators

# Get chain status
GET /v1/chain/status
```

## Testing

```php
namespace Tests\Consensus;

class TetheredPacemakerModelTest {
    public function testFinality(): void {
        // Simulate 4 validators
        // Propose, prevote, precommit
        // Verify strict quorum for the genesis validator count
    }
    
    public function testByzantine(): void {
        // Simulate 1 Byzantine validator
        // Verify finality still achieved
    }
}
```

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Architecture overview](overview.md) · [Core concepts](../concepts/overview.md)
<!-- /docs-nav -->
