<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

/** Owns linker and PHP closures until after the store has been destroyed. */
final class WasmtimeHostBridge
{
    public readonly \FFI\CData $linker;
    public ?string $failure=null;
    private array $callbacks=[];
    public function __construct(private readonly \FFI $ffi,\FFI\CData $engine,private readonly ContractHostInterface $host)
    {
        $linker=$ffi->wasmtime_linker_new($engine);
        if ($linker===null) throw new HostCallException('Linker allocation failed');
        $this->linker=$linker;
    }
    public function register(): void
    {
        foreach (ContractAbi::FUNCTIONS as $name=>[$params,$result]) {
            $p=$this->vector($params); $r=$this->vector([$result]);
            // functype_new takes ownership of both vectors and their valtypes.
            $type=$this->ffi->wasm_functype_new(\FFI::addr($p),\FFI::addr($r));
            if ($type===null) throw new HostCallException('Function type allocation failed');
            $callback=function ($env,$caller,$args,$nargs,$results,$nresults) use ($name,$params,$result) {
                try {
                    if ($nargs!==count($params) || $nresults!==1) throw new HostCallException('Callback arity mismatch');
                    $arguments=[];
                    foreach ($params as $i=>$kind) {
                        if ($args[$i]->kind!==$kind) throw new HostCallException('Callback type mismatch');
                        $arguments[]=$kind===0?$args[$i]->of->i32:$args[$i]->of->i64;
                    }
                    $value=$this->host->invoke($name,$arguments,new GuestMemory($this->ffi,$caller));
                    $results[0]->kind=$result;
                    if ($result===0) $results[0]->of->i32=$value; else $results[0]->of->i64=$value;
                    return null;
                } catch (\Throwable $e) {
                    // Never unwind PHP exceptions across C. Diagnostic stays local.
                    $this->failure=get_class($e).': '.$e->getMessage();
                    return $this->ffi->wasmtime_trap_new('Greenwood host failure',22);
                }
            };
            $this->callbacks[]=$callback;
            try {
                $error=$this->ffi->wasmtime_linker_define_func($this->linker,'greenwood',9,$name,strlen($name),$type,$callback,null,null);
                if ($error!==null) {
                    $this->ffi->wasmtime_error_delete($error);
                    throw new HostCallException('Host registration failed: '.$name);
                }
            } finally { $this->ffi->wasm_functype_delete($type); }
        }
    }
    private function vector(array $kinds): \FFI\CData
    {
        $v=$this->ffi->new('wasm_valtype_vec_t');
        $this->ffi->wasm_valtype_vec_new_uninitialized(\FFI::addr($v),count($kinds));
        foreach ($kinds as $i=>$kind) $v->data[$i]=$this->ffi->wasm_valtype_new($kind);
        return $v;
    }
    public function close(): void
    {
        $this->ffi->wasmtime_linker_delete($this->linker);
        $this->callbacks=[];
    }
}
