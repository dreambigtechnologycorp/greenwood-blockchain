# Smart Contracts

Checked against current source on October 21, 2026.

Greenwood executes bounded integer-only WASM through Wasmtime. Contracts can
read invocation context, stage storage and balance changes, emit events and
return bytes through the Greenwood host ABI. No WASI, network, filesystem or
process access is exposed.

## Entrypoints and Input

Deployment validates and instantiates code, then invokes optional greenwood_init
with raw init calldata. An absent initializer is permitted. Ordinary calls invoke
greenwood_call, not a WASM export named after the requested method.

Both entrypoints take no arguments and return an i32 or i64. A normal return,
including a nonzero scalar, is successful runtime execution. The scalar is not a
transaction success code. Output bytes are set separately by return_value.

[AbiEncoder](../../src/Contracts/AbiEncoder.php) encodes call input as:

```text
u32 big-endian method byte length
method bytes
u32 big-endian calldata byte length
calldata bytes
```

There is no four-byte function selector, implicit JSON, base64 inside guest
memory, or universal length-prefixed return format. For method set and calldata
00 78, invocation input is 00000003 736574 00000002 0078.

## Writes Versus Queries

Signed DeployContract and CallContract envelopes enter through POST
/v1/transactions. Transport acceptance is not finality. Execution applies effects
to speculative state; finalized blocks establish persistent results.

The local signing helpers are contract-deploy-sign and contract-call-sign in
bin/greenwood-cli. See [quickstart](quickstart.md) for positional syntax.
The former contract deploy/sign-deploy/sign-call flag examples were unsupported.
Never put private key material in command-line arguments or reuse a validator
identity for application development.

Read-only POST /v1/contracts/{id}/call accepts method and base64 calldata in JSON,
runs against finalized state and does not commit effects or consume an account
nonce. Its return_data is hex, not base64. See [RPC v1](../rpc-v1.md).

## Storage, Events and Balances

Storage keys and values are raw bytes scoped to the executing contract.
[Storage](storage.md) documents host statuses and limits. emit_event takes topic
pointer/length and data pointer/length; the host supplies contract identity.
Events have an emission-order index and gain transaction context on commit.
They are derived receipt data, not a separate consensus events table.

Attached value and transfers are staged in an ExecutionOverlay. Fatal execution
failure discards storage, balances, events and output. A successful transaction
commits validated effects and increments the sender nonce once. Gas is Wasmtime
fuel, not a gas-price charge or refund mechanism against account balances.

## Limits and Examples

ContractLimits caps gas at 1,000,000,000 and bounds host calls, storage and events.
Transaction-specific method, calldata and code limits live in
ContractTransactionLimits. Do not assume separate fixed deploy/call fuel budgets
from an old example.

Use the checked-in [storage fixture](../../test/fixtures/reference_storage.wat)
and [value fixture](../../test/fixtures/reference_value.wat), which implement the
actual invocation encoding, instead of the removed pseudo-contract examples.
The [WASM ABI](../GREENWOOD_WASM_ABI_V1.md) specifies imports and runtime behavior;
its dated acceptance checkpoints remain historical evidence, not a fresh physical
test result.
