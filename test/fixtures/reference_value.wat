(module

(import "greenwood" "contract_balance" (func $balance (result i64)))
(import "greenwood" "transfer" (func $pay (param i32 i64) (result i32)))

(import "greenwood" "input_size" (func $size (result i32)))
(import "greenwood" "input_read" (func $read (param i32 i32) (result i32)))
(import "greenwood" "return_value" (func $return (param i32 i32) (result i32)))
(memory (export "memory") 2 2)
(func $u32 (param $p i32) (result i32)
 local.get $p i32.load8_u i32.const 24 i32.shl
 local.get $p i32.const 1 i32.add i32.load8_u i32.const 16 i32.shl i32.or
 local.get $p i32.const 2 i32.add i32.load8_u i32.const 8 i32.shl i32.or
 local.get $p i32.const 3 i32.add i32.load8_u i32.or)
(func $ok (param i32) local.get 0 if unreachable end)

(func (export "greenwood_call") (result i32)
 (local $m i32) (local $p i32) (local $n i32) (local $k i32) (local $v i32) (local $r i32)
 i32.const 0 call $size call $read call $ok
 i32.const 0 call $u32 local.set $m
 local.get $m i32.const 4 i32.add call $u32 local.set $n
 local.get $m i32.const 8 i32.add local.set $p

 ;; balance(): output unsigned 64-bit big-endian balance.
 local.get $m i32.const 7 i32.eq
 i32.const 4 i32.load i32.const 1634492770 i32.eq i32.and
 i32.const 8 i32.load i32.const 16777215 i32.and i32.const 6644590 i32.eq i32.and
 if
  local.get $n if unreachable end
  i32.const 65536 call $balance i64.const 56 i64.shr_u i32.wrap_i64 i32.store8
i32.const 65537 call $balance i64.const 48 i64.shr_u i32.wrap_i64 i32.store8
i32.const 65538 call $balance i64.const 40 i64.shr_u i32.wrap_i64 i32.store8
i32.const 65539 call $balance i64.const 32 i64.shr_u i32.wrap_i64 i32.store8
i32.const 65540 call $balance i64.const 24 i64.shr_u i32.wrap_i64 i32.store8
i32.const 65541 call $balance i64.const 16 i64.shr_u i32.wrap_i64 i32.store8
i32.const 65542 call $balance i64.const 8 i64.shr_u i32.wrap_i64 i32.store8
i32.const 65543 call $balance i64.const 0 i64.shr_u i32.wrap_i64 i32.store8
  i32.const 65536 i32.const 8 call $return call $ok i32.const 0 return
 end
 ;; pay(recipient[32], u64 amount): high u32 then low u32, no float conversions.
 local.get $m i32.const 3 i32.eq
 i32.const 4 i32.load i32.const 7954800 i32.eq i32.and
 if
  local.get $n i32.const 40 i32.ne if unreachable end
  local.get $p
  local.get $p i32.const 32 i32.add call $u32 i64.extend_i32_u i64.const 32 i64.shl
  local.get $p i32.const 36 i32.add call $u32 i64.extend_i32_u i64.or
  call $pay call $ok i32.const 0 return
 end
 unreachable)
)
