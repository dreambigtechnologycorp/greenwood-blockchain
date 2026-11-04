<?php
declare(strict_types=1);
namespace Greenwood\Tests\Contracts\Support;
use Greenwood\Contracts\WasmtimeConfig;
/** Test-only real Wasmtime WAT compiler, independent of the execution bindings. */
final class WatCompiler
{
    private static ?\FFI $ffi=null;
    public static function compile(string $wat): string
    {
        $ffi=self::$ffi??=\FFI::cdef('typedef struct wasmtime_error wasmtime_error_t;
        typedef struct { size_t size; uint8_t *data; } wasm_byte_vec_t;
        wasmtime_error_t *wasmtime_wat2wasm(const char *, size_t, wasm_byte_vec_t *);
        void wasmtime_error_message(const wasmtime_error_t *, wasm_byte_vec_t *);
        void wasmtime_error_delete(wasmtime_error_t *);
        void wasm_byte_vec_delete(wasm_byte_vec_t *);',WasmtimeConfig::findLibrary());
        $out=$ffi->new('wasm_byte_vec_t');
        $error=$ffi->wasmtime_wat2wasm($wat,strlen($wat),\FFI::addr($out));
        if ($error!==null) {
            $msg=$ffi->new('wasm_byte_vec_t'); $ffi->wasmtime_error_message($error,\FFI::addr($msg));
            try { throw new \RuntimeException(\FFI::string($msg->data,$msg->size)); }
            finally { $ffi->wasm_byte_vec_delete(\FFI::addr($msg)); $ffi->wasmtime_error_delete($error); }
        }
        try { return \FFI::string($out->data,$out->size); }
        finally { $ffi->wasm_byte_vec_delete(\FFI::addr($out)); }
    }
}
