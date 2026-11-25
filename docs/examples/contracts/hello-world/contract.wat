;; Compile from the repository root:
;; wat2wasm docs/examples/contracts/hello-world/contract.wat -o /tmp/hello.wasm
;; Deployment/signing/query instructions: docs/contracts/quickstart.md.
;; Every valid method/calldata invocation returns these UTF-8 bytes.
(module
  (import "greenwood" "return_value"
    (func $return_value (param i32 i32) (result i32)))
  (memory (export "memory") 1 1)
  (data (i32.const 0) "Hello, World!")
  (func (export "greenwood_call") (result i32)
    i32.const 0
    i32.const 13
    call $return_value
    if unreachable end
    i32.const 0))
