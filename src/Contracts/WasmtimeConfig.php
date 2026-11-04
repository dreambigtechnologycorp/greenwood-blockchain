<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

final class WasmtimeConfig
{
    public const WASMTIME_VERSION = "48.0.1";
    public const SHARED_LIB_FILENAME = "libwasmtime.dylib";
    public const MAX_WASM_STACK = 2 * 1024 * 1024;
    public const MAX_INSTANCES = 1;
    public const MAX_MEMORY = 64 * 1024 * 1024;
    public const MAX_TABLES = 1;
    public const MAX_TABLE_ELEMENTS = 1000;

    public static function checkFFI(): void
    {
        if (!extension_loaded('FFI')) {
            throw new WasmtimeUnavailableException('FFI extension not loaded');
        }
        $ffiEnable = ini_get('ffi.enable');
        if ($ffiEnable === 'false' || $ffiEnable === 'none') {
            throw new WasmtimeUnavailableException('FFI usage disabled in php.ini');
        }
    }

    public static function findLibrary(): string
    {
        $envPath = getenv('GREENWOOD_WASMTIME_LIB');
        if ($envPath && file_exists($envPath)) {
            return $envPath;
        }
        $platform = php_uname('m');
        $os = php_uname('s');
        $baseDir = dirname(__DIR__, 2) . '/runtime/wasmtime/';
        $libPath = '';
        if ($os === 'Darwin') {
            if ($platform === 'arm64') {
                $libPath = $baseDir . 'darwin-arm64/' . self::SHARED_LIB_FILENAME;
            } elseif ($platform === 'x86_64') {
                $libPath = $baseDir . 'darwin-x86_64/' . self::SHARED_LIB_FILENAME;
            }
        } elseif ($os === 'Linux') {
            if ($platform === 'aarch64') {
                $libPath = $baseDir . 'linux-arm64/libwasmtime.so';
            } elseif ($platform === 'x86_64') {
                $libPath = $baseDir . 'linux-x86_64/libwasmtime.so';
            }
        }
        if ($libPath && file_exists($libPath)) {
            return $libPath;
        }
        $systemPaths = [
            '/usr/local/lib/' . self::SHARED_LIB_FILENAME,
            '/opt/homebrew/lib/' . self::SHARED_LIB_FILENAME,
            '/usr/lib/' . self::SHARED_LIB_FILENAME,
            '/usr/lib/x86_64-linux-gnu/' . self::SHARED_LIB_FILENAME,
        ];
        foreach ($systemPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        throw new WasmtimeUnavailableException('Wasmtime library not found. Set GREENWOOD_WASMTIME_LIB environment variable.');
    }

    public static function buildFFIDeclarations(): string
    {
        return <<<DECLS
// Wasmtime C API surface for Greenwood 3A - exact signatures from 48.0.1 headers

// Core WebAssembly C API types
typedef struct wasm_config wasm_config_t;
typedef struct wasm_engine wasm_engine_t;
typedef struct wasm_store wasm_store_t;
typedef struct wasm_module wasm_module_t;
typedef struct wasm_instance wasm_instance_t;
typedef struct wasm_func wasm_func_t;
typedef struct wasm_trap wasm_trap_t;
typedef struct wasm_byte_vec {
    size_t size;
    uint8_t *data;
} wasm_byte_vec_t;
// wasm_name is a typedef to wasm_byte_vec
typedef wasm_byte_vec_t wasm_name_t;
typedef struct wasm_val wasm_val_t;
typedef struct wasm_valtype wasm_valtype_t;
typedef struct wasm_functype wasm_functype_t;
typedef struct wasm_extern wasm_extern_t;
typedef struct wasm_externtype wasm_externtype_t;
typedef struct wasm_exporttype wasm_exporttype_t;
typedef struct wasm_importtype wasm_importtype_t;
typedef struct wasm_frame wasm_frame_t;

// Wasmtime-specific types
typedef struct wasmtime_context wasmtime_context_t;
typedef struct wasmtime_store wasmtime_store_t;
typedef struct wasmtime_module wasmtime_module_t;
typedef struct wasmtime_instance {
    uint64_t store_id;
    size_t __private;
} wasmtime_instance_t;
typedef struct wasmtime_func {
  /// Internal identifier of what store this belongs to.
  ///
  /// This field may be zero when used in conjunction with #wasmtime_val_t
  /// to represent a null `funcref` value in WebAssembly. For a valid function
  /// this field is otherwise never zero.
  uint64_t store_id;
  /// Private field for Wasmtime, undefined if `store_id` is zero.
  void *__private;
} wasmtime_func_t;
typedef struct wasmtime_error wasmtime_error_t;
typedef struct wasmtime_trap wasmtime_trap_t;


typedef struct wasmtime_caller wasmtime_caller_t;
typedef struct wasmtime_global {
  /// Internal identifier of what store this belongs to, never zero.
  uint64_t store_id;
  /// Private field for Wasmtime.
  uint32_t __private1;
  /// Private field for Wasmtime.
  uint32_t __private2;
  /// Private field for Wasmtime.
  uint32_t __private3;
} wasmtime_global_t;
typedef struct wasmtime_table {
  struct {
    /// Internal identifier of what store this belongs to, never zero.
    uint64_t store_id;
    /// Private field for Wasmtime.
    uint32_t __private1;
  };
  /// Private field for Wasmtime.
  uint32_t __private2;
} wasmtime_table_t;
typedef struct wasmtime_memory {
  struct {
    /// Internal identifier of what store this belongs to, never zero.
    uint64_t store_id;
    /// Private field for Wasmtime.
    uint32_t __private1;
  };
  /// Private field for Wasmtime.
  uint32_t __private2;
} wasmtime_memory_t;
typedef struct wasmtime_sharedmemory wasmtime_sharedmemory_t;
typedef struct wasmtime_tag {
  struct {
    /// Internal identifier of what store this belongs to, never zero.
    uint64_t store_id;
    /// Private field for Wasmtime.
    uint32_t __private1;
  };
  /// Private field for Wasmtime.
  uint32_t __private2;
} wasmtime_tag_t;

// Wasmtime extern kinds
typedef uint8_t wasmtime_extern_kind_t;
#define WASMTIME_EXTERN_FUNC 0
#define WASMTIME_EXTERN_GLOBAL 1
#define WASMTIME_EXTERN_TABLE 2
#define WASMTIME_EXTERN_MEMORY 3
#define WASMTIME_EXTERN_SHAREDMEMORY 4
#define WASMTIME_EXTERN_TAG 5

typedef union wasmtime_extern_union {
  /// Field used if #wasmtime_extern_t::kind is #WASMTIME_EXTERN_FUNC
  wasmtime_func_t func;
  /// Field used if #wasmtime_extern_t::kind is #WASMTIME_EXTERN_GLOBAL
  wasmtime_global_t global;
  /// Field used if #wasmtime_extern_t::kind is #WASMTIME_EXTERN_TABLE
  wasmtime_table_t table;
  /// Field used if #wasmtime_extern_t::kind is #WASMTIME_EXTERN_MEMORY
  wasmtime_memory_t memory;
  /// Field used if #wasmtime_extern_t::kind is #WASMTIME_EXTERN_SHAREDMEMORY
  struct wasmtime_sharedmemory *sharedmemory;
  /// Field used if #wasmtime_extern_t::kind is #WASMTIME_EXTERN_TAG
  wasmtime_tag_t tag;
} wasmtime_extern_union_t;
typedef struct wasmtime_extern {
  /// Discriminant of which field of #of is valid.
  wasmtime_extern_kind_t kind;
  /// Container for the extern item's value.
  wasmtime_extern_union_t of;
} wasmtime_extern_t;

// Wasmtime val kinds  
typedef uint8_t wasmtime_valkind_t;
#define WASMTIME_I32 0
#define WASMTIME_I64 1
#define WASMTIME_F32 2
#define WASMTIME_F64 3
#define WASMTIME_V128 4
#define WASMTIME_FUNCREF 5
#define WASMTIME_EXTERNREF 6

typedef float float32_t;
typedef double float64_t;
typedef uint8_t wasmtime_v128[16];
typedef struct wasmtime_anyref {
  /// Internal metadata tracking within the store, embedders should not
  /// configure or modify these fields.
  uint64_t store_id;
  /// Internal to Wasmtime.
  uint32_t __private1;
  /// Internal to Wasmtime.
  uint32_t __private2;
  /// Internal to Wasmtime.
  void *__private3;
} wasmtime_anyref_t;
typedef struct wasmtime_exnref {
  /// Internal metadata tracking within the store, embedders should not
  /// configure or modify these fields.
  uint64_t store_id;
  /// Internal to Wasmtime.
  uint32_t __private1;
  /// Internal to Wasmtime.
  uint32_t __private2;
  /// Internal to Wasmtime.
  void *__private3;
} wasmtime_exnref_t;
typedef struct wasmtime_externref {
  /// Internal metadata tracking within the store, embedders should not
  /// configure or modify these fields.
  uint64_t store_id;
  /// Internal to Wasmtime.
  uint32_t __private1;
  /// Internal to Wasmtime.
  uint32_t __private2;
  /// Internal to Wasmtime.
  void *__private3;
} wasmtime_externref_t;
typedef union wasmtime_valunion {
  /// Field used if #wasmtime_val_t::kind is #WASMTIME_I32
  int32_t i32;
  /// Field used if #wasmtime_val_t::kind is #WASMTIME_I64
  int64_t i64;
  /// Field used if #wasmtime_val_t::kind is #WASMTIME_F32
  float32_t f32;
  /// Field used if #wasmtime_val_t::kind is #WASMTIME_F64
  float64_t f64;
  /// Field used if #wasmtime_val_t::kind is #WASMTIME_ANYREF
  wasmtime_anyref_t anyref;
  /// Field used if #wasmtime_val_t::kind is #WASMTIME_EXTERNREF
  wasmtime_externref_t externref;
  /// Field used if #wasmtime_val_t::kind is #WASMTIME_EXNREF
  wasmtime_exnref_t exnref;
  /// Field used if #wasmtime_val_t::kind is #WASMTIME_FUNCREF
  ///
  /// Use `wasmtime_funcref_is_null` to test whether this is a null function
  /// reference.
  wasmtime_func_t funcref;
  /// Field used if #wasmtime_val_t::kind is #WASMTIME_V128
  wasmtime_v128 v128;
} wasmtime_valunion_t;
typedef struct wasmtime_val {
  /// Discriminant of which field of #of is valid.
  wasmtime_valkind_t kind;
  /// Container for the extern item's value.
  wasmtime_valunion_t of;
} wasmtime_val_t;

// Wasmtime trap codes
typedef uint8_t wasmtime_trap_code_t;
enum wasmtime_trap_code_enum {
  /// The current stack space was exhausted.
  WASMTIME_TRAP_CODE_STACK_OVERFLOW = 0,
  /// An out-of-bounds memory access.
  WASMTIME_TRAP_CODE_MEMORY_OUT_OF_BOUNDS = 1,
  /// A wasm atomic operation was presented with a not-naturally-aligned
  /// linear-memory address.
  WASMTIME_TRAP_CODE_HEAP_MISALIGNED = 2,
  /// An out-of-bounds access to a table.
  WASMTIME_TRAP_CODE_TABLE_OUT_OF_BOUNDS = 3,
  /// Indirect call to a null table entry.
  WASMTIME_TRAP_CODE_INDIRECT_CALL_TO_NULL = 4,
  /// Signature mismatch on indirect call.
  WASMTIME_TRAP_CODE_BAD_SIGNATURE = 5,
  /// An integer arithmetic operation caused an overflow.
  WASMTIME_TRAP_CODE_INTEGER_OVERFLOW = 6,
  /// An integer division by zero.
  WASMTIME_TRAP_CODE_INTEGER_DIVISION_BY_ZERO = 7,
  /// Failed float-to-int conversion.
  WASMTIME_TRAP_CODE_BAD_CONVERSION_TO_INTEGER = 8,
  /// Code that was supposed to have been unreachable was reached.
  WASMTIME_TRAP_CODE_UNREACHABLE_CODE_REACHED = 9,
  /// Execution has potentially run too long and may be interrupted.
  WASMTIME_TRAP_CODE_INTERRUPT = 10,
  /// Execution has run out of the configured fuel amount.
  WASMTIME_TRAP_CODE_OUT_OF_FUEL = 11,
  /// Used to indicate that a trap was raised by atomic wait operations on non
  /// shared memory.
  WASMTIME_TRAP_CODE_ATOMIC_WAIT_NON_SHARED_MEMORY = 12,
  /// Call to a null reference.
  WASMTIME_TRAP_CODE_NULL_REFERENCE = 13,
  /// Attempt to access beyond the bounds of an array.
  WASMTIME_TRAP_CODE_ARRAY_OUT_OF_BOUNDS = 14,
  /// Attempted an allocation that was too large to succeed.
  WASMTIME_TRAP_CODE_ALLOCATION_TOO_LARGE = 15,
  /// Attempted to cast a reference to a type that it is not an instance of.
  WASMTIME_TRAP_CODE_CAST_FAILURE = 16,
  /// When the `component-model` feature is enabled this trap represents a
  /// scenario where one component tried to call another component but it
  /// would have violated the reentrance rules of the component model,
  /// triggering a trap instead.
  WASMTIME_TRAP_CODE_CANNOT_ENTER_COMPONENT = 17,
  /// Async-lifted export failed to produce a result by calling `task.return`
  /// before returning `STATUS_DONE` and/or after all host tasks completed.
  WASMTIME_TRAP_CODE_NO_ASYNC_RESULT = 18,
  /// We are suspending to a tag for which there is no active handler.
  WASMTIME_TRAP_CODE_UNHANDLED_TAG = 19,
  /// Attempt to resume a continuation twice.
  WASMTIME_TRAP_CODE_CONTINUATION_ALREADY_CONSUMED = 20,
  /// A Pulley opcode was executed at runtime when the opcode was disabled at
  /// compile time.
  WASMTIME_TRAP_CODE_DISABLED_OPCODE = 21,
  /// Async event loop deadlocked; i.e. it cannot make further progress given
  /// that all host tasks have completed and any/all host-owned stream/future
  /// handles have been dropped.
  WASMTIME_TRAP_CODE_ASYNC_DEADLOCK = 22,
  /// When the `component-model` feature is enabled this trap represents a
  /// scenario where a component instance tried to call an import or intrinsic
  /// when it wasn't allowed to, e.g. from a post-return function.
  WASMTIME_TRAP_CODE_CANNOT_LEAVE_COMPONENT = 23,
  /// A synchronous task attempted to make a potentially blocking call prior
  /// to returning.
  WASMTIME_TRAP_CODE_CANNOT_BLOCK_SYNC_TASK = 24,
  /// A component tried to lift a `char` with an invalid bit pattern.
  WASMTIME_TRAP_CODE_INVALID_CHAR = 25,
  /// Debug assertion generated for a fused adapter regarding the expected
  /// completion of a string encoding operation.
  WASMTIME_TRAP_CODE_DEBUG_ASSERT_STRING_ENCODING_FINISHED = 26,
  /// Debug assertion generated for a fused adapter regarding a string
  /// encoding operation.
  WASMTIME_TRAP_CODE_DEBUG_ASSERT_EQUAL_CODE_UNITS = 27,
  /// Debug assertion generated for a fused adapter regarding the alignment of
  /// a pointer.
  WASMTIME_TRAP_CODE_DEBUG_ASSERT_POINTER_ALIGNED = 28,
  /// Debug assertion generated for a fused adapter regarding the upper bits
  /// of a 64-bit value.
  WASMTIME_TRAP_CODE_DEBUG_ASSERT_UPPER_BITS_UNSET = 29,
  /// A component tried to lift or lower a string past the end of its memory.
  WASMTIME_TRAP_CODE_STRING_OUT_OF_BOUNDS = 30,
  /// A component tried to lift or lower a list past the end of its memory.
  WASMTIME_TRAP_CODE_LIST_OUT_OF_BOUNDS = 31,
  /// A component used an invalid discriminant when lowering a variant value.
  WASMTIME_TRAP_CODE_INVALID_DISCRIMINANT = 32,
  /// A component passed an unaligned pointer when lifting or lowering a
  /// value.
  WASMTIME_TRAP_CODE_UNALIGNED_POINTER = 33,
  /// `task.cancel` invoked in an invalid way.
  WASMTIME_TRAP_CODE_TASK_CANCEL_NOT_CANCELLED = 34,
  /// `task.cancel` or `task.return` called too many times
  WASMTIME_TRAP_CODE_TASK_CANCEL_OR_RETURN_TWICE = 35,
  /// `subtask.cancel` invoked after it already finished.
  WASMTIME_TRAP_CODE_SUBTASK_CANCEL_AFTER_TERMINAL = 36,
  /// `task.return` invoked with an invalid type.
  WASMTIME_TRAP_CODE_TASK_RETURN_INVALID = 37,
  /// `waitable-set.drop` invoked on a waitable set with waiters.
  WASMTIME_TRAP_CODE_WAITABLE_SET_DROP_HAS_WAITERS = 38,
  /// `subtask.drop` invoked on a subtask that hasn't resolved yet.
  WASMTIME_TRAP_CODE_SUBTASK_DROP_NOT_RESOLVED = 39,
  /// `thread.new-indirect` invoked with a function that has an invalid type.
  WASMTIME_TRAP_CODE_THREAD_NEW_INDIRECT_INVALID_TYPE = 40,
  /// `thread.new-indirect` invoked with an uninitialized function reference.
  WASMTIME_TRAP_CODE_THREAD_NEW_INDIRECT_UNINITIALIZED = 41,
  /// Backpressure-related intrinsics overflowed the built-in counter.
  WASMTIME_TRAP_CODE_BACKPRESSURE_OVERFLOW = 42,
  /// Invalid code returned from `callback` of `async`-lifted function.
  WASMTIME_TRAP_CODE_UNSUPPORTED_CALLBACK_CODE = 43,
  /// Cannot resume a thread which is not suspended.
  WASMTIME_TRAP_CODE_CANNOT_RESUME_THREAD = 44,
  /// Cannot issue a read/write on a future/stream while there is a
  /// pending operation already.
  WASMTIME_TRAP_CODE_CONCURRENT_FUTURE_STREAM_OP = 45,
  /// A reference count (for e.g. an `error-context`) overflowed.
  WASMTIME_TRAP_CODE_REFERENCE_COUNT_OVERFLOW = 46,
  /// A read/write on a stream must be <2**28 items.
  WASMTIME_TRAP_CODE_STREAM_OP_TOO_BIG = 47,
  /// The guest either attempted to add a waitable to a waitable set while it
  /// was being used in a synchronous operation or tried to use it in a
  /// synchronous operation while it was added to a waitable set.
  WASMTIME_TRAP_CODE_WAITABLE_SYNC_AND_ASYNC = 48,
  /// An exception propagated out of a component without being caught.
  WASMTIME_TRAP_CODE_UNCAUGHT_EXCEPTION = 49,
};

// WebAssembly C API - Config functions
void wasm_config_delete(wasm_config_t *);
wasm_config_t *wasm_config_new(void);
void wasmtime_config_consume_fuel_set(wasm_config_t *, bool);
void wasmtime_config_memory_init_cow_set(wasm_config_t *, bool);
void wasmtime_config_epoch_interruption_set(wasm_config_t *, bool);
void wasmtime_config_debug_info_set(wasm_config_t *, bool);
void wasmtime_config_wasm_simd_set(wasm_config_t *, bool);
void wasmtime_config_wasm_relaxed_simd_set(wasm_config_t *, bool);
void wasmtime_config_wasm_bulk_memory_set(wasm_config_t *, bool);
void wasmtime_config_wasm_multi_memory_set(wasm_config_t *, bool);
void wasmtime_config_wasm_memory64_set(wasm_config_t *, bool);
void wasmtime_config_max_wasm_stack_set(wasm_config_t *, size_t);

// WebAssembly C API - Engine functions
void wasm_engine_delete(wasm_engine_t *);
wasm_engine_t *wasm_engine_new(void);
wasm_engine_t *wasm_engine_new_with_config(wasm_config_t *);

// WebAssembly C API - Byte vector functions
void wasm_byte_vec_delete(wasm_byte_vec_t *);

void wasm_byte_vec_new_uninitialized(wasm_byte_vec_t *, size_t);
void wasm_byte_vec_new_empty(wasm_byte_vec_t *);

// WebAssembly C API - Trap functions  
void wasm_trap_delete(wasm_trap_t *);
void wasm_trap_message(const wasm_trap_t *, wasm_name_t *);


// WebAssembly C API - Module functions
void wasm_module_delete(wasm_module_t *);


// WebAssembly C API - Store functions


// WebAssembly C API - Instance functions
void wasm_instance_delete(wasm_instance_t *);

// WebAssembly C API - Func functions
void wasm_func_delete(wasm_func_t *);

// WebAssembly C API - Extern functions
void wasm_extern_delete(wasm_extern_t *);

// Wasmtime - Context functions
wasmtime_context_t *wasmtime_store_context(wasmtime_store_t *);
wasmtime_error_t *wasmtime_context_set_fuel(wasmtime_context_t *, uint64_t);
wasmtime_error_t *wasmtime_context_get_fuel(const wasmtime_context_t *, uint64_t *);

// Wasmtime - Error functions
void wasmtime_error_delete(wasmtime_error_t *);
void wasmtime_error_message(const wasmtime_error_t *, wasm_name_t *);

// Wasmtime - Module functions
wasmtime_error_t *wasmtime_module_new(wasm_engine_t *, const uint8_t *, size_t, wasmtime_module_t **);
void wasmtime_module_delete(wasmtime_module_t *);
wasmtime_error_t *wasmtime_module_validate(wasm_engine_t *, const uint8_t *, size_t);

// Wasmtime - Instance functions
wasmtime_error_t *wasmtime_instance_new(wasmtime_context_t *, const wasmtime_module_t *, const wasmtime_extern_t *, size_t, wasmtime_instance_t *, wasm_trap_t **);
bool wasmtime_instance_export_get(wasmtime_context_t *, const wasmtime_instance_t *, const char *, size_t, wasmtime_extern_t *);

// Wasmtime - Func functions
wasmtime_error_t *wasmtime_func_call(wasmtime_context_t *, const wasmtime_func_t *, const wasmtime_val_t *, size_t, wasmtime_val_t *, size_t, wasm_trap_t **);
void wasmtime_func_from_raw(wasmtime_context_t *, void *, wasmtime_func_t *);

// Wasmtime - Store functions
wasmtime_store_t *wasmtime_store_new(wasm_engine_t *, void *, void (*)(void *));
void wasmtime_store_delete(wasmtime_store_t *);

// Wasmtime - Extern functions
void wasmtime_extern_delete(wasmtime_extern_t *);

void wasmtime_store_limiter(wasmtime_store_t *, int64_t, int64_t, int64_t, int64_t, int64_t);
bool wasmtime_trap_code(const wasm_trap_t *, wasmtime_trap_code_t *);
// Wasmtime - Trap functions
void wasm_trap_delete(wasm_trap_t *);


DECLS;
    }

    public static function checkAvailability(): void
    {
        self::checkFFI();
        self::findLibrary();
    }
}
