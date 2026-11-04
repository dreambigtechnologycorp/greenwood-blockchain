(module
  (import "wasi_snapshot_preview1" "proc_exit" (func (param i32)))
  (func (export "greenwood_call") (result i32) i32.const 42))
