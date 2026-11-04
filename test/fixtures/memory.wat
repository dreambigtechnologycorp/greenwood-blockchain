(module (memory (export "memory") 1 1024)
  (func (export "greenwood_call") (result i32) i32.const 1024 memory.grow))
