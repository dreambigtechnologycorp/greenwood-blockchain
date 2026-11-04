(module
(import "greenwood" "storage_set" (func $set (param i32 i32 i32 i32) (result i32)))
(import "greenwood" "input_read" (func $read (param i32 i32) (result i32)))
(import "greenwood" "input_size" (func $size (result i32)))
(memory (export "memory") 2 2) (data (i32.const 0) "init")
(func (export "greenwood_init") (result i32)
 i32.const 16 call $size call $read drop
 i32.const 0 i32.const 4 i32.const 16 call $size call $set)
(func (export "greenwood_call") (result i32) i32.const 0))