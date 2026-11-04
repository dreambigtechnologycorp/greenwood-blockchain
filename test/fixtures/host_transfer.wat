(module (import "greenwood" "transfer" (func $host (param i32 i64) (result i32)))
(memory (export "memory") 1 1) (data (i32.const 0) "BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB")
(func (export "greenwood_call") (result i32) i32.const 0 i64.const 7 call $host))
