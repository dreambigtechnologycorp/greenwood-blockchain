(module

(import "greenwood" "storage_set" (func $set (param i32 i32 i32 i32) (result i32)))
(import "greenwood" "storage_get" (func $get (param i32 i32 i32 i32) (result i32)))
(import "greenwood" "storage_delete" (func $delete (param i32 i32) (result i32)))
(import "greenwood" "emit_event" (func $event (param i32 i32 i32 i32) (result i32)))

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

 local.get $n i32.const 4 i32.lt_u if unreachable end
 local.get $p call $u32 local.set $k
 ;; set(bytes key, bytes value)
 local.get $m i32.const 3 i32.eq
 i32.const 4 i32.load i32.const 16777215 i32.and i32.const 7628147 i32.eq i32.and
 if
  local.get $p i32.const 4 i32.add local.get $k i32.add call $u32 local.set $v
  local.get $k local.get $v i32.add i32.const 8 i32.add local.get $n i32.ne if unreachable end
  local.get $p i32.const 4 i32.add local.get $k
  local.get $p i32.const 8 i32.add local.get $k i32.add local.get $v call $set call $ok
  i32.const 4 local.get $m local.get $p local.get $n call $event call $ok
  i32.const 0 return
 end
 local.get $k i32.const 4 i32.add local.get $n i32.ne if unreachable end
 ;; get(bytes key)
 local.get $m i32.const 3 i32.eq
 i32.const 4 i32.load i32.const 16777215 i32.and i32.const 7628135 i32.eq i32.and
 if
  local.get $p i32.const 4 i32.add local.get $k i32.const 65536 i32.const 65536 call $get local.set $r
  local.get $r i32.const 0 i32.lt_s if unreachable end
  i32.const 65536 local.get $r call $return call $ok i32.const 0 return
 end
 ;; delete(bytes key)
 local.get $m i32.const 6 i32.eq
 i32.const 4 i32.load i32.const 1701602660 i32.eq i32.and
 i32.const 8 i32.load16_u i32.const 25972 i32.eq i32.and
 if
  local.get $p i32.const 4 i32.add local.get $k call $delete call $ok
  i32.const 4 local.get $m local.get $p local.get $n call $event call $ok
  i32.const 0 return
 end
 unreachable)
)
