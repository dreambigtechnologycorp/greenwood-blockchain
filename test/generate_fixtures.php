<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
// Development-only WAT compiler from the installed Wasmtime library.
// Does not alter the production engine's FFI declarations or configuration.
$ffi=FFI::cdef('typedef struct wasmtime_error wasmtime_error_t;
typedef struct { size_t size; uint8_t *data; } wasm_byte_vec_t;
wasmtime_error_t *wasmtime_wat2wasm(const char *, size_t, wasm_byte_vec_t *);
void wasmtime_error_message(const wasmtime_error_t *, wasm_byte_vec_t *);
void wasmtime_error_delete(wasmtime_error_t *);
void wasm_byte_vec_delete(wasm_byte_vec_t *);',Greenwood\Contracts\WasmtimeConfig::findLibrary());
foreach (glob(__DIR__.'/fixtures/*.wat') as $path) {
    $wat=file_get_contents($path); $out=$ffi->new('wasm_byte_vec_t');
    $error=$ffi->wasmtime_wat2wasm($wat,strlen($wat),FFI::addr($out));
    if ($error!==null) {
        $message=$ffi->new('wasm_byte_vec_t'); $ffi->wasmtime_error_message($error,FFI::addr($message));
        try { throw new RuntimeException(FFI::string($message->data,$message->size)); }
        finally { $ffi->wasm_byte_vec_delete(FFI::addr($message)); $ffi->wasmtime_error_delete($error); }
    }
    try { file_put_contents(substr($path,0,-4).'.wasm',FFI::string($out->data,$out->size)); }
    finally { $ffi->wasm_byte_vec_delete(FFI::addr($out)); }
    echo 'Generated '.basename($path)."\n";
}
