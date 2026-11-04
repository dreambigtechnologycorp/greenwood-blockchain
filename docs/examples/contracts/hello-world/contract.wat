# Hello World Contract

The simplest contract that returns a value.

## Contract Source

Create `hello.wat`:

```wat
(module
  ;; Import Greenwood functions
  (import "greenwood" "input_size" (func $input_size (result i32)))
  (import "greenwood" "return_value" (func $return_value (param i32 i32) (result i32)))
  
  ;; Memory (2 pages = 128KB)
  (memory (export "memory") 2 2)
  
  ;; Export entry point
  (func (export "greenwood_call") (result i32)
    ;; Return "Hello, World!"
    i32.const 4    ;; pointer to string
    i32.const 13   ;; length of string
    call $return_value
    
    ;; Success
    i32.const 0
  )
  
  ;; Data segment
  (data (i32.const 4) "Hello, World!")
)
```

## Compile

```bash
wat2wasm hello.wat -o hello.wasm
```

## Deploy

```bash
# Create deploy transaction
bin/greenwood-cli contract deploy \
  --wasm=hello.wasm \
  --sender=<your_address> \
  --nonce=0 \
  --fee=1000000 \
  --initial-balance=0 \
  --output=/tmp/deploy.json

# Sign transaction
bin/greenwood-cli contract sign-deploy \
  --input=/tmp/deploy.json \
  --private-key=<your_private_key> \
  --output=/tmp/signed.json

# Submit transaction
bin/greenwood tx submit /tmp/signed.json

# Get contract address from transaction
bin/greenwood tx get <tx_hash>
```

## Call

```bash
# Create call transaction
bin/greenwood-cli contract call \
  --contract=<contract_address> \
  --function="hello" \
  --args="" \
  --sender=<your_address> \
  --nonce=1 \
  --fee=1000000 \
  --output=/tmp/call.json

# Sign transaction
bin/greenwood-cli contract sign-call \
  --input=/tmp/call.json \
  --private-key=<your_private_key> \
  --output=/tmp/signed.json

# Submit transaction
bin/greenwood tx submit /tmp/signed.json
```

## Verify

```bash
# Check transaction status
bin/greenwood tx get <call_tx_hash>

# Expected: status = FINALIZED
```