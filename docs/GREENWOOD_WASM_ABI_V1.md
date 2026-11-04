# Greenwood WASM ABI V1

This document defines the Greenwood smart contract ABI for v1.

## Pass 3A runtime checkpoint — complete

The native runtime is implemented behind `WasmEngineInterface` / `WasmtimeEngine`.
The raw `validate` / `execute` path preserves the accepted 3A behavior. The
explicit `executeContract` path implements the 3B host ABI below. Contract
transactions and state integration are described in the 3C section at the end.

### Native version and ownership

Bundled Wasmtime **48.0.1**, tested on macOS arm64 with PHP FFI. The binary and
header fingerprints are recorded in `runtime/wasmtime/manifest.json`; these are
reproducibility fingerprints, not an independent release-origin attestation.
Other native platforms have not passed this checkpoint in this environment.
`GREENWOOD_WASMTIME_LIB` can select a compatible operator-supplied artifact;
operators must match the pinned version/build layout and rerun acceptance before
using another artifact. `getVersion()` reports the configured version.

Concrete instance, extern and value layouts come from the bundled `instance.h`,
`extern.h`, `tag.h` and `val.h` (including GC fields enabled in `conf.h`). Tests
compare every copied concrete layout with those headers. `instance_new` receives
context*, module*, NULL imports, zero imports, instance* and trap**. Function
calls return error*, take a **value** result count, and write values to a result
array. `wasmtime_context_set_fuel` returns an owned error* or NULL. Config setters
used here return void. Instruction trap codes use the bundled enum.

Engine construction consumes the config. Each execution owns one module and one
fresh store. Instance/function handles belong to that store and have no separate
destructor. Found externs, errors, traps, native message vectors, stores, modules
and engines are released on their applicable success/failure paths. Integer
results require no GC unroot operation. The raw path registers no host functions.
No path registers WASI. See 3B below for FFI scope lifetime and linker ownership.

### Deterministic execution and limits

Fuel enabled; epoch interruption disabled; debug info disabled; SIMD, relaxed
SIMD, bulk memory, multi-memory and memory64 disabled. Stack: 2 MiB. Per store:
one instance, one table, one memory, 64 MiB maximum memory and 1000 maximum table
elements. The native limiter enforces bounds on resource growth as well as
creation. Fuel limit is an integer in 0..1,000,000,000. Fuel accounting includes
instantiation and data initialization, not only the exported function body.
Out-of-fuel is classified by native trap code **OUT_OF_FUEL**, not error text.
Ordinary traps remain a distinct result. Invalid modules throw validation errors;
missing exports and function/signature errors return explicit result statuses.

Native WASM validation runs first. A separate bounded binary parser then enforces
the conservative **3A profile**: at most 1 MiB of module bytes, integer i32/i64
function/global/local types and integer instructions, no imports, no start
function, no nonempty element segments, no extensions, and explicitly bounded
unshared 32-bit memories/tables. Custom/data payload bytes are not mistaken for
instructions. Function types allow at most 128 parameters and one integer result;
execution currently accepts no arguments and exactly one integer result.
The parser supports up to 10,000 functions/types/globals/exports/data segments and
65,536 locals per function. Native validation additionally enforces WASM grammar
and typing. This is the raw-runtime profile; host execution uses the explicit 3B profile below.

### Reproduction and evidence

Fixtures are generated from checked-in WAT using the real Wasmtime WAT compiler:

```sh
php -d ffi.enable=true test/generate_fixtures.php
php -d ffi.enable=true test/validate_fixture.php test/fixtures/main.wasm
php -d ffi.enable=true test/native_probe.php instantiate
php -d ffi.enable=true test/native_probe.php export
php -d ffi.enable=true test/native_probe.php call
php composer.phar test:contracts
```

`main.wasm` preserves the corrected no-result module. `valid.wat` / `valid.wasm`
export `greenwood_call` returning i32 42. The malformed historical body-size
encoding is retained only as a negative test. The default export fixture consumes
**2 fuel**, identical across 100 executions. The active-data fixture returns 67
and consumes **16,388 fuel** with a sufficient budget; 100 fuel exhausts during
initialization. The infinite-loop fixture exhausts its 100-fuel allowance.

Acceptance includes ordinary trap versus fuel exhaustion, successful calls after
traps, missing/wrong-kind exports, signature errors, native-valid WASI/float/resource
policy rejections, memory growth limits, i64 returns, malformed WASM, 50 engine
lifecycle cycles mixing failures and success, and Pass 2 regressions.
Machine-readable execution evidence is in `docs/pass3a-acceptance.json`.

## Pass 3B — implemented host ABI and speculative effects

Identifier: `GREENWOOD_WASM_ABI_V1`, version **1**. All imports are functions in
namespace **`greenwood`**. Exact names and signatures are enforced before
instantiation. Unknown namespaces/names, WASI, duplicate names, nonfunction
imports and incorrect signatures are rejected. All other 3A integer-only,
instruction, module and resource restrictions remain in effect.

`WasmtimeEngine::executeContract(wasm, context, stateView, entrypoint)` defaults
to `greenwood_call`. The export takes no arguments and returns one i32 or i64.
In **3B**, this scalar is an uninterpreted runtime result, not a transaction
success code. A normal return retains effects even when the scalar is nonzero.
Recoverable host statuses can be inspected and handled by the guest.
`return_value` supplies separate arbitrary output bytes. No transaction envelope,
nonce, deployment, method encoding, state-root or commit behavior is activated.

### Execution context

The immutable `ContractExecutionContext` contains:

- `caller`: raw 32 bytes, authenticated by the embedding caller.
- `contractId`: raw 32 bytes, selected by the embedding caller.
- `input`: arbitrary bounded bytes.
- `attachedValue`: integer in 0..9,223,372,036,854,775,807.
- `gasLimit`: integer in 0..1,000,000,000.

Guests cannot change context. Internal identities are raw bytes, not hex.
64-bit PHP is required. i64 money uses only the nonnegative signed range;
negative guest amounts are invalid and float conversion is never used.

### Function signatures and memory

Unless marked i64, every parameter below is **i32**, interpreted as a nonnegative
signed integer. The result is i32 unless marked i64. A high-bit-set pointer or
length is negative and rejected. Memory-using calls require the guest's own
unshared memory exported as **`memory`**. Other calls need no memory export.

| Import | Parameters | Result semantics |
|---|---|---|
| `input_size` | none | Input length in bytes. |
| `input_read` | ptr, len | Status; copy the first `len` input bytes. Requires `len <= input_size`; no input cursor. |
| `caller` | out_ptr | Status; write exactly 32 authenticated caller bytes. |
| `contract_id` | out_ptr | Status; write exactly 32 current contract-ID bytes. |
| `storage_get` | key_ptr, key_len, out_ptr, out_capacity | Complete value length on found; negative status otherwise. |
| `storage_set` | key_ptr, key_len, value_ptr, value_len | Status; set a value in this contract's overlay namespace. |
| `storage_delete` | key_ptr, key_len | Status; add a delete marker, including for a missing key. |
| `attached_value` | none | **i64**: original attached value from context. |
| `contract_balance` | none | **i64**: speculative balance including attachment and transfers. |
| `transfer` | recipient_ptr: i32, amount: i64 | Status; transfer a positive amount to a regular account identified by 32 raw bytes. |
| `emit_event` | topic_ptr, topic_len, data_ptr, data_len | Status; append an ordered event. Empty topic/data allowed. |
| `return_value` | ptr, len | Status; copy output bytes. Last successful call wins, including an empty value. |

`GuestMemory` resolves the current memory for every operation, including after
`memory.grow`. It checks `ptr <= memory_size` and `len <= memory_size - ptr`,
avoiding addition overflow. Reads/writes copy bytes; no native pointer survives a
callback or escapes Wasmtime-facing classes. Memory extern handles are released
after each operation. Zero-length accesses are allowed at `ptr == memory_size`,
but not beyond it; they never dereference a pointer.

Negative ranges, missing/wrong-kind memory exports and out-of-bounds accesses are
fatal host failures. For byte fields with explicit ABI size limits, a nonnegative
over-limit size returns INVALID_ARGUMENT without accessing memory. No truncated
read or partial write occurs. Host arguments are processed in signature order.
`storage_get` checks the full output capacity's memory range before backend lookup.

Storage keys are arbitrary **nonempty** bytes. Values can be empty. A found value
is copied completely and its length returned; `0` means FOUND with an empty value,
which is distinct from NOT_FOUND (-1). Insufficient output capacity returns -2
without writing. No storage import accepts a selectable contract ID.

### Guest statuses and fatal failures

| Value | Meaning |
|---|---|
| 0 | OK; for storage_get, FOUND with zero bytes |
| -1 | NOT_FOUND, storage_get only |
| -2 | BUFFER_TOO_SMALL, storage_get only |
| -3 | INVALID_ARGUMENT: invalid key/byte size, excessive input prefix, nonpositive transfer |
| -4 | INSUFFICIENT_BALANCE |
| -5 | OVERFLOW: a resulting balance exceeds the supported integer range |
| -6 | LIMIT_EXCEEDED: event, successful-transfer or modified-storage-key limit |

Positive storage_get results are value lengths. input_size and the i64 getters
return values, not statuses. A recoverable error makes **no changes for that
operation**; earlier valid effects remain speculative and can be retained if the
guest handles the error and returns normally.

Backend exceptions, invalid backend values, unexpected PHP exceptions, callback
arity/type mismatch, host-call-budget exhaustion and invalid memory access abort
execution. Backend exceptions are wrapped before reaching the guest-status
handler, including exceptions with status-like codes. Backend failure must never
become NOT_FOUND.

The callback catches PHP exceptions rather than unwinding through C. It records a
local diagnostic and transfers ownership of a host trap to Wasmtime. Wasmtime can
surface callback failure through its error pointer; the wrapper frees that error
and reports `error` with the local host diagnostic. Native instruction traps
remain `trap`; native OUT_OF_FUEL remains `fuel_exhausted`. Diagnostics are local,
not consensus-encoded data.

### Read-only state, overlays and balances

`ContractStateViewInterface` has only storage, account-balance and contract-balance
reads. Its caller must supply a stable speculative-state view for the entire
execution. Missing storage is null; missing balances are zero; backend failures
throw. Balance baselines are cached for the execution. No canonical State object
or database write API is supplied to the host.

Storage reads consult delete markers, then overlay writes, then the underlying
view. Set clears a delete marker; delete removes an earlier write. The namespace
is always `(context.contractId, key)`.

Effects contain storage writes/deletes, separate account and contract balance
deltas, events and output bytes. PHP map keys have a NUL prefix before raw keys
or IDs to prevent PHP numeric-string key coercion. This is an implementation
container, **not a wire format**; the overlay context supplies its storage
contract ID.

Before WASM entry, attached value speculatively debits the caller account and
credits this contract. Both balances are validated before either delta is
recorded. Attachment occurs once, including zero attachment. Insufficient funds,
overflow or backend failure prevents execution and discards effects.

Transfers credit the **regular account namespace**. Every 32-byte address pattern
is representable, consistent with base Transfer's address model; there is no
recipient-existence or public-key lookup requirement. A recipient whose raw bytes
match a contract ID still denotes a regular account. Negative final balances and
overflow are forbidden. Both sides are checked before either delta changes.
Transferring to the caller can offset its attached-value debit. Only successful
transfer operations count against the transfer limit.

A normal return produces `ContractExecutionResult(runtime, overlay)` with a sealed
overlay. **No automatic commit exists in 3B.** Trap, fuel exhaustion, fatal host
error, invalid memory, validation failure, missing export or wrong export/signature
discards all writes/deletes, balance deltas, events and output. Attached value and
earlier transfers disappear with the overlay. The underlying view is unchanged
on success as well as failure.

### Events and return values

An immutable event contains raw `contractId`, raw `topic`, raw `data` and a
zero-based `eventIndex` in emission order. There is no transaction hash in 3B.
Events are local overlay results and are never published during execution.

Return bytes default to empty. Last successful `return_value` wins, including an
empty replacement. A rejected replacement preserves the earlier value. Fatal
execution failure discards the entire output with all other effects.

### Central limits and fuel

Host limits are centralized in `ContractLimits`. Existing native VM limits stay
in `WasmtimeConfig`.

| Limit | Value |
|---|---:|
| MAX_INPUT_BYTES | 65,536 |
| MAX_STORAGE_KEY_BYTES | 256 |
| MAX_STORAGE_VALUE_BYTES | 65,536 |
| MAX_RETURN_BYTES | 65,536 |
| MAX_EVENT_TOPIC_BYTES | 256 |
| MAX_EVENT_DATA_BYTES | 4,096 |
| MAX_EVENTS_PER_CALL | 64 |
| MAX_HOST_TRANSFER_OPERATIONS | 64 |
| MAX_STORAGE_KEYS_PER_CALL | 256 |
| MAX_HOST_CALLS | 4,096 |
| MAX_GAS | 1,000,000,000 |

Distinct modified keys include writes and deletes; revisiting an existing key
does not consume another slot. Every host invocation, including reads and rejected
operations, consumes one host-call slot. The 4,097th invocation aborts the call.
These limits bound callback work and accumulated effects in addition to fuel.

Gas consumed is **Wasmtime 48.0.1 fuel**, including initialization and guest
instructions invoking hosts. 3B adds no host-fuel tariff. Host work is bounded by
the fixed call/byte/effect limits above. No wall-clock metering, WASI, randomness,
network, filesystem or process access is exposed.

### Native ownership and acceptance

3B uses the bundled 48.0.1 `wasmtime_func_callback_t`, linker APIs, caller/memory
access, trap creation and the `wasm.h` function-type/vector APIs required by those
Wasmtime calls. It never mixes classic wasm function objects with native Wasmtime
handles. Callback/linker/caller/memory/trap signatures are compared with bundled
headers in tests. The matching release `wasm.h` is now included.

Function type construction consumes its value-type vectors. Registration borrows
the type, which is then deleted. PHP closures remain strongly held until after
store destruction; linker deletion and closure release follow. Caller handles
are borrowed only during callbacks. No instance/function destructor is invented.

The FFI CType scope is retained per library for the process lifetime. Repeated
scope destruction exposed a PHP 8.5 FFI field-access crash during 3B regression
testing; retaining the scope resolved it. Native engines, modules, stores and
linkers retain their normal ownership and cleanup.

```sh
php -d ffi.enable=true test/generate_fixtures.php
php -d ffi.enable=true test/host_gate.php input_size
php -d ffi.enable=true vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Contracts/ExecutionOverlayTest.php
php -d ffi.enable=true vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Contracts/ContractHostIntegrationTest.php
php composer.phar test:contracts
php composer.phar test
python3 tests/Fixtures/verify_vectors.py
```

Every required host has a checked-in WAT/WASM fixture. Additional fixtures perform
storage, event, transfer and output operations followed by success, trap, fuel
exhaustion or bad memory. Tests also compile adversarial WAT using the real
Wasmtime compiler; no manual WASM body-length arithmetic is used. Acceptance
compares status, fuel, storage, balances, events and output across 100 executions,
then exercises 50 fresh-engine cycles mixing fatal host failures and success.
Raw 3A and real-process Pass 2 remain separate regression requirements.

At the 3B checkpoint, contract transactions and persistence were not activated.
The following 3C section adds them without changing the standalone 3B API.
Multi-node contract acceptance and compute remain later gates; this is not
launch-ready.

## Pass 3C — transaction entrypoints and receipt context

Contract transactions now enter through the authenticated envelope and shared
State transaction dispatcher. The 3A raw API and 3B host effects retain their accepted behavior.
One 3C integration correction makes host missing-export results report consumed
instantiation fuel rather than an absent fuel value; raw 3A behavior is unchanged. The 3C deployment API adds an explicit optional-init
mode; missing exports are still failures on ordinary calls.

### Deployment initialization

The transaction layer calls `WasmtimeEngine::executeDeployment`, using
`greenwood_init` if present. The export takes no parameters and returns one i32
or i64, using the same hosts as normal calls. Input is **raw init_calldata** with
no additional method or length prefix. Context caller is the deployer; contractId
is the domain-separated hash of complete WASM bytes; attachedValue is the
transaction's value. That value enters the overlay before instantiation/entry.

If `greenwood_init` is absent, deployment succeeds after successful validation
and instantiation. Code and attached value still become state on commit. Active
data initialization still consumes fuel, even without init. A present nonfunction
export, wrong signature, trap, fuel exhaustion or fatal host failure causes full
deployment rollback. No code/balance/storage/event/nonce change survives.
Duplicate deployment is rejected before init; initialization never reruns against
existing code. A constructor may use its own storage/balance namespace before
code is committed; code and the sealed overlay publish atomically afterward.

### Normal call input

Every CallContract executes `greenwood_call`, not a dynamically named WASM export.
Its input is exactly:

```
u32 method_byte_length (big-endian)
method bytes
u32 calldata_byte_length (big-endian)
calldata bytes
```

Method matches case-sensitive ASCII `[A-Za-z_][A-Za-z0-9_]{0,63}`. Calldata remains
arbitrary bytes; interpretation belongs to the contract. No JSON, terminator,
normalization or implicit padding is inserted. Example, method `set` and calldata
`00 78`:

```
00000003 736574 00000002 0078
```

Call calldata is limited to 65,464 bytes, leaving space for a 64-byte method and
two four-byte prefixes within MAX_INPUT_BYTES=65,536. Init calldata keeps the full
65,536-byte limit. Deployed code is capped at 524,288 bytes to fit the unchanged
1 MiB block bound with envelope/calldata overhead; the standalone 3A/3B native
module limit remains 1 MiB. ContractTransactionLimits centralizes these L1 limits.

### Transaction outcome and derived data

As in 3B, the exported integer is an uninterpreted scalar: normal return succeeds,
including nonzero values. Output bytes use return_value. Contracts can inspect
recoverable host statuses and trap when their application wants the transaction
to fail. Any execution failure rejects the transaction and candidate block;
sender nonce and all contract/account state remain unchanged. Success commits
the sealed overlay and increments sender nonce once. Gas is consumed Wasmtime
fuel, not a charge against account balances; there is no host tariff or gas price.

`ContractStateView` clones the current speculative State for stable reads. The
execution layer uses `ContractStateCommitter` to validate/apply an overlay in a
second detached State, then publishes it only after the whole effect set and
state-root calculation succeed. The host and overlay still cannot commit.

`ContractReceipt` contains status, gas_used, return_data, events, contract_id and
transaction_hash. Successful 3B events are enriched into immutable
`ContractTransactionEvent` values with transaction_hash. Event order is block
transaction order followed by zero-based event_index. Failed effects have no
events or output. Native diagnostics never enter deterministic receipts.

Receipts/events are derived, indexed data, excluded from the unified state root
and unchanged block header. SQLite stores their display representation with hex
binary fields and exact integer values. Replay regenerates receipts/events from
signed transactions rather than trusting stored results. Persistent code,
storage and contract balances are included in the unified logical state root as
specified in GREENWOOD_PROTOCOL_V1.

### Reference contracts and acceptance

`reference_storage.wat` dispatches:

- `set`: calldata `bytes(key) | bytes(value)`; writes storage and emits a `set`
  event with that calldata. The event data limit also bounds this sample's input.
- `get`: calldata `bytes(key)`; returns the stored bytes. Missing keys trap in this
  reference implementation; the underlying host's NOT_FOUND remains recoverable.
- `delete`: calldata `bytes(key)`; deletes storage and emits a `delete` event.

`reference_value.wat` dispatches:

- `balance`: empty calldata; returns u64 big-endian speculative contract balance.
- `pay`: calldata `recipient[32] | u64 amount` in big-endian; transfers native value.

These fixtures assert host statuses and trap on unknown methods or rejected host
operations. They introduce no token standard. Constructor and failure fixtures
exercise attached value, init input, storage/event/transfer/output rollback and
fuel exhaustion. All fixtures are compiled with the actual Wasmtime WAT compiler.

`tests/Fixtures/contracts-v1.json` freezes twelve complete execution vectors,
including receipts and pre/post logical roots. Single-process certified block
replay and SQLite destruction tests reproduce code, storage, balances, nonces,
receipts, height/hash/root. No multi-node contract acceptance has run in 3C;
that remains the 3E gate. This pass does not implement containerd or client RPCs.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](README.md) · [Contract overview](contracts/overview.md) · [Contract quickstart](contracts/quickstart.md)
<!-- /docs-nav -->
