(module (import "greenwood" "contract_balance" (func $host (result i64)))
(func (export "greenwood_call") (result i64) call $host))
