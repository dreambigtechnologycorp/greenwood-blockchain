;; Methods: get, increment, decrement. All require empty calldata.
;; Invocation framing: u32 big-endian method length, method bytes,
;; then u32 big-endian calldata length (zero).
;; Storage key: "counter". Storage and return value: four-byte little-endian u32.
;; Missing storage means zero; decrementing zero or incrementing 0xffffffff traps.
;; No access control: any funded caller can change this educational counter.
;; Read-only RPC calls discard writes; use signed calls to persist changes.
;; Compile with wat2wasm; see docs/contracts/quickstart.md for signing/submission.
(module
  (import "greenwood" "input_size" (func $size (result i32)))
  (import "greenwood" "input_read" (func $read (param i32 i32) (result i32)))
  (import "greenwood" "storage_get" (func $get (param i32 i32 i32 i32) (result i32)))
  (import "greenwood" "storage_set" (func $set (param i32 i32 i32 i32) (result i32)))
  (import "greenwood" "return_value" (func $return (param i32 i32) (result i32)))
  (memory (export "memory") 1 1)
  (data (i32.const 0) "increment")
  (data (i32.const 16) "decrement")
  (data (i32.const 32) "get")
  (data (i32.const 128) "counter")

  (func $ok (param $status i32)
    local.get $status if unreachable end)
  (func $u32be (param $p i32) (result i32)
    local.get $p i32.load8_u i32.const 24 i32.shl
    local.get $p i32.const 1 i32.add i32.load8_u i32.const 16 i32.shl i32.or
    local.get $p i32.const 2 i32.add i32.load8_u i32.const 8 i32.shl i32.or
    local.get $p i32.const 3 i32.add i32.load8_u i32.or)
  ;; Compare method bytes at 1028 with a fixed expected string.
  (func $matches (param $expected i32) (param $length i32) (result i32)
    (local $i i32)
    (loop $next
      local.get $i local.get $length i32.eq
      if i32.const 1 return end
      i32.const 1028 local.get $i i32.add i32.load8_u
      local.get $expected local.get $i i32.add i32.load8_u i32.ne
      if i32.const 0 return end
      local.get $i i32.const 1 i32.add local.set $i
      br $next)
    unreachable)

  (func (export "greenwood_call") (result i32)
    (local $length i32) (local $method_length i32) (local $operation i32)
    (local $read_result i32) (local $value i32)
    call $size local.set $length
    ;; Fixed short methods only: bound input before copying into memory.
    local.get $length i32.const 11 i32.lt_u if unreachable end
    local.get $length i32.const 17 i32.gt_u if unreachable end
    i32.const 1024 local.get $length call $read call $ok
    i32.const 1024 call $u32be local.set $method_length
    local.get $method_length i32.const 3 i32.eq
    if
      i32.const 32 i32.const 3 call $matches i32.eqz if unreachable end
      i32.const 0 local.set $operation
    else
      local.get $method_length i32.const 9 i32.ne if unreachable end
      i32.const 0 i32.const 9 call $matches
      if
        i32.const 1 local.set $operation
      else
        i32.const 16 i32.const 9 call $matches i32.eqz if unreachable end
        i32.const 2 local.set $operation
      end
    end
    local.get $method_length i32.const 8 i32.add local.get $length i32.ne
    if unreachable end
    i32.const 1028 local.get $method_length i32.add call $u32be
    if unreachable end

    i32.const 128 i32.const 7 i32.const 2048 i32.const 4 call $get
    local.set $read_result
    local.get $read_result i32.const -1 i32.eq
    if
      i32.const 0 local.set $value
    else
      local.get $read_result i32.const 4 i32.ne if unreachable end
      i32.const 2048 i32.load local.set $value
    end
    local.get $operation i32.const 1 i32.eq
    if
      local.get $value i32.const -1 i32.eq if unreachable end
      local.get $value i32.const 1 i32.add local.set $value
    end
    local.get $operation i32.const 2 i32.eq
    if
      local.get $value i32.eqz if unreachable end
      local.get $value i32.const 1 i32.sub local.set $value
    end
    i32.const 2048 local.get $value i32.store
    local.get $operation
    if
      i32.const 128 i32.const 7 i32.const 2048 i32.const 4 call $set call $ok
    end
    i32.const 2048 i32.const 4 call $return call $ok
    i32.const 0))
