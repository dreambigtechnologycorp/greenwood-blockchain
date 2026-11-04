(module (import "greenwood" "emit_event" (func $host (param i32 i32 i32 i32) (result i32)))
(memory (export "memory") 1 1) (data (i32.const 0) "td")
(func (export "greenwood_call") (result i32) i32.const 0 i32.const 1 i32.const 1 i32.const 1 call $host))
