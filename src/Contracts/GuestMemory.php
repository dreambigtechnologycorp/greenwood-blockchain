<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

/** Wasmtime-facing, callback-scoped access. Resolve the memory afresh for every
 * operation, so no data pointer survives guest memory.grow or a callback. */
final class GuestMemory implements GuestMemoryInterface
{
    public function __construct(private readonly \FFI $ffi,private readonly \FFI\CData $caller) {}
    private function access(int $pointer,int $length,\Closure $operation): mixed
    {
        if ($pointer<0 || $length<0) throw new HostCallException('Negative guest memory range');
        $export=$this->ffi->new('wasmtime_extern_t');
        if (!$this->ffi->wasmtime_caller_export_get($this->caller,'memory',6,\FFI::addr($export))) throw new HostCallException('Guest must export memory');
        try {
            if ($export->kind!==3) throw new HostCallException('memory export is not linear memory');
            $context=$this->ffi->wasmtime_caller_context($this->caller);
            $memory=\FFI::addr($export->of->memory);
            $size=$this->ffi->wasmtime_memory_data_size($context,$memory);
            // Subtract after checking pointer. Never compute an untrusted ptr+len.
            if ($pointer>$size || $length>$size-$pointer) throw new HostCallException('Guest memory out of bounds');
            if ($length===0) return $operation(null);
            $base=$this->ffi->wasmtime_memory_data($context,$memory);
            if ($base===null) throw new HostCallException('Missing guest memory data');
            return $operation(\FFI::addr($base[$pointer]));
        } finally { $this->ffi->wasmtime_extern_delete(\FFI::addr($export)); }
    }
    public function checkRange(int $pointer,int $length): void
    { $this->access($pointer,$length,static fn($unused)=>null); }
    public function readBytes(int $pointer,int $length): string
    { return $this->access($pointer,$length,static fn($data): string=>$length===0?'':\FFI::string($data,$length)); }
    public function writeBytes(int $pointer,string $bytes): void
    { $this->access($pointer,strlen($bytes),static function ($data) use ($bytes): void { if ($bytes!=='') \FFI::memcpy($data,$bytes,strlen($bytes)); }); }
}
