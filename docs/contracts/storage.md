# Contract Storage

Checked against ContractHost, ExecutionOverlay and ContractLimits on October 21,
2026. Storage is raw byte key/value data scoped to the executing contract ID,
not a filesystem path or automatically base64-encoded string.

## Host Calls

All imports belong to the greenwood namespace:

| Import | Parameters | Result |
| --- | --- | --- |
| storage_set | key_ptr, key_len, value_ptr, value_len (i32) | i32 status |
| storage_get | key_ptr, key_len, out_ptr, capacity (i32) | byte length or negative status |
| storage_delete | key_ptr, key_len (i32) | i32 status |

Set/delete return OK (0) on success. Get returns the number of bytes copied;
zero means an existing empty value, not a missing key. NOT_FOUND (-1) indicates
absence, BUFFER_TOO_SMALL (-2) insufficient output capacity, INVALID_ARGUMENT
(-3) invalid bounded input, and LIMIT_EXCEEDED (-6) an exhausted effect limit.
Guests must inspect results. Backend failures and invalid memory access abort
execution rather than masquerading as missing storage.

## Limits

| Bound | Bytes/count |
| --- | ---: |
| Key length | 1-256 bytes |
| Value length | 0-65,536 bytes |
| Distinct modified keys per invocation | 256 |
| Total host calls per invocation | 4,096 |
| Return bytes | 65,536 |

Writes and deletes share the distinct-key limit. Repeated changes to the same key
do not consume another slot. Every host call, including reads and rejected
operations, consumes the host-call budget.

## Overlay Semantics

Reads consult delete markers, then staged writes, then the underlying state view.
Set clears a delete marker; delete removes a staged write. One contract cannot
select another contract's storage namespace through these imports.

The overlay has no automatic commit API. The transaction executor applies sealed
effects only after successful execution and validation. A trap, fuel exhaustion
or fatal host error discards effects. A read-only RPC query never persists even
successful effects.

## Working Reference

The checked-in [reference_storage.wat](../../test/fixtures/reference_storage.wat)
replaces the previous non-compiling storage example and incorrect counter example.
It dispatches the actual method/calldata encoding:

- set: calldata is length-prefixed key followed by length-prefixed value.
- get: calldata is a length-prefixed key; returns raw stored bytes.
- delete: calldata is a length-prefixed key.

Each prefix is u32 big-endian. The fixture checks host statuses and traps for a
missing key or unknown method. Those are fixture choices; the host's NOT_FOUND
result itself is recoverable. set/delete emit topic/data events.

Do not compare memory addresses to compare method strings, treat storage_get's
byte count as an integer stored value, or ignore host results. These were errors
in the removed counter example.

See [ExecutionOverlay tests](../../tests/Contracts/ExecutionOverlayTest.php),
[host integration tests](../../tests/Contracts/ContractHostIntegrationTest.php),
[contract overview](overview.md) and [WASM ABI](../GREENWOOD_WASM_ABI_V1.md).
