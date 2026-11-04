# Counter Contract

A simple counter that can be incremented and decremented.

## Contract Source

Create `counter.wat`:

```wat
(module
  ;; Import Greenwood functions
  (import "greenwood" "storage_set" (func $storage_set (param i32 i32 i32 i32) (result i32)))
  (import "greenwood" "storage_get" (func $storage_get (param i32 i32 i32 i32) (result i32)))
  (import "greenwood" "emit_event" (func $emit_event (param i32 i32 i32 i32) (result i32)))
  (import "greenwood" "input_size" (func $input_size (result i32)))
  (import "greenwood" "input_read" (func $input_read (param i32 i32) (result i32)))
  (import "greenwood" "return_value" (func $return_value (param i32 i32) (result i32)))
  
  ;; Memory
  (memory (export "memory") 2 2)
  
  ;; Function names (null-terminated)
  (data (i32.const 0) "increment")
  (data (i32.const 10) "decrement")
  (data (i32.const 20) "get")
  
  ;; Events
  (data (i32.const 30) "Counter incremented")
  (data (i32.const 48) "Counter decremented")
  
  ;; Storage key
  (data (i32.const 66) "counter")
  
  ;; Entry point
  (func (export "greenwood_call") (result i32)
    (local $size i32) (local $input_ptr i32)
    (local $key i32) (local $key_len i32)
    (local $value i32) (local $new_value i32)
    (local $func_name i32) (local $func_len i32)
    (local $result i32)
    
    ;; Read input
    call $input_size
    local.set $size
    
    i32.const 0
    local.get $size
    call $input_read
    i32.const 0
    local.set $input_ptr
    
    ;; Get function name (first part of input)
    i32.const 0
    local.set $func_name
    i32.const 10
    local.set $func_len
    
    ;; Get storage key
    i32.const 66
    local.set $key
    i32.const 7
    local.set $key_len
    
    ;; Read current value
    local.get $key
    local.get $key_len
    i32.const 100
    i32.const 4
    call $storage_get
    local.set $result
    
    ;; Check if success
    local.get $result
    i32.const 0
    i32.lt_s
    if
      ;; Value not found, initialize to 0
      i32.const 100
      i32.const 4
      i32.const 0
      i32.const 0
      call $storage_set
      i32.const 0
      local.set $value
    else
      ;; Read value from buffer
      i32.const 100
      i32.load
      local.set $value
    end
    
    ;; Function: increment
    local.get $func_name
    i32.load
    i32.const 105
    i32.eq
    local.get $func_name
    i32.const 1
    i32.load
    i32.const 110
    i32.eq
    i32.and
    local.get $func_name
    i32.const 2
    i32.load
    i32.const 105
    i32.eq
    i32.and
    local.get $func_name
    i32.const 3
    i32.load
    i32.const 109
    i32.eq
    i32.and
    local.get $func_name
    i32.const 4
    i32.load
    i32.const 101
    i32.eq
    i32.and
    local.get $func_name
    i32.const 5
    i32.load
    i32.const 114
    i32.eq
    i32.and
    local.get $func_name
    i32.const 6
    i32.load
    i32.const 109
    i32.eq
    i32.and
    local.get $func_name
    i32.const 7
    i32.load
    i32.const 101
    i32.eq
    i32.and
    local.get $func_name
    i32.const 8
    i32.load
    i32.const 110
    i32.eq
    i32.and
    local.get $func_name
    i32.const 9
    i32.load
    i32.const 116
    i32.eq
    i32.and
    if
      ;; Increment
      local.get $value
      i32.const 1
      i32.add
      local.set $new_value
      
      ;; Store
      local.get $key
      local.get $key_len
      i32.const 100
      i32.const 4
      local.get $new_value
      i32.store
      call $storage_set
      
      ;; Emit event
      i32.const 66
      i32.const 7
      i32.const 30
      i32.const 19
      call $emit_event
      
      ;; Return new value
      i32.const 100
      i32.const 4
      call $return_value
      
      i32.const 0
      return
    end
    
    ;; Function: decrement
    local.get $func_name
    i32.load
    i32.const 100
    i32.eq
    local.get $func_name
    i32.const 1
    i32.load
    i32.const 101
    i32.eq
    i32.and
    local.get $func_name
    i32.const 2
    i32.load
    i32.const 99
    i32.eq
    i32.and
    local.get $func_name
    i32.const 3
    i32.load
    i32.const 114
    i32.eq
    i32.and
    local.get $func_name
    i32.const 4
    i32.load
    i32.const 101
    i32.eq
    i32.and
    local.get $func_name
    i32.const 5
    i32.load
    i32.const 109
    i32.eq
    i32.and
    local.get $func_name
    i32.const 6
    i32.load
    i32.const 101
    i32.eq
    i32.and
    local.get $func_name
    i32.const 7
    i32.load
    i32.const 110
    i32.eq
    i32.and
    local.get $func_name
    i32.const 8
    i32.load
    i32.const 116
    i32.eq
    i32.and
    if
      ;; Decrement
      local.get $value
      i32.const 1
      i32.sub
      local.set $new_value
      
      ;; Store
      local.get $key
      local.get $key_len
      i32.const 100
      i32.const 4
      local.get $new_value
      i32.store
      call $storage_set
      
      ;; Emit event
      i32.const 66
      i32.const 7
      i32.const 48
      i32.const 20
      call $emit_event
      
      ;; Return new value
      i32.const 100
      i32.const 4
      call $return_value
      
      i32.const 0
      return
    end
    
    ;; Function: get
    local.get $func_name
    i32.load
    i32.const 103
    i32.eq
    local.get $func_name
    i32.const 1
    i32.load
    i32.const 101
    i32.eq
    i32.and
    local.get $func_name
    i32.const 2
    i32.load
    i32.const 116
    i32.eq
    i32.and
    if
      ;; Return value
      local.get $value
      i32.const 100
      i32.store
      i32.const 100
      i32.const 4
      call $return_value
      
      i32.const 0
      return
    end
    
    ;; Unknown function
    i32.const 1
    return
  )
)
```