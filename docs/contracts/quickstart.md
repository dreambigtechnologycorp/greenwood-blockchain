# Contract quickstart

This guide shows the supported contract shape and local deployment-signing
workflow. Contracts are WebAssembly modules accepted by
`GREENWOOD_WASM_ABI_V1`; they are not HTTP handlers and cannot use WASI.

## Prerequisites

- A deliberately selected local development node/network and a funded development
  account with its local key. See the [local quickstart](../getting-started/quickstart.md).
  Examples below use a disposable local fixture; they do not provision production
  identities or demonstrate live endpoint availability.
- A WAT-to-WASM compiler such as `wat2wasm`.
- The Wasmtime runtime available to validators that execute the transaction.

## 1. Write the smallest valid contract

All Greenwood host imports are in the `greenwood` module. Memory-using imports
require an exported memory named `memory`; the entry point is
`greenwood_call`, takes no arguments, and returns one `i32` or `i64`.

```wat
(module
  (import "greenwood" "return_value"
    (func $return_value (param i32 i32) (result i32)))
  (memory (export "memory") 1 1)
  (data (i32.const 0) "hello")
  (func (export "greenwood_call") (result i32)
    i32.const 0
    i32.const 5
    call $return_value
    drop
    i32.const 0))
```

Save it as `hello.wat` and compile it:

```bash
wat2wasm hello.wat -o hello.wasm
```

The ABI has a deliberately narrow profile: no WASI, no unapproved imports, no
floating-point instructions, and bounded module, memory, table, input, and fuel
limits. Read [WASM ABI v1](../GREENWOOD_WASM_ABI_V1.md) before expanding this
example.

## 2. Sign a deployment locally

The checked-in helper creates a canonical signed deployment envelope. It
prints the JSON wrapper accepted by the public transaction endpoint:

```bash
php bin/greenwood-cli contract-deploy-sign \
  ./local-genesis/genesis.json \
  ./local-genesis/keys/A.key \
  ./hello.wasm \
  '' 0 100000 0 > deploy.json
```

`A.key` is a disposable fixture identity from the local genesis helper. Never use
a production validator identity for application development. Choose the actual
finalized sender nonce; `0` only fits an unused funded fixture account.

The arguments after the key are: WASM path, initialization bytes, attached
value, gas limit, and sender nonce. The helper bounds module bytes, input,
value, and gas before signing; validators revalidate the canonical envelope.

## 3. Submit and wait for finality

```bash
php bin/greenwood tx submit deploy.json --rpc=http://127.0.0.1:9201
php bin/greenwood tx status TRANSACTION_HASH --rpc=http://127.0.0.1:9201
```

Submission returns `PENDING`, not a deployed contract. Poll until the status
is `FINALIZED`. Contract IDs are derived deterministically from deployed WASM
bytes using `GREENWOOD_CONTRACT_V1`. Query
`GET /v1/transactions/{hash}/receipt` after finality for the derived execution
receipt and `contract_id`. The receipt describes finalized execution but is not
a separately certified or state-root-committed record.

## 4. Design calls and storage

Call input is arbitrary bounded bytes supplied by the transaction embedding;
the ABI does not impose a function-name JSON convention. A production client
must create a canonical `CallContract` envelope with the target contract ID,
method, input bytes, attached value, gas limit, nonce, and signature. The local
helper's implemented syntax is `contract-call-sign GENESIS KEYFILE CONTRACT_ID
METHOD CALLDATA ATTACHED_VALUE GAS_LIMIT NONCE`; CALLDATA is the literal argument
bytes, not automatically decoded base64. Submission is a separate signed write.

Read-only queries use `POST /v1/contracts/{id}/call`. The PHP RPC CLI does not
implement a `contract` command group. With `CONTRACT_ID` set to the finalized
64-character lowercase hex contract ID, query the local example directly:

```sh
curl --fail-with-body http://127.0.0.1:9201/v1/contracts/"$CONTRACT_ID"/call \
  -H 'Content-Type: application/json' \
  --data '{"method":"hello","calldata":""}'
```

This executes against finalized state with a zero caller and zero attached value,
without a transaction or persistent effects. Inspect `status`; HTTP 200 can also
carry a runtime failure. Successful `return_data` is hex (`68656c6c6f` for this
example). A signed `contract-call-sign` is a separate state-changing operation.
Never ask a public node to sign for you.

For host imports, status codes, input/output behavior, storage semantics, and
the complete transaction result model, continue with:

- [Contract overview](overview.md)
- [Contract storage](storage.md)
- [WASM ABI v1](../GREENWOOD_WASM_ABI_V1.md)
- [Hello-world WAT example](../examples/contracts/hello-world/contract.wat)
- [Counter WAT example](../examples/contracts/counter/contract.wat) (empty calldata;
  `get`, `increment`, `decrement`; raw four-byte little-endian return value)
- [Tested storage WAT fixture](../../test/fixtures/reference_storage.wat)
- [Tested value WAT fixture](../../test/fixtures/reference_value.wat)

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Contract overview](overview.md) · [WASM ABI v1](../GREENWOOD_WASM_ABI_V1.md)
<!-- /docs-nav -->
