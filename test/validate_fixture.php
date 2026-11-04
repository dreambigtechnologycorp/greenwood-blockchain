<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../src/Contracts/WasmtimeException.php';
use Greenwood\Contracts\WasmtimeBindings;
WasmtimeBindings::load();
$ffi=WasmtimeBindings::get();
$engine=$ffi->wasm_engine_new();
try {
    $wasm=file_get_contents($argv[1]??__DIR__.'/fixtures/valid.wasm');
    $bytes=$ffi->new('uint8_t['.strlen($wasm).']'); FFI::memcpy($bytes,$wasm,strlen($wasm));
    $error=$ffi->wasmtime_module_validate($engine,$bytes,strlen($wasm));
    if ($error!==null) {
        $message=$ffi->new('wasm_byte_vec_t');
        $ffi->wasmtime_error_message($error,FFI::addr($message));
        try { throw new RuntimeException(FFI::string($message->data,$message->size)); }
        finally { $ffi->wasm_byte_vec_delete(FFI::addr($message)); $ffi->wasmtime_error_delete($error); }
    }
    echo "PASS wasmtime_module_validate: ".bin2hex($wasm)."\n";
} finally { $ffi->wasm_engine_delete($engine); }
