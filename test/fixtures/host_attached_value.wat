(module (import "greenwood" "attached_value" (func $host (result i64)))
(func (export "greenwood_call") (result i64) call $host))
