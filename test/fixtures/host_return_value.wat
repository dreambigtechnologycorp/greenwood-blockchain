(module (import "greenwood" "return_value" (func $host (param i32 i32) (result i32)))
(memory (export "memory") 1 1) (data (i32.const 0) "answer")
(func (export "greenwood_call") (result i32) i32.const 0 i32.const 6 call $host))
