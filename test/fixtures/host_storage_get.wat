(module (import "greenwood" "storage_get" (func $host (param i32 i32 i32 i32) (result i32)))
(memory (export "memory") 1 1) (data (i32.const 0) "x")
(func (export "greenwood_call") (result i32) i32.const 0 i32.const 1 i32.const 16 i32.const 3 call $host))
