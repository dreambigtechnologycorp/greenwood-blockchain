(module (import "greenwood" "contract_id" (func $host (param i32) (result i32)))
(memory (export "memory") 1 1)
(func (export "greenwood_call") (result i32) i32.const 0 call $host drop i32.const 0 i32.load8_u))
