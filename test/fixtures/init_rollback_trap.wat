(module
(import "greenwood" "storage_set" (func $set (param i32 i32 i32 i32) (result i32)))
(import "greenwood" "emit_event" (func $event (param i32 i32 i32 i32) (result i32)))
(import "greenwood" "transfer" (func $transfer (param i32 i64) (result i32)))
(import "greenwood" "return_value" (func $return (param i32 i32) (result i32)))
(memory (export "memory") 1 2)
(data (i32.const 0) "xnewtdanswer")
(data (i32.const 32) "BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB")
(func (export "greenwood_init") (result i32)
 i32.const 0 i32.const 1 i32.const 1 i32.const 3 call $set drop
 i32.const 4 i32.const 1 i32.const 5 i32.const 1 call $event drop
 i32.const 32 i64.const 7 call $transfer drop
 i32.const 6 i32.const 6 call $return drop
unreachable))
