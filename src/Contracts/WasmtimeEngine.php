<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

/** Owns engine lifetime. Raw execution forbids imports; executeContract explicitly
 * enables the Greenwood host profile. Every call owns a fresh module and store. */
final class WasmtimeEngine implements WasmEngineInterface
{
    private \FFI $ffi;
    private ?\FFI\CData $engine = null;

    public function __construct(?string $libraryPath = null)
    {
        WasmtimeConfig::checkFFI();
        $this->ffi = WasmtimeHostBindings::load($libraryPath ?? WasmtimeConfig::findLibrary());
        $config = $this->ffi->wasm_config_new();
        if ($config === null) throw new WasmtimeUnavailableException('Config allocation failed');
        try {
            $this->ffi->wasmtime_config_consume_fuel_set($config, true);
            // Disable copy-on-write memory initialization. With CoW enabled
            // (default on Linux), memory/data-segment instantiation defers to
            // mmap and charges no fuel, while platforms without CoW charge
            // eager-init fuel — identical modules then report different fuel
            // on Linux vs macOS (measured: data fixture 3 vs 16388 under
            // Wasmtime 48.0.1). Eager init makes memory-instantiation fuel
            // accounting identical and deterministic on every platform.
            // This strictly ENLARGES accounted work; fuel metering is not
            // weakened.
            $this->ffi->wasmtime_config_memory_init_cow_set($config, false);
            $this->ffi->wasmtime_config_epoch_interruption_set($config, false);
            $this->ffi->wasmtime_config_debug_info_set($config, false);
            foreach (['simd','relaxed_simd','bulk_memory','multi_memory','memory64'] as $feature) {
                $this->ffi->{'wasmtime_config_wasm_'.$feature.'_set'}($config, false);
            }
            $this->ffi->wasmtime_config_max_wasm_stack_set($config, WasmtimeConfig::MAX_WASM_STACK);
            // wasm_engine_new_with_config consumes config; it must never be deleted again.
            $this->engine = $this->ffi->wasm_engine_new_with_config($config);
            $config = null;
            if ($this->engine === null) throw new WasmtimeUnavailableException('Engine allocation failed');
        } finally {
            if ($config !== null) $this->ffi->wasm_config_delete($config);
        }
    }

    private function bytes(string $wasm): \FFI\CData
    {
        if (strlen($wasm) > 1048576) throw new WasmValidationException('Module exceeds 1 MiB');
        $bytes = $this->ffi->new('uint8_t['.max(1,strlen($wasm)).']');
        if ($wasm !== '') \FFI::memcpy($bytes, $wasm, strlen($wasm));
        return $bytes;
    }

    public function validate(string $wasm): void
    {
        $this->validateProfile($wasm, false);
    }

    private function validateProfile(string $wasm, bool $hostImports): void
    {
        $error = $this->ffi->wasmtime_module_validate($this->engine, $this->bytes($wasm), strlen($wasm));
        if ($error !== null) throw new WasmValidationException($this->errorMessage($error));
        WasmPolicy::validate($wasm,$hostImports);
    }

    public function execute(string $wasm, string $entrypoint, int $fuelLimit): WasmExecutionResult
    {
        return $this->run($wasm,$entrypoint,$fuelLimit);
    }

    public function validateContract(string $wasm): void { $this->validateProfile($wasm,true); }

    public function executeDeployment(string $wasm,ContractExecutionContext $context,ContractStateViewInterface $state): ContractExecutionResult
    { return $this->executeWithHost($wasm,$context,$state,'greenwood_init',true); }

    public function executeContract(string $wasm, ContractExecutionContext $context, ContractStateViewInterface $state, string $entrypoint='greenwood_call'): ContractExecutionResult
    { return $this->executeWithHost($wasm,$context,$state,$entrypoint,false); }

    private function executeWithHost(string $wasm,ContractExecutionContext $context,ContractStateViewInterface $state,string $entrypoint,bool $optionalInit): ContractExecutionResult
    {
        $overlay=new ExecutionOverlay($context,$state);
        try {
            $overlay->attachValue();
            $runtime=$this->run($wasm,$entrypoint,$context->gasLimit,new ContractHost($context,$overlay),$optionalInit);
            if ($runtime->isSuccess()) $overlay->seal(); else $overlay->discard();
        } catch (\Throwable $e) {
            $overlay->discard();
            $runtime=WasmExecutionResult::error(get_class($e).': '.$e->getMessage(),0);
        }
        return new ContractExecutionResult($runtime,$overlay);
    }

    private function run(string $wasm,string $entrypoint,int $fuelLimit,?ContractHostInterface $host=null,bool $optionalInit=false): WasmExecutionResult
    {
        if ($fuelLimit < 0 || $fuelLimit > 1000000000) throw new \InvalidArgumentException('Fuel limit outside 0..1000000000');
        if ($entrypoint === '' || strlen($entrypoint)>128) throw new \InvalidArgumentException('Invalid entrypoint');
        $this->validateProfile($wasm,$host!==null);
        $module = $this->ffi->new('wasmtime_module_t *');
        $trap = $this->ffi->new('wasm_trap_t *');
        $store = null;
        $export = null;
        $found = false;
        $bridge = null;
        try {
            $error = $this->ffi->wasmtime_module_new($this->engine, $this->bytes($wasm), strlen($wasm), \FFI::addr($module));
            if ($error !== null) return WasmExecutionResult::error($this->errorMessage($error));
            $store = $this->ffi->wasmtime_store_new($this->engine, null, null);
            if ($store === null) throw new WasmExecutionException('Store allocation failed');
            $this->ffi->wasmtime_store_limiter($store, WasmtimeConfig::MAX_MEMORY, WasmtimeConfig::MAX_TABLE_ELEMENTS, WasmtimeConfig::MAX_INSTANCES, WasmtimeConfig::MAX_TABLES, 1);
            $context = $this->ffi->wasmtime_store_context($store);
            $error = $this->ffi->wasmtime_context_set_fuel($context, $fuelLimit);
            if ($error !== null) throw new WasmExecutionException($this->errorMessage($error));
            $instance = $this->ffi->new('wasmtime_instance_t');
            if ($host===null) {
                $error = $this->ffi->wasmtime_instance_new($context, $module, null, 0, \FFI::addr($instance), \FFI::addr($trap));
            } else {
                $bridge=new WasmtimeHostBridge($this->ffi,$this->engine,$host);
                $bridge->register();
                $error=$this->ffi->wasmtime_linker_instantiate($bridge->linker,$context,$module,\FFI::addr($instance),\FFI::addr($trap));
            }
            if ($error !== null) {
                $message=$this->errorMessage($error);
                return WasmExecutionResult::error($bridge?->failure ?? $message, $this->fuelUsed($context,$fuelLimit));
            }
            if ($bridge?->failure!==null) return WasmExecutionResult::error($bridge->failure,$this->fuelUsed($context,$fuelLimit));
            if (!\FFI::isNull($trap)) return $this->trapResult($trap,$context,$fuelLimit);
            $export = $this->ffi->new('wasmtime_extern_t');
            $found = $this->ffi->wasmtime_instance_export_get($context, \FFI::addr($instance), $entrypoint, strlen($entrypoint), \FFI::addr($export));
            if (!$found) {
                if ($optionalInit) return WasmExecutionResult::success(null,$this->fuelUsed($context,$fuelLimit));
                $missing=WasmExecutionResult::missingExport($entrypoint);
                // 3C receipts must include fuel consumed before export lookup (e.g. data initialization).
                // Keep the accepted raw 3A missing-export result unchanged.
                return $host===null?$missing:new WasmExecutionResult($missing->getStatus(),null,$this->fuelUsed($context,$fuelLimit),$missing->getErrorMessage());
            }
            if ($export->kind !== 0) return WasmExecutionResult::error('Export is not a function', $this->fuelUsed($context,$fuelLimit));
            $results = $this->ffi->new('wasmtime_val_t[1]');
            // 3A supports no arguments and one integer result; native call validates the signature.
            $error = $this->ffi->wasmtime_func_call($context, \FFI::addr($export->of->func), null, 0, $results, 1, \FFI::addr($trap));
            if ($error !== null) {
                $message=$this->errorMessage($error);
                return WasmExecutionResult::error($bridge?->failure ?? $message, $this->fuelUsed($context,$fuelLimit));
            }
            if ($bridge?->failure!==null) return WasmExecutionResult::error($bridge->failure,$this->fuelUsed($context,$fuelLimit));
            if (!\FFI::isNull($trap)) return $this->trapResult($trap,$context,$fuelLimit);
            $value = match ($results[0]->kind) {
                0 => $results[0]->of->i32,
                1 => $results[0]->of->i64,
                default => throw new WasmExecutionException('Noninteger result escaped module policy'),
            };
            return WasmExecutionResult::success($value,$this->fuelUsed($context,$fuelLimit));
        } finally {
            if (!\FFI::isNull($trap)) $this->ffi->wasm_trap_delete($trap);
            if ($found) $this->ffi->wasmtime_extern_delete(\FFI::addr($export));
            // Instance and function handles have no independent destructors.
            if ($store !== null) $this->ffi->wasmtime_store_delete($store);
            $bridge?->close();
            if (!\FFI::isNull($module)) $this->ffi->wasmtime_module_delete($module);
        }
    }

    private function fuelUsed(\FFI\CData $context,int $initial): int
    {
        $remaining = $this->ffi->new('uint64_t');
        $error = $this->ffi->wasmtime_context_get_fuel($context, \FFI::addr($remaining));
        if ($error !== null) throw new WasmExecutionException($this->errorMessage($error));
        return $initial - $remaining->cdata;
    }

    private function errorMessage(\FFI\CData $error): string
    {
        $message = $this->ffi->new('wasm_byte_vec_t');
        try {
            $this->ffi->wasmtime_error_message($error, \FFI::addr($message));
            return \FFI::string($message->data, $message->size);
        } finally {
            $this->ffi->wasm_byte_vec_delete(\FFI::addr($message));
            $this->ffi->wasmtime_error_delete($error);
        }
    }

    private function trapResult(\FFI\CData $trap,\FFI\CData $context,int $limit): WasmExecutionResult
    {
        $code = $this->ffi->new('wasmtime_trap_code_t');
        $fuel = $this->fuelUsed($context,$limit);
        if ($this->ffi->wasmtime_trap_code($trap, \FFI::addr($code)) && $code->cdata === $this->ffi->WASMTIME_TRAP_CODE_OUT_OF_FUEL) {
            return WasmExecutionResult::fuelExhausted($fuel);
        }
        $message = $this->ffi->new('wasm_byte_vec_t');
        try {
            $this->ffi->wasm_trap_message($trap, \FFI::addr($message));
            return WasmExecutionResult::trap(rtrim(\FFI::string($message->data,$message->size),"\0"),$fuel);
        } finally { $this->ffi->wasm_byte_vec_delete(\FFI::addr($message)); }
    }

    public function getVersion(): string { return WasmtimeConfig::WASMTIME_VERSION; }
    public function __destruct()
    {
        if ($this->engine !== null) $this->ffi->wasm_engine_delete($this->engine);
    }
}
