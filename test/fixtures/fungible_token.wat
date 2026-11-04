(module
  ;; Import greenwood contract functions
  (import "greenwood" "contract_balance" (func $balance (result i64)))
  (import "greenwood" "transfer" (func $pay (param i32 i64) (result i32)))
  (import "greenwood" "input_size" (func $size (result i32)))
  (import "greenwood" "input_read" (func $read (param i32 i32) (result i32)))
  (import "greenwood" "return_value" (func $return (param i32 i32) (result i32)))

  (memory (export "memory") 2 2)
  
  ;; Storage: 65536-65543 = total supply (8 bytes u64)
  ;; Storage: 65544+ = balances (8 bytes per account)
  
  ;; Helper: read u32 from memory
  (func $u32 (param $p i32) (result i32)
    local.get $p i32.load8_u i32.const 24 i32.shl
    local.get $p i32.const 1 i32.add i32.load8_u i32.const 16 i32.shl i32.or
    local.get $p i32.const 2 i32.add i32.load8_u i32.const 8 i32.shl i32.or
    local.get $p i32.const 3 i32.add i32.load8_u i32.or)
  
  (func $ok (param $r i32) local.get $r if unreachable end)
  
  ;; Initialize token supply from init data
  (func (export "greenwood_init") (result i32)
    (local $size i32)
    
    ;; Read 8 bytes of init data as u64 supply
    i32.const 8 call $size
    i32.const 16 local.get $size call $read drop
    
    ;; Write supply to storage at offset 65536
    i32.const 65536 i64.const 0 i64.store
    
    i32.const 0 return)
  
  ;; Main entry point for calls
  (func (export "greenwood_call") (result i32)
    (local $method_len i32)
    (local $args_len i32)
    (local $args_ptr i32)
    
    ;; Read input: u32 method_length | u32 args_length | bytes
    i32.const 0 call $size call $read call $ok
    i32.const 0 call $u32 local.set $method_len
    local.get $method_len i32.const 4 i32.add call $u32 local.set $args_len
    local.get $method_len i32.const 8 i32.add local.set $args_ptr
    
    ;; balance(): method hash = 1634492770 ('bala' = first 4 chars of 'balance')
    local.get $method_len i32.const 7 i32.eq
    i32.const 4 i32.load i32.const 1634492770 i32.eq i32.and
    if
      ;; Validate no args
      local.get $args_len i32.const 0 i32.ne if unreachable end
      
      ;; Get balance from storage (total supply at offset 65536)
      i32.const 65536 i64.load
      
      ;; Return as big-endian u64
      i32.const 65536 i64.load i64.const 56 i64.shr_u i32.wrap_i64 i32.const 65536 i32.store8
      i32.const 65537 i64.load i64.const 48 i64.shr_u i32.wrap_i64 i32.const 65537 i32.store8
      i32.const 65538 i64.load i64.const 40 i64.shr_u i32.wrap_i64 i32.const 65538 i32.store8
      i32.const 65539 i64.load i64.const 32 i64.shr_u i32.wrap_i64 i32.const 65539 i32.store8
      i32.const 65540 i64.load i64.const 24 i64.shr_u i32.wrap_i64 i32.const 65540 i32.store8
      i32.const 65541 i64.load i64.const 16 i64.shr_u i32.wrap_i64 i32.const 65541 i32.store8
      i32.const 65542 i64.load i64.const 8 i64.shr_u i32.wrap_i64 i32.const 65542 i32.store8
      i32.const 65543 i64.load i64.const 0 i64.shr_u i32.wrap_i64 i32.const 65543 i32.store8
      
      i32.const 65536 i32.const 8 call $return call $ok i32.const 0 return
    end
    
    ;; pay(recipient[32], u64 amount): method hash = 7954800 ('yap' = reversed 'pay')
    local.get $method_len i32.const 3 i32.eq
    i32.const 4 i32.load i32.const 7954800 i32.eq i32.and
    local.get $args_len i32.const 40 i32.eq i32.and
    if
      ;; Extract recipient (first 32 bytes from args)
      local.get $args_ptr
      ;; Extract amount (last 8 bytes from args)
      local.get $args_ptr i32.const 32 i32.add call $u32 i64.extend_i32_u i64.const 32 i64.shl
      local.get $args_ptr i32.const 36 i32.add call $u32 i64.extend_i32_u i64.or
      ;; Transfer
      call $pay call $ok i32.const 0 return
    end
    
    unreachable)
)
