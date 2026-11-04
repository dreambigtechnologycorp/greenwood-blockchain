<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../src/Contracts/WasmtimeException.php';
use Greenwood\Contracts\WasmtimeBindings;
WasmtimeBindings::load(); $ffi=WasmtimeBindings::get();
$engine=$ffi->wasm_engine_new(); $module=$ffi->new('wasmtime_module_t *'); $store=null;
try {
    $wasm=file_get_contents(__DIR__.'/fixtures/valid.wasm');
    $bytes=$ffi->new('uint8_t['.strlen($wasm).']'); FFI::memcpy($bytes,$wasm,strlen($wasm));
    $error=$ffi->wasmtime_module_validate($engine,$bytes,strlen($wasm));
    if ($error!==null) throw new RuntimeException('Validation failed');
    echo "validation: PASS\n";
    $error=$ffi->wasmtime_module_new($engine,$bytes,strlen($wasm),FFI::addr($module));
    if ($error!==null) throw new RuntimeException('Module creation returned an error');
    echo "module creation: PASS\n";
    if (($argv[1]??'compile')!=='compile') {
    $store=$ffi->wasmtime_store_new($engine,null,null);
    $context=$ffi->wasmtime_store_context($store);
    echo "store creation: PASS\n";
    $instance=$ffi->new('wasmtime_instance_t');
    echo "instance allocation: PASS\n";
    $trap=$ffi->new('wasm_trap_t *');
    $error=$ffi->wasmtime_instance_new($context,$module,null,0,FFI::addr($instance),FFI::addr($trap));
    if ($error!==null || !FFI::isNull($trap)) throw new RuntimeException('Instance creation returned error/trap');
    echo "instance_new: PASS\nerror: null\ntrap: null\n";
    if (in_array($argv[1]??'',['export','call'],true)) {
        $export=$ffi->new('wasmtime_extern_t');
        $found=$ffi->wasmtime_instance_export_get($context,FFI::addr($instance),'greenwood_call',14,FFI::addr($export));
        if (!$found) throw new RuntimeException('Missing greenwood_call');
        try {
            if ($export->kind!==0) throw new RuntimeException('Export is not a function');
            $func=$ffi->new('wasmtime_func_t'); $func=$export->of->func;
            echo "export lookup: PASS\nexport kind: function\nfunction extraction: PASS\n";
            if (($argv[1]??'')==='call') {
                $results=$ffi->new('wasmtime_val_t[1]');
                $error=$ffi->wasmtime_func_call($context,FFI::addr($export->of->func),null,0,$results,1,FFI::addr($trap));
                if ($error!==null || !FFI::isNull($trap)) throw new RuntimeException('Function call failed');
                if ($results[0]->kind!==0 || $results[0]->of->i32!==42) throw new RuntimeException('Expected i32 42');
                echo "function call: PASS\nresult: 42\n";
            }
        } finally { $ffi->wasmtime_extern_delete(FFI::addr($export)); }
    }
    }
} finally {
    if ($store!==null) $ffi->wasmtime_store_delete($store);
    if (!FFI::isNull($module)) $ffi->wasmtime_module_delete($module);
    $ffi->wasm_engine_delete($engine);
}
