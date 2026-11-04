<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

/** Additional 3B C declarations from the bundled 48.0.1 headers.
 * wasm_valtype_vec_t and type ownership APIs expand WASM_DECLARE_VEC/OWN in wasm.h.
 */
final class WasmtimeHostBindings
{
    /** Retain CType scopes for the process lifetime. PHP 8.5 property access caches
     * can otherwise outlive a repeatedly created/destroyed FFI scope (native
     * regression: full 3A suite crashed in zend_ffi_cdata_read_field).
     * Engines/stores/modules/linkers still have their normal per-owner lifetimes.
     */
    private static array $scopes=[];
    public static function load(string $library): \FFI
    {
        return self::$scopes[$library]??=\FFI::cdef(WasmtimeConfig::buildFFIDeclarations().self::declarations(),$library);
    }
    public static function declarations(): string
    {
        return <<<'C'
typedef struct wasm_valtype_vec_t { size_t size; wasm_valtype_t **data; } wasm_valtype_vec_t;
typedef uint8_t wasm_valkind_t;
wasm_valtype_t *wasm_valtype_new(wasm_valkind_t);
void wasm_valtype_vec_new_uninitialized(wasm_valtype_vec_t *, size_t);
void wasm_valtype_vec_delete(wasm_valtype_vec_t *);
wasm_functype_t *wasm_functype_new(wasm_valtype_vec_t *, wasm_valtype_vec_t *);
void wasm_functype_delete(wasm_functype_t *);
typedef wasm_trap_t *(*wasmtime_func_callback_t)(
    void *env, wasmtime_caller_t *caller, const wasmtime_val_t *args,
    size_t nargs, wasmtime_val_t *results, size_t nresults);
typedef struct wasmtime_linker wasmtime_linker_t;
wasmtime_linker_t *wasmtime_linker_new(wasm_engine_t *engine);
void wasmtime_linker_delete(wasmtime_linker_t *linker);
wasmtime_error_t *wasmtime_linker_define_func(
    wasmtime_linker_t *linker, const char *module, size_t module_len,
    const char *name, size_t name_len, const wasm_functype_t *ty,
    wasmtime_func_callback_t cb, void *data, void (*finalizer)(void *));
wasmtime_error_t *wasmtime_linker_instantiate(
    const wasmtime_linker_t *linker, wasmtime_context_t *store,
    const wasmtime_module_t *module, wasmtime_instance_t *instance, wasm_trap_t **trap);
bool wasmtime_caller_export_get(wasmtime_caller_t *caller, const char *name,
    size_t name_len, wasmtime_extern_t *item);
wasmtime_context_t *wasmtime_caller_context(wasmtime_caller_t *caller);
uint8_t *wasmtime_memory_data(wasmtime_context_t *store,const wasmtime_memory_t *memory);
size_t wasmtime_memory_data_size(const wasmtime_context_t *store,const wasmtime_memory_t *memory);
wasm_trap_t *wasmtime_trap_new(const char *msg,size_t msg_len);
C;
    }
}
